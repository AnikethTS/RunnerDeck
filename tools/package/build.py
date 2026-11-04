#!/usr/bin/env python3
import argparse
import os
import sys
from pathlib import Path

import linux
import macos
import windows
from common import Context, read_version, sha256_of, write_sums

REGISTRY = {}
for module, platform in ((windows, windows.PLATFORM), (linux, linux.PLATFORM), (macos, None)):
    for name, build in module.TARGETS.items():
        REGISTRY[name] = (platform, build)

GROUPS = {"windows": [n for n in REGISTRY if n.startswith("windows-")]}


def main() -> None:
    parser = argparse.ArgumentParser(description="Build RunnerDeck release artifacts")
    parser.add_argument("--target", action="append", choices=sorted(REGISTRY) + sorted(GROUPS), required=True)
    parser.add_argument("--out", default=str(Path(__file__).resolve().parents[2] / "dist"))
    parser.add_argument("--go", default=os.environ.get("GO", "go"))
    parser.add_argument("--source-zip", help="the release source archive (brew-formula)")
    parser.add_argument("--tag", help="the release tag, such as v1.12.0 (brew-formula)")
    parser.add_argument("--url", help="override the download URL written into the formula")
    args = parser.parse_args()

    names: list[str] = []
    for target in args.target:
        names += GROUPS.get(target, [target])
    out = Path(args.out).resolve()
    out.mkdir(parents=True, exist_ok=True)
    ctx = Context(
        out=out,
        go=args.go,
        version=read_version(),
        source_zip=Path(args.source_zip).resolve() if args.source_zip else None,
        tag=args.tag,
        url=args.url,
    )

    by_platform: dict[str, list[Path]] = {}
    for name in dict.fromkeys(names):
        platform, build = REGISTRY[name]
        print(f"== {name}")
        for artifact in build(ctx):
            if platform:
                by_platform.setdefault(platform, []).append(artifact)
            print(f"Built {artifact} ({artifact.stat().st_size / 1_048_576:.1f} MB)")
            print(f"sha256 {sha256_of(artifact)}")
    for platform, files in by_platform.items():
        print(f"Wrote {write_sums(out, platform, files)}")


if __name__ == "__main__":
    sys.exit(main())
