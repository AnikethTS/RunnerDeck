#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RELEASES_URL = "https://windows.php.net/downloads/releases/releases.json"
DOWNLOAD_BASE = "https://windows.php.net/downloads/releases/"
EXTENSIONS = ["curl", "mbstring", "openssl", "pdo_sqlite", "sqlite3"]
PRUNE = ["phpdbg.exe", "php-cgi.exe", "php-win.exe", "php8embed.lib", "php8phpdbg.dll"]
APP_DIRS = ["public", "src", "bin"]
APP_FILES = ["VERSION", "LICENSE"]


def fetch(url: str) -> bytes:
    if not url.startswith(DOWNLOAD_BASE):
        sys.exit(f"refusing to fetch {url}: only {DOWNLOAD_BASE} is allowed")
    request = urllib.request.Request(url, headers={"User-Agent": "runnerdeck-package-build"})
    # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def php_release(branch: str) -> dict:
    releases = json.loads(fetch(RELEASES_URL))
    entry = releases.get(branch, {}).get("nts-vs17-x64", {}).get("zip")
    if not entry:
        sys.exit(f"windows.php.net has no nts-vs17-x64 build for PHP {branch}")
    if not re.fullmatch(r"php-[0-9A-Za-z.\-]+-nts-Win32-vs17-x64\.zip", entry["path"]):
        sys.exit(f"unexpected PHP archive name {entry['path']!r}")
    return {"version": releases[branch]["version"], "path": entry["path"], "sha256": entry["sha256"]}


def download_php(release: dict, cache: Path) -> Path:
    cache.mkdir(parents=True, exist_ok=True)
    target = cache / release["path"]
    if not target.is_file() or sha256_of(target) != release["sha256"]:
        print(f"Downloading {release['path']}")
        target.write_bytes(fetch(DOWNLOAD_BASE + release["path"]))
    actual = sha256_of(target)
    if actual != release["sha256"]:
        target.unlink()
        sys.exit(f"SHA-256 mismatch for {release['path']}: expected {release['sha256']}, got {actual}")
    return target


def write_php_ini(php_dir: Path) -> None:
    ini = (php_dir / "php.ini-production").read_text(encoding="utf-8")
    ini = re.sub(r'(?m)^;\s*extension_dir\s*=\s*"ext"\s*$', 'extension_dir = "ext"', ini, count=1)
    for extension in EXTENSIONS:
        pattern = rf"(?m)^;\s*extension={re.escape(extension)}\s*$"
        if not re.search(pattern, ini):
            sys.exit(f"php.ini-production has no commented extension={extension} line")
        ini = re.sub(pattern, f"extension={extension}", ini, count=1)
    (php_dir / "php.ini").write_text(ini, encoding="utf-8", newline="\n")


def copy_app(app_dir: Path) -> None:
    app_dir.mkdir(parents=True)
    ignore = shutil.ignore_patterns("*.log", "__pycache__", ".gitkeep")
    for name in APP_DIRS:
        shutil.copytree(ROOT / name, app_dir / name, ignore=ignore)
    for name in APP_FILES:
        shutil.copy2(ROOT / name, app_dir / name)


LAUNCHER = ROOT / "packaging" / "windows" / "launcher"


def build_launcher(go: str, target: Path, embed_version: str | None = None) -> None:
    env = dict(os.environ, GOOS="windows", GOARCH="amd64", CGO_ENABLED="0")
    ldflags = "-s -w"
    command = [go, "build", "-trimpath"]
    if embed_version:
        ldflags += f" -X main.embeddedVersion={embed_version}"
        command += ["-tags", "payload"]
    command += ["-ldflags", ldflags, "-o", str(target.resolve()), "."]
    subprocess.run(command, cwd=LAUNCHER, env=env, check=True)


def make_payload(stage: Path, payload_path: Path) -> None:
    with zipfile.ZipFile(payload_path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for top in ("php", "app"):
            for path in sorted((stage / top).rglob("*")):
                if path.is_file():
                    archive.write(path, path.relative_to(stage))


def build_single_exe(go: str, stage: Path, version: str, out: Path) -> Path:
    payload = LAUNCHER / "payload.zip"
    target = out / f"runnerdeck-{version}-windows-x64.exe"
    try:
        make_payload(stage, payload)
        build_launcher(go, target, embed_version=version)
    finally:
        payload.unlink(missing_ok=True)
    digest = sha256_of(target)
    (out / f"{target.name}.sha256").write_text(f"{digest}  {target.name}\n")
    return target


def readme(version: str, php_version: str) -> str:
    return (
        f"RunnerDeck {version} for Windows\r\n"
        "\r\n"
        "Double-click runnerdeck.exe. It starts the dashboard and opens your browser.\r\n"
        "Close the window (or press Ctrl+C) to stop it.\r\n"
        "\r\n"
        "You also need the GitHub CLI: winget install GitHub.cli, then gh auth login.\r\n"
        "Your settings and runners are kept in %LOCALAPPDATA%\\RunnerDeck.\r\n"
        "Options: runnerdeck.exe --port 9000 --no-browser --version\r\n"
        "\r\n"
        f"Bundled PHP {php_version} (php.net). This download is not code-signed, so\r\n"
        "Windows SmartScreen may warn the first time: choose More info, then Run anyway.\r\n"
        "https://github.com/AnikethTS/RunnerDeck\r\n"
    )


def make_zip(stage: Path, zip_path: Path) -> None:
    if zip_path.exists():
        zip_path.unlink()
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(stage.rglob("*")):
            if path.is_file():
                archive.write(path, Path(stage.name) / path.relative_to(stage))


def main() -> None:
    parser = argparse.ArgumentParser(description="Build the RunnerDeck Windows zip")
    parser.add_argument("--out", default=str(ROOT / "dist"))
    parser.add_argument("--go", default=os.environ.get("GO", "go"))
    parser.add_argument("--single-exe", action="store_true", help="also build one self-contained runnerdeck.exe")
    args = parser.parse_args()

    version = (ROOT / "VERSION").read_text().strip()
    branch = (ROOT / "packaging" / "windows" / "php-branch.txt").read_text().strip()
    out = Path(args.out).resolve()
    release = php_release(branch)
    php_zip = download_php(release, out / "cache")

    stage = out / f"runnerdeck-{version}-windows-x64"
    if stage.exists():
        shutil.rmtree(stage)
    stage.mkdir(parents=True)
    php_dir = stage / "php"
    with zipfile.ZipFile(php_zip) as archive:
        archive.extractall(php_dir)
    for name in PRUNE:
        (php_dir / name).unlink(missing_ok=True)
    write_php_ini(php_dir)
    copy_app(stage / "app")
    build_launcher(args.go, stage / "runnerdeck.exe")
    (stage / "README.txt").write_text(readme(version, release["version"]), encoding="utf-8", newline="")

    zip_path = out / f"runnerdeck-{version}-windows-x64.zip"
    make_zip(stage, zip_path)
    digest = sha256_of(zip_path)
    (out / f"{zip_path.name}.sha256").write_text(f"{digest}  {zip_path.name}\n")
    print(f"Built {zip_path} ({zip_path.stat().st_size / 1_048_576:.1f} MB)")
    print(f"sha256 {digest}")
    if args.single_exe:
        exe = build_single_exe(args.go, stage, version, out)
        print(f"Built {exe} ({exe.stat().st_size / 1_048_576:.1f} MB)")
        print(f"sha256 {sha256_of(exe)}")
    print(f"PHP {release['version']}")


if __name__ == "__main__":
    main()
