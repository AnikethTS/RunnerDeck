#!/usr/bin/env python3
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PORT = 8195
BASE = f"http://127.0.0.1:{PORT}"


def get(path: str, timeout: float = 3.0):
    try:
        with urllib.request.urlopen(BASE + path, timeout=timeout) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except (urllib.error.URLError, OSError):
        return None, ""


def wait_for(check, seconds: float, interval: float = 0.5) -> bool:
    deadline = time.time() + seconds
    while time.time() < deadline:
        if check():
            return True
        time.sleep(interval)
    return False


def fail(message: str) -> None:
    print(f"FAIL: {message}")
    sys.exit(1)


def find_zip(target: str) -> Path:
    path = Path(target)
    if path.is_dir():
        zips = sorted(path.glob("runnerdeck-*-windows-x64.zip"))
        if not zips:
            fail(f"no runnerdeck-*-windows-x64.zip in {path}")
        return zips[-1]
    return path


def main() -> None:
    archive = find_zip(sys.argv[1] if len(sys.argv) > 1 else "dist")
    version = (ROOT / "VERSION").read_text().strip()
    work = Path(tempfile.mkdtemp(prefix="runnerdeck-smoke-"))
    with zipfile.ZipFile(archive) as zf:
        zf.extractall(work / "pkg")
    exe = next((work / "pkg").glob("*/runnerdeck.exe"), None)
    if exe is None:
        fail("runnerdeck.exe is missing from the zip")

    shown = subprocess.run([str(exe), "--version"], capture_output=True, text=True, timeout=30)
    if shown.stdout.strip() != f"RunnerDeck {version}":
        fail(f"--version printed {shown.stdout!r}, expected 'RunnerDeck {version}'")
    print(f"ok  --version: {shown.stdout.strip()}")

    data_dir = work / "data"
    env = dict(os.environ, RUNNERDECK_DATA_DIR=str(data_dir))
    for name in ("RUNNERDECK_ORG", "RUNNERDECK_REPO", "RUNNERDECK_SCOPE"):
        env.pop(name, None)
    launcher = subprocess.Popen(
        [str(exe), "--no-browser", "--port", str(PORT)],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    try:
        if not wait_for(lambda: get("/health.php")[0] == 200, 90):
            launcher.kill()
            log = data_dir / "server.log"
            tail = log.read_text(errors="replace")[-1500:] if log.exists() else "(no server.log)"
            fail(f"the server never answered /health.php\n{tail}")
        print("ok  /health.php answers")

        status, body = get("/health.php")
        if json.loads(body).get("ok") is not True:
            fail(f"/health.php body was {body!r}")
        status, body = get("/index.php")
        if status != 200 or "RunnerDeck" not in body:
            fail(f"/index.php returned {status}")
        print("ok  the dashboard page renders")

        status, body = get("/api.php?action=csrf_token")
        if status != 200 or not json.loads(body).get("token"):
            fail(f"csrf_token returned {status}: {body!r}")
        print("ok  the API answers")

        for name in ("server.log", "runners", "db"):
            if not (data_dir / name).exists():
                fail(f"{name} was not created under the data directory")
        print("ok  settings, runners and logs live under the data directory")
    finally:
        launcher.kill()
        output = launcher.communicate(timeout=30)[0]

    if "Welcome to RunnerDeck" not in output:
        fail(f"the welcome banner was not printed. Output was:\n{output[:800]}")
    print("ok  the welcome banner is printed")

    if not wait_for(lambda: get("/health.php")[0] is None, 20):
        subprocess.run(["taskkill", "/F", "/IM", "php.exe"], capture_output=True)
        fail("the PHP server was still running after the launcher was killed")
    print("ok  PHP stops together with the launcher")
    print("Windows package smoke test passed")


if __name__ == "__main__":
    main()
