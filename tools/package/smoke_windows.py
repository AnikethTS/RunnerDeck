#!/usr/bin/env python3
import json
import os
import shutil
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
    if not path.startswith("/"):
        raise ValueError(f"expected an absolute path, got {path!r}")
    try:
        # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
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


def find_artifact(target: str, pattern: str) -> Path:
    path = Path(target)
    if path.is_dir():
        found = sorted(path.glob(pattern))
        if not found:
            fail(f"no {pattern} in {path}")
        return found[-1]
    return path


def locate_exe(target: str, single: bool, work: Path) -> Path:
    if single:
        source = find_artifact(target, "RunnerDeck-*-windows-x64.exe")
        alone = work / "alone"
        alone.mkdir()
        shutil.copy2(source, alone / "RunnerDeck.exe")
        return alone / "RunnerDeck.exe"
    archive = find_artifact(target, "RunnerDeck-*-windows-x64.zip")
    with zipfile.ZipFile(archive) as zf:
        zf.extractall(work / "pkg")
    exe = next((work / "pkg").glob("*/RunnerDeck.exe"), None)
    if exe is None:
        fail("RunnerDeck.exe is missing from the zip")
    return exe


def main() -> None:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    single = "--single" in sys.argv[1:]
    target = args[0] if args else "dist"
    version = (ROOT / "VERSION").read_text().strip()
    work = Path(tempfile.mkdtemp(prefix="runnerdeck-smoke-"))
    exe = locate_exe(target, single, work)

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
        stdin=subprocess.DEVNULL,
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
        if single and not list((data_dir / "runtime").glob("*/php/php.exe")):
            fail("the single exe did not unpack its runtime under the data directory")
        print("ok  settings, runners and logs live under the data directory")
    finally:
        launcher.kill()
        output = launcher.communicate(timeout=30)[0]

    if "Welcome to RunnerDeck" not in output:
        fail(f"the welcome banner was not printed. Output was:\n{output[:800]}")
    print("ok  the welcome banner is printed")
    for expected in ("[1/4] Preparing RunnerDeck", "[2/4] Checking PHP", "[4/4] Starting the dashboard"):
        if expected not in output:
            fail(f"the guided steps are missing {expected!r}. Output was:\n{output[:1200]}")
    print("ok  the guided setup steps are printed")

    if not wait_for(lambda: get("/health.php")[0] is None, 20):
        subprocess.run(["taskkill", "/F", "/IM", "php.exe"], capture_output=True)
        fail("the PHP server was still running after the launcher was killed")
    print("ok  PHP stops together with the launcher")
    print("Windows package smoke test passed")


if __name__ == "__main__":
    main()
