#!/usr/bin/env python3
"""Exercise the callback HTTP contract without exposing runtime secrets."""
import os
import pathlib
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import json

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory() as tmp:
    storage = pathlib.Path(tmp)
    (storage / "runtime").mkdir()
    (storage / "runtime" / "vk-callback-confirmation.txt").write_text("file-token\\n")
    env = dict(os.environ, SAPI_STORAGE_ROOT=tmp, VK_COMMUNITY_ID="220996873",
               VK_CALLBACK_CONFIRMATION="env-token", VK_CALLBACK_SECRET="test-secret")
    proc = subprocess.Popen(["php", "-S", "127.0.0.1:18991", "-t", str(ROOT / "public")],
                            env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    def post(payload):
        req = urllib.request.Request("http://127.0.0.1:18991/callback.php",
            data=json.dumps(payload).encode(), headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=3) as res:
                return res.status, res.read().decode()
        except urllib.error.HTTPError as exc:
            return exc.code, exc.read().decode()
    try:
        for _ in range(30):
            try:
                assert post({"type": "confirmation", "group_id": 220996873}) == (200, "file-token")
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise AssertionError("PHP server did not start")
        assert post({"type": "confirmation", "group_id": 999}) == (403, "forbidden")
        (storage / "runtime" / "vk-callback-confirmation.txt").unlink()
        assert post({"type": "confirmation", "group_id": 220996873}) == (200, "env-token")
        print("callback confirmation HTTP tests: PASS")
    finally:
        proc.terminate()
        proc.wait(timeout=5)
