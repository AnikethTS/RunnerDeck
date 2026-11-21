#!/bin/sh
set -eu
GH_VERSION=2.105.0
GH_SHA256=79e7ab5db738c48777b696e374f8b428f0647debfb424f44d2f426370407fe2f
DEST="${1:-/usr/local/bin/gh}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL -o "$tmp/gh.tar.gz" \
  "https://github.com/cli/cli/releases/download/v${GH_VERSION}/gh_${GH_VERSION}_linux_amd64.tar.gz"
echo "${GH_SHA256}  $tmp/gh.tar.gz" | sha256sum -c -
tar -xzf "$tmp/gh.tar.gz" -C "$tmp"
cp "$tmp/gh_${GH_VERSION}_linux_amd64/bin/gh" "$DEST"
chmod 0755 "$DEST"
