#!/bin/sh
# Pin used by the Docker image and Alpine CI. Bump both values together.
set -eu
GH_VERSION=2.100.0
GH_SHA256=e4d4bb4498e8d007abe545b6568926793ace1b6447da598294a610018cb164be
DEST="${1:-/usr/local/bin/gh}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL -o "$tmp/gh.tar.gz" \
  "https://github.com/cli/cli/releases/download/v${GH_VERSION}/gh_${GH_VERSION}_linux_amd64.tar.gz"
echo "${GH_SHA256}  $tmp/gh.tar.gz" | sha256sum -c -
tar -xzf "$tmp/gh.tar.gz" -C "$tmp"
cp "$tmp/gh_${GH_VERSION}_linux_amd64/bin/gh" "$DEST"
chmod 0755 "$DEST"
