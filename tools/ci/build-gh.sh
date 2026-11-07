#!/bin/sh
# Rebuild the pinned GitHub CLI with a patched golang.org/x/net.
# The newest published gh (2.103.3) still vendors x/net v0.60.0, and Trivy
# fails the release image on CVE-2026-78661 (fixed in v0.62.0).
set -eu
GH_VERSION=2.103.3
GH_SRC_SHA256=bb148138a3a2fe023e57c78042fe3b42fc09171c0e8fff4cf983b227b7a2eade
XNET_VERSION=v0.62.0
DEST="${1:-/usr/local/bin/gh}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL -o "$tmp/src.tar.gz" \
  "https://github.com/cli/cli/archive/refs/tags/v${GH_VERSION}.tar.gz"
echo "${GH_SRC_SHA256}  $tmp/src.tar.gz" | sha256sum -c -
tar -xzf "$tmp/src.tar.gz" -C "$tmp"

cd "$tmp/cli-${GH_VERSION}"
go get "golang.org/x/net@${XNET_VERSION}"
go mod tidy
mkdir -p "$(dirname "$DEST")"
# Tag date of v2.103.3, so the embedded version string stays stable.
GH_VERSION="v${GH_VERSION}" SOURCE_DATE_EPOCH=1792972800 CGO_ENABLED=0 \
  go run script/build.go
cp bin/gh "$DEST"
chmod 0755 "$DEST"
