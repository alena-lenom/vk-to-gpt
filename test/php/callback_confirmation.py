#!/usr/bin/env python3
"""Check the public VK Callback API contract with an isolated fake config.php."""
import json
import os
import pathlib
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory() as tmp:
    config_file = pathlib.Path(tmp) / "config.php"
    config_file.write_text(
        "<?php return ["
        "'VK_CALLBACK_CONFIRMATION'=>'file-token',"
        "'VK_CALLBACK_SECRET'=>'test-secret',"
        "'VK_COMMUNITY_ID'=>'220996873',"
        "'VK_SAPI_EVENTS_DIR'=>'/nonexistent-callback-test-events'"
        "];"
    )
    env = dict(
        os.environ,
        VK_TO_GPT_CALLBACK_TEST_CONFIG=str(config_file),
        VK_CALLBACK_CONFIRMATION="wrong-env-token",
        VK_COMMUNITY_ID="999",
    )
    proc = subprocess.Popen(
        ["php", "-S", "127.0.0.1:18991", "-t", str(ROOT / "public")],
        env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )

    def post(payload):
        req = urllib.request.Request(
            "http://127.0.0.1:18991/callback.php",
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
        )
        try:
            with urllib.request.urlopen(req, timeout=3) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as exc:
            return exc.code, exc.read().decode()

    try:
        for attempt in range(30):
            try:
                assert post({"type": "confirmation", "group_id": 220996873}) == (200, "file-token")
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise AssertionError("PHP server did not start")
        assert post({"type": "confirmation", "group_id": 999}) == (403, "forbidden")
        assert post({"type": "wall_reply_new", "group_id": 220996873}) == (403, "forbidden")
        config_file.unlink()
        assert post({"type": "confirmation", "group_id": 220996873}) == (503, "unavailable")
        print("callback protected-config HTTP tests: PASS")
    finally:
        proc.terminate()
        proc.wait(timeout=5)
