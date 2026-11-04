import json
import os
import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

from common import COMMON, DESKTOP, ENTRY, ROOT, Context, artifact_name, copy_app, fetch, sha256_of

PLATFORM = "windows-x64"
RELEASES_URL = "https://windows.php.net/downloads/releases/releases.json"
DOWNLOAD_BASE = "https://windows.php.net/downloads/releases/"
EXTENSIONS = ["curl", "mbstring", "openssl", "pdo_sqlite", "sqlite3"]
PRUNE = ["phpdbg.exe", "php-cgi.exe", "php-win.exe", "php8embed.lib", "php8phpdbg.dll"]
INSTALLER = ROOT / "packaging" / "windows" / "installer" / "runnerdeck.iss"
PRODUCT_EXE = "RunnerDeck.exe"


def php_release(branch: str) -> dict:
    releases = json.loads(fetch(RELEASES_URL, DOWNLOAD_BASE))
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
        target.write_bytes(fetch(DOWNLOAD_BASE + release["path"], DOWNLOAD_BASE))
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


def stage(ctx: Context) -> dict:
    if "windows" in ctx.cache:
        return ctx.cache["windows"]
    branch = (COMMON / "php-branch.txt").read_text().strip()
    release = php_release(branch)
    php_zip = download_php(release, ctx.out / "cache")
    root = ctx.out / "stage" / artifact_name(ctx.version, PLATFORM, "")
    if root.exists():
        shutil.rmtree(root)
    root.mkdir(parents=True)
    with zipfile.ZipFile(php_zip) as archive:
        archive.extractall(root / "php")
    for name in PRUNE:
        (root / "php" / name).unlink(missing_ok=True)
    write_php_ini(root / "php")
    copy_app(root / "app")
    ctx.cache["windows"] = {"root": root, "php_version": release["version"]}
    return ctx.cache["windows"]


def build_launcher(ctx: Context, target: Path, embed: bool = False, tray: bool = False) -> None:
    env = dict(os.environ, GOOS="windows", GOARCH="amd64", CGO_ENABLED="0")
    ldflags = "-s -w"
    command = [ctx.go, "build", "-trimpath"]
    if embed:
        ldflags += f" -X main.embeddedVersion={ctx.version}"
        command += ["-tags", "payload"]
    if tray:
        ldflags += " -H=windowsgui -X main.defaultMode=tray"
    command += ["-ldflags", ldflags, "-o", str(target.resolve()), "./cmd/runnerdeck"]
    subprocess.run(command, cwd=DESKTOP, env=env, check=True)


def with_payload(ctx: Context, build) -> None:
    payload = ENTRY / "payload.zip"
    root = stage(ctx)["root"]
    try:
        with zipfile.ZipFile(payload, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for top in ("php", "app"):
                for path in sorted((root / top).rglob("*")):
                    if path.is_file():
                        archive.write(path, path.relative_to(root))
        build()
    finally:
        payload.unlink(missing_ok=True)


def readme(version: str, php_version: str) -> str:
    return (
        f"RunnerDeck {version} for Windows\r\n"
        "\r\n"
        f"Double-click {PRODUCT_EXE}. It starts the dashboard and opens your browser.\r\n"
        "Close the window (or press Ctrl+C) to stop it.\r\n"
        "\r\n"
        "You also need the GitHub CLI: winget install GitHub.cli, then gh auth login.\r\n"
        "Your settings and runners are kept in %LOCALAPPDATA%\\RunnerDeck.\r\n"
        f"Options: {PRODUCT_EXE} --port 9000 --no-browser --version\r\n"
        "\r\n"
        f"Bundled PHP {php_version} (php.net). This download is not code-signed, so\r\n"
        "Windows SmartScreen may warn the first time: choose More info, then Run anyway.\r\n"
        "https://github.com/AnikethTS/RunnerDeck\r\n"
    )


def build_zip(ctx: Context) -> list[Path]:
    info = stage(ctx)
    root = info["root"]
    build_launcher(ctx, root / PRODUCT_EXE)
    (root / "README.txt").write_text(readme(ctx.version, info["php_version"]), encoding="utf-8", newline="")
    target = ctx.out / artifact_name(ctx.version, PLATFORM, ".zip")
    target.unlink(missing_ok=True)
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(root.rglob("*")):
            if path.is_file():
                archive.write(path, Path(root.name) / path.relative_to(root))
    return [target]


def build_exe(ctx: Context) -> list[Path]:
    target = ctx.out / artifact_name(ctx.version, PLATFORM, ".exe")
    with_payload(ctx, lambda: build_launcher(ctx, target, embed=True))
    return [target]


def find_iscc() -> str:
    found = shutil.which("ISCC") or shutil.which("ISCC.exe")
    if found:
        return found
    for base in (os.environ.get("ProgramFiles(x86)"), os.environ.get("ProgramFiles"), os.environ.get("LOCALAPPDATA")):
        if not base:
            continue
        for candidate in (Path(base) / "Inno Setup 6" / "ISCC.exe", Path(base) / "Programs" / "Inno Setup 6" / "ISCC.exe"):
            if candidate.exists():
                return str(candidate)
    sys.exit("Inno Setup (ISCC.exe) was not found. Install it with: choco install innosetup")


def build_setup(ctx: Context) -> list[Path]:
    tray_exe = ctx.out / "tray" / PRODUCT_EXE
    tray_exe.parent.mkdir(parents=True, exist_ok=True)
    with_payload(ctx, lambda: build_launcher(ctx, tray_exe, embed=True, tray=True))
    subprocess.run(
        [
            find_iscc(),
            "/Qp",
            f"/DAppVersion={ctx.version}",
            f"/DSourceExe={tray_exe}",
            f"/DOutputDir={ctx.out}",
            str(INSTALLER),
        ],
        check=True,
    )
    return [ctx.out / artifact_name(ctx.version, PLATFORM, "-setup.exe")]


TARGETS = {
    "windows-zip": build_zip,
    "windows-exe": build_exe,
    "windows-setup": build_setup,
}
