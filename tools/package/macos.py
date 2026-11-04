import sys
from pathlib import Path

from common import ROOT, Context, sha256_of

TEMPLATE = ROOT / "packaging" / "macos" / "runnerdeck.rb.in"
RELEASE_URL = "https://github.com/AnikethTS/RunnerDeck/releases/download/{tag}/{name}"


def render_formula(version: str, url: str, sha256: str) -> str:
    text = TEMPLATE.read_text(encoding="utf-8")
    for placeholder, value in (("@URL@", url), ("@VERSION@", version), ("@SHA256@", sha256)):
        if placeholder not in text:
            sys.exit(f"{TEMPLATE.name} has no {placeholder} placeholder")
        text = text.replace(placeholder, value)
    return text


def build_formula(ctx: Context) -> list[Path]:
    if ctx.source_zip is None:
        sys.exit("brew-formula needs --source-zip (the release's runnerdeck-<tag>.zip)")
    url = ctx.url or RELEASE_URL.format(tag=ctx.tag or f"v{ctx.version}", name=ctx.source_zip.name)
    target = ctx.out / "runnerdeck.rb"
    target.write_text(render_formula(ctx.version, url, sha256_of(ctx.source_zip)), encoding="utf-8")
    return [target]


TARGETS = {"brew-formula": build_formula}
