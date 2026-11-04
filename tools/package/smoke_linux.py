#!/usr/bin/env python3
import json
import os
import signal
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PORT = 8202
BASE = f"http://127.0.0.1:{PORT}"


def fail(message: str) -> None:
    print(f"FAIL: {message}")
    sys.exit(1)


def get(path: str):
    if not path.startswith("/"):
        raise ValueError(f"expected an absolute path, got {path!r}")
    try:
        # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
        with urllib.request.urlopen(BASE + path, timeout=3) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except (urllib.error.URLError, OSError):
        return None, ""


def wait_for(check, seconds: float) -> bool:
    deadline = time.time() + seconds
    while time.time() < deadline:
        if check():
            return True
        time.sleep(0.5)
    return False


def main() -> None:
    target = next((a for a in sys.argv[1:] if not a.startswith("--")), "dist")
    path = Path(target)
    deb = sorted(path.glob("RunnerDeck-*-linux-all.deb"))[-1] if path.is_dir() else path
    version = (ROOT / "VERSION").read_text().strip()

    subprocess.run(["sudo", "apt-get", "install", "-y", str(deb.resolve())], check=True)
    try:
        shown = subprocess.run(["runnerdeck", "--version"], capture_output=True, text=True, timeout=30)
        if shown.stdout.strip() != f"RunnerDeck {version}":
            fail(f"--version printed {shown.stdout!r}, expected 'RunnerDeck {version}'")
        print(f"ok  --version: {shown.stdout.strip()}")

        home = tempfile.mkdtemp(prefix="runnerdeck-home-")
        env = dict(os.environ, HOME=home, RUNNERDECK_NO_BANNER="1")
        for name in ("RUNNERDECK_ORG", "RUNNERDECK_REPO", "RUNNERDECK_SCOPE"):
            env.pop(name, None)
        server = subprocess.Popen(
            ["runnerdeck", str(PORT)], env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
            start_new_session=True,
        )
        try:
            if not wait_for(lambda: get("/health.php")[0] == 200, 60):
                server.kill()
                fail("the installed app never answered /health.php\n" + server.communicate(timeout=30)[0][-1500:])
            print("ok  /health.php answers")
            status, body = get("/api.php?action=csrf_token")
            if status != 200 or not json.loads(body).get("token"):
                fail(f"csrf_token returned {status}: {body!r}")
            print("ok  the API answers")
            data = Path(home) / ".local/share/runnerdeck"
            if not (data / "db").is_dir() or not (data / "runners").is_dir():
                fail("the data directory was not created under the user's home")
            print("ok  settings, runners and logs live under the user's data directory")
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            try:
                server.wait(timeout=20)
            except subprocess.TimeoutExpired:
                os.killpg(server.pid, signal.SIGKILL)
        if not wait_for(lambda: get("/health.php")[0] is None, 20):
            fail("the server kept running after it was stopped")
        print("ok  the server stops with the launcher")
    finally:
        subprocess.run(["sudo", "apt-get", "remove", "-y", "runnerdeck"], check=True)
    if Path("/usr/bin/runnerdeck").exists() or Path("/usr/share/runnerdeck").exists():
        fail("uninstalling left files behind")
    print("ok  uninstalling removes the app")
    print("Linux package smoke test passed")


if __name__ == "__main__":
    main()
