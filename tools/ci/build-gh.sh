#!/bin/sh
# Rebuild the pinned GitHub CLI with a patched golang.org/x/net.
# gh 2.105.0 still vendors x/net v0.62.0. Trivy fails the release image on
# CVE-2026-78661, which is fixed from v0.62.0; this build uses v0.63.0.
set -eu
GH_VERSION=2.105.0
GH_SRC_SHA256=3a8fddd2ca0f7c96983a251d3971a84ef042b230b26ca696e5e6ed811cd0ed8d
XNET_VERSION=v0.63.0
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
# Publish time of v2.105.0, so the embedded version string stays stable.
GH_VERSION="v${GH_VERSION}" SOURCE_DATE_EPOCH=1794961115 CGO_ENABLED=0 \
  go run script/build.go
cp bin/gh "$DEST"
chmod 0755 "$DEST"
