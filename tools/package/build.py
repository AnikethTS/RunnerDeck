#!/usr/bin/env python3
import argparse
import os
import sys
from pathlib import Path

from common import Context, read_version, sha256_of, write_sums
import windows

REGISTRY = {name: (windows.PLATFORM, build) for name, build in windows.TARGETS.items()}


def main() -> None:
    parser = argparse.ArgumentParser(description="Build RunnerDeck release artifacts")
    parser.add_argument("--target", action="append", choices=sorted(REGISTRY) + ["windows"], required=True)
    parser.add_argument("--out", default=str(Path(__file__).resolve().parents[2] / "dist"))
    parser.add_argument("--go", default=os.environ.get("GO", "go"))
    args = parser.parse_args()

    names: list[str] = []
    for target in args.target:
        names += [n for n in REGISTRY if n.startswith("windows-")] if target == "windows" else [target]
    out = Path(args.out).resolve()
    out.mkdir(parents=True, exist_ok=True)
    ctx = Context(out=out, go=args.go, version=read_version())

    by_platform: dict[str, list[Path]] = {}
    for name in dict.fromkeys(names):
        platform, build = REGISTRY[name]
        print(f"== {name}")
        for artifact in build(ctx):
            by_platform.setdefault(platform, []).append(artifact)
            print(f"Built {artifact} ({artifact.stat().st_size / 1_048_576:.1f} MB)")
            print(f"sha256 {sha256_of(artifact)}")
    for platform, files in by_platform.items():
        print(f"Wrote {write_sums(out, platform, files)}")


if __name__ == "__main__":
    sys.exit(main())
