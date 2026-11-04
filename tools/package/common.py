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
APP_FILES = ["VERSION", "LICENSE"]


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


def copy_app(app_dir: Path) -> None:
    app_dir.mkdir(parents=True)
    ignore = shutil.ignore_patterns("*.log", "__pycache__", ".gitkeep")
    for name in APP_DIRS:
        shutil.copytree(ROOT / name, app_dir / name, ignore=ignore)
    for name in APP_FILES:
        shutil.copy2(ROOT / name, app_dir / name)


@dataclass
class Context:
    out: Path
    go: str
    version: str
    cache: dict = field(default_factory=dict)
