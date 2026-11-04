import hashlib
import shutil
import sys
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DESKTOP = ROOT / "desktop"
ENTRY = DESKTOP / "cmd" / "runnerdeck"
COMMON = ROOT / "packaging" / "common"

APP_DIRS = ["public", "src", "bin"]
ROOT_FILES = ["VERSION", "LICENSE"]


def read_version() -> str:
    return (ROOT / "VERSION").read_text().strip()


def artifact_name(version: str, platform: str, suffix: str) -> str:
    return f"RunnerDeck-{version}-{platform}{suffix}"


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_sums(out: Path, platform: str, files: list[Path]) -> Path:
    target = out / f"SHA256SUMS-{platform}"
    lines = [f"{sha256_of(path)}  {path.name}\n" for path in sorted(files)]
    target.write_text("".join(lines))
    return target


def fetch(url: str, allowed_prefix: str) -> bytes:
    if not url.startswith(allowed_prefix):
        sys.exit(f"refusing to fetch {url}: only {allowed_prefix} is allowed")
    request = urllib.request.Request(url, headers={"User-Agent": "runnerdeck-package-build"})
    # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def copy_app(install_root: Path) -> Path:
    app_dir = install_root / "app"
    app_dir.mkdir(parents=True)
    ignore = shutil.ignore_patterns("*.log", "__pycache__", ".gitkeep")
    for name in APP_DIRS:
        shutil.copytree(ROOT / "app" / name, app_dir / name, ignore=ignore)
    for name in ROOT_FILES:
        shutil.copy2(ROOT / name, install_root / name)
    return app_dir


def stage_unix(install_root: Path) -> None:
    copy_app(install_root)
    shutil.copy2(ROOT / "run.sh", install_root / "run.sh")


def launcher_script(app_root: str) -> bytes:
    text = (COMMON / "runnerdeck").read_text(encoding="utf-8")
    if "@APP_ROOT@" not in text:
        sys.exit("packaging/common/runnerdeck has no @APP_ROOT@ placeholder")
    return text.replace("@APP_ROOT@", app_root).encode("utf-8")


@dataclass
class Context:
    out: Path
    go: str
    version: str
    source_zip: Path | None = None
    tag: str | None = None
    url: str | None = None
    cache: dict = field(default_factory=dict)
