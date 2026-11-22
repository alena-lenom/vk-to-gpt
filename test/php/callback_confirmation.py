#!/usr/bin/env python3
"""Verify the VK Callback API against isolated fake configuration and event storage."""
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
    events = pathlib.Path(tmp) / "events"
    events.mkdir(mode=0o700)
    config_file.write_text(
        "<?php return ["
        "'VK_CALLBACK_CONFIRMATION'=>'file-token',"
        "'VK_CALLBACK_SECRET'=>'test-secret',"
        "'VK_COMMUNITY_ID'=>'220996873',"
        f"'VK_SAPI_EVENTS_DIR'=>'{events.as_posix()}'"
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
        fake = {
            "type": "wall_reply_new",
            "group_id": 220996873,
            "event_id": "synthetic-vk-event-1",
            "secret": "test-secret",
            "object": {
                "id": 901,
                "owner_id": -220996873,
                "post_id": 23,
                "from_id": 42,
                "date": 1790000000,
                "text": "Synthetic test comment",
            },
        }
        assert post(dict(fake, secret="wrong")) == (403, "forbidden")
        assert not list(events.iterdir())
        assert post(fake) == (200, "ok")
        assert post(fake) == (200, "ok")
        files = list(events.glob("event-*.json"))
        assert len(files) == 1, "Duplicate callback generated extra files"
        record = json.loads(files[0].read_text())
        assert record["source"] == {"type": "community", "group_id": 220996873}
        assert record["interaction"]["post_owner_id"] == -220996873
        assert record["interaction"]["comment_id"] == 901
        assert record["interaction"]["post_id"] == 23
        # A repeated event_id may have altered data; the first receipt must win.
        replay = dict(fake, object=dict(fake["object"], text="Changed replay payload"))
        assert post(replay) == (200, "ok")
        assert len(list(events.glob("event-*.json"))) == 1
        assert json.loads(files[0].read_text()) == record, "Replay overwrote original event"

        php_code = (
            f"require {json.dumps(str(ROOT / 'php' / 'SapiEventReader.php'))};"
            "$out=\\VkToGpt\\SapiEventReader::read(getenv('TEST_EVENTS_DIR'),"
            "['group_id'=>220996873,'limit'=>5,'since'=>0]);"
            "if (($out['status']??'')!=='AVAILABLE' ||"
            "($out['observed_matching_events']??0)!==1 ||"
            "($out['items'][0]['comment_id']??0)!==901) exit(2);"
        )
        read = subprocess.run(
            ["php", "-r", php_code],
            env=dict(os.environ, TEST_EVENTS_DIR=str(events)),
            capture_output=True, text=True, check=False,
        )
        assert read.returncode == 0, read.stderr
        assert post(dict(fake, event_id="synthetic-invalid", object=dict(fake["object"], owner_id=-999))) == (200, "ok")
        assert len(list(events.glob("event-*.json"))) == 1
        for file in events.iterdir():
            file.unlink()
        events.rmdir()
        assert post(dict(fake, event_id="synthetic-no-storage")) == (503, "unavailable")
        config_file.unlink()
        assert post({"type": "confirmation", "group_id": 220996873}) == (503, "unavailable")
        print("callback confirmation, event persistence and MCP reader tests: PASS")
    finally:
        proc.terminate()
        proc.wait(timeout=5)
