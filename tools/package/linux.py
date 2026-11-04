import gzip
import hashlib
import io
import os
import shutil
import tarfile
import tempfile
from pathlib import Path

from common import ROOT, Context, artifact_name, launcher_script, stage_unix

PLATFORM = "linux-all"
INSTALL_DIR = "usr/share/runnerdeck"
PACKAGE = "runnerdeck"
MAINTAINER = "AnikethTS <AnikethTS@users.noreply.github.com>"
DEPENDS = "bash, curl, tar, php-cli, php-curl, php-mbstring, php-sqlite3"
DESCRIPTION = (
    "Dashboard for a pool of self-hosted GitHub Actions runners\n"
    " RunnerDeck runs on your own machine, starts and stops the runners in the\n"
    " pool, and shows their state, host load and the jobs waiting for them.\n"
    " Run it with the runnerdeck command; it listens on 127.0.0.1 only unless\n"
    " you enable the TOTP login."
)


def mtime() -> int:
    return int(os.environ.get("SOURCE_DATE_EPOCH", "0"))


def tar_gz(entries: list[tuple[str, bytes | None, int]]) -> bytes:
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.GNU_FORMAT) as archive:
        for name, data, mode in entries:
            info = tarfile.TarInfo(name)
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mtime = mtime()
            info.mode = mode
            if data is None:
                info.type = tarfile.DIRTYPE
                archive.addfile(info)
            else:
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
    out = io.BytesIO()
    with gzip.GzipFile(fileobj=out, mode="wb", mtime=mtime(), compresslevel=9) as handle:
        handle.write(raw.getvalue())
    return out.getvalue()


def ar_archive(members: list[tuple[str, bytes]]) -> bytes:
    out = bytearray(b"!<arch>\n")
    for name, data in members:
        header = f"{name:<16}{mtime():<12}{0:<6}{0:<6}{'100644':<8}{len(data):<10}`\n"
        out += header.encode("ascii") + data
        if len(data) % 2:
            out += b"\n"
    return bytes(out)


def collect(version: str) -> list[tuple[str, bytes, int]]:
    files: list[tuple[str, bytes, int]] = []
    with tempfile.TemporaryDirectory() as tmp:
        stage = Path(tmp) / "stage"
        stage.mkdir()
        stage_unix(stage)
        for path in sorted(stage.rglob("*")):
            if path.is_file():
                relative = path.relative_to(stage).as_posix()
                mode = 0o755 if relative == "run.sh" else 0o644
                files.append((f"{INSTALL_DIR}/{relative}", path.read_bytes(), mode))
    files.append(("usr/bin/runnerdeck", launcher_script("/" + INSTALL_DIR), 0o755))
    linux = ROOT / "packaging" / "linux"
    files.append(("usr/share/applications/runnerdeck.desktop", (linux / "runnerdeck.desktop").read_bytes(), 0o644))
    files.append(("usr/lib/systemd/user/runnerdeck.service", (linux / "runnerdeck.service").read_bytes(), 0o644))
    for size in ("192", "512"):
        icon = ROOT / "app" / "public" / "assets" / f"icon-{size}.png"
        files.append((f"usr/share/icons/hicolor/{size}x{size}/apps/runnerdeck.png", icon.read_bytes(), 0o644))
    files.append(("usr/share/doc/runnerdeck/copyright", (ROOT / "LICENSE").read_bytes(), 0o644))
    return files


def directories(files: list[tuple[str, bytes, int]]) -> list[str]:
    found: set[str] = set()
    for name, _, _ in files:
        parts = name.split("/")[:-1]
        for index in range(1, len(parts) + 1):
            found.add("/".join(parts[:index]))
    return sorted(found)


def control_file(version: str, installed_kib: int) -> bytes:
    fields = [
        ("Package", PACKAGE),
        ("Version", version),
        ("Architecture", "all"),
        ("Maintainer", MAINTAINER),
        ("Installed-Size", str(installed_kib)),
        ("Depends", DEPENDS),
        ("Recommends", "gh"),
        ("Section", "devel"),
        ("Priority", "optional"),
        ("Homepage", "https://github.com/AnikethTS/RunnerDeck"),
    ]
    body = "".join(f"{key}: {value}\n" for key, value in fields)
    return (body + "Description: " + DESCRIPTION + "\n").encode("utf-8")


def build_deb(ctx: Context) -> list[Path]:
    files = collect(ctx.version)
    entries: list[tuple[str, bytes | None, int]] = [(f"./{d}/", None, 0o755) for d in directories(files)]
    entries += [(f"./{name}", data, mode) for name, data, mode in files]
    installed_kib = sum(len(data) for _, data, _ in files) // 1024 + 1
    md5sums = "".join(f"{hashlib.md5(data).hexdigest()}  {name}\n" for name, data, _ in files).encode()
    control = tar_gz(
        [
            ("./", None, 0o755),
            ("./control", control_file(ctx.version, installed_kib), 0o644),
            ("./md5sums", md5sums, 0o644),
        ]
    )
    deb = ar_archive(
        [
            ("debian-binary", b"2.0\n"),
            ("control.tar.gz", control),
            ("data.tar.gz", tar_gz(entries)),
        ]
    )
    target = ctx.out / artifact_name(ctx.version, PLATFORM, ".deb")
    target.write_bytes(deb)
    return [target]


TARGETS = {"linux-deb": build_deb}
