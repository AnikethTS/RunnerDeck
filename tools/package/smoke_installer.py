#!/usr/bin/env python3
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PORT = 8196
BASE = f"http://127.0.0.1:{PORT}"


def fail(message: str) -> None:
    print(f"FAIL: {message}")
    sys.exit(1)


def health() -> int | None:
    try:
        # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
        with urllib.request.urlopen(BASE + "/health.php", timeout=3) as response:
            return response.status
    except (urllib.error.URLError, OSError):
        return None


def wait_for(check, seconds: float, interval: float = 0.5) -> bool:
    deadline = time.time() + seconds
    while time.time() < deadline:
        if check():
            return True
        time.sleep(interval)
    return False


def find_setup(target: str) -> Path:
    path = Path(target)
    if path.is_dir():
        found = sorted(path.glob("RunnerDeck-*-windows-x64-setup.exe"))
        if not found:
            fail(f"no RunnerDeck-*-windows-x64-setup.exe in {path}")
        return found[-1]
    return path


def main() -> None:
    target = next((a for a in sys.argv[1:] if not a.startswith("--")), "dist")
    setup = find_setup(target)
    version = (ROOT / "VERSION").read_text().strip()
    work = Path(tempfile.mkdtemp(prefix="runnerdeck-installer-"))
    install_dir = work / "app"
    data_dir = work / "data"
    log = work / "setup.log"

    done = subprocess.run(
        [str(setup), "/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART", "/TASKS=", f"/DIR={install_dir}", f"/LOG={log}"],
        timeout=300,
    )
    if done.returncode != 0:
        tail = log.read_text(errors="replace")[-2000:] if log.exists() else "(no log)"
        fail(f"the installer exited with {done.returncode}\n{tail}")
    print("ok  the installer ran silently without administrator rights")

    exe = install_dir / "RunnerDeck.exe"
    uninstaller = install_dir / "unins000.exe"
    for path in (exe, uninstaller, install_dir / "RunnerDeck.ico"):
        if not path.exists():
            fail(f"{path.name} was not installed")
    group = Path(os.environ["APPDATA"]) / "Microsoft/Windows/Start Menu/Programs/RunnerDeck"
    shortcut = group / "RunnerDeck.lnk"
    for name in ("RunnerDeck.lnk", "Uninstall RunnerDeck.lnk"):
        if not (group / name).exists():
            fail(f"the Start menu entry {name} was not created")
    print("ok  files and the Start menu shortcut are in place")

    shown = subprocess.run([str(exe), "--version"], capture_output=True, text=True, timeout=30)
    if shown.stdout.strip() != f"RunnerDeck {version}":
        fail(f"--version printed {shown.stdout!r}, expected 'RunnerDeck {version}'")
    print(f"ok  installed app reports {shown.stdout.strip()}")

    env = dict(os.environ, RUNNERDECK_DATA_DIR=str(data_dir))
    for name in ("RUNNERDECK_ORG", "RUNNERDECK_REPO", "RUNNERDECK_SCOPE"):
        env.pop(name, None)
    app = subprocess.Popen(
        [str(exe), "--no-browser", "--no-prompts", "--port", str(PORT)],
        env=env,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        if not wait_for(lambda: health() == 200 or app.poll() is not None, 120):
            fail("the tray app never answered /health.php")
        if app.poll() is not None:
            fail(f"the tray app exited with {app.returncode} before the server was up")
        print("ok  the windowless tray app starts the dashboard")

        second = subprocess.run(
            [str(exe), "--no-browser", "--no-prompts", "--port", str(PORT)],
            env=env,
            stdin=subprocess.DEVNULL,
            capture_output=True,
            timeout=30,
        )
        if second.returncode != 0 or app.poll() is not None:
            fail("starting RunnerDeck a second time should just hand over to the running one")
        print("ok  a second launch does not start a second server")
    finally:
        subprocess.run(["taskkill", "/F", "/T", "/PID", str(app.pid)], capture_output=True)

    if not wait_for(lambda: health() is None, 20):
        subprocess.run(["taskkill", "/F", "/IM", "php.exe"], capture_output=True)
        fail("PHP kept running after the tray app was stopped")
    print("ok  PHP stops together with the tray app")

    removed = subprocess.run(
        [str(uninstaller), "/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART"], timeout=300
    )
    if removed.returncode != 0:
        fail(f"the uninstaller exited with {removed.returncode}")
    if not wait_for(lambda: not exe.exists(), 30):
        fail("RunnerDeck.exe is still installed after uninstalling")
    if shortcut.exists():
        fail("the Start menu shortcut survived uninstalling")
    if group.exists():
        fail("the Start menu folder survived uninstalling")
    print("ok  uninstalling removes the app and its shortcut")
    print("Windows installer smoke test passed")


if __name__ == "__main__":
    main()
