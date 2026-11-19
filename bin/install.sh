#!/bin/sh
# One-command install of the latest RunnerDeck release (Linux and macOS):
#
#   curl -fsSL https://raw.githubusercontent.com/AnikethTS/RunnerDeck/main/bin/install.sh | sh
#
# Downloads the release zip, checks its SHA-256, unpacks it into
# ~/.local/share/runnerdeck and runs `./runnerdeck install`, which links the
# command into ~/.local/bin and sets up tab completion. Run it again to upgrade;
# your data, runners and .env in that folder are kept.
#
# RUNNERDECK_DIR   install folder (default ~/.local/share/runnerdeck)
# RUNNERDECK_TAG   a specific release, e.g. v2.0.0 (default: the latest)
set -eu

REPO="AnikethTS/RunnerDeck"
DEST="${RUNNERDECK_DIR:-${HOME:?HOME is not set}/.local/share/runnerdeck}"

need() {
  command -v "$1" >/dev/null 2>&1 || { echo "runnerdeck install: '$1' is required" >&2; exit 1; }
}
need curl
need unzip
need php
if command -v sha256sum >/dev/null 2>&1; then
  sha_check() { sha256sum -c "$1" >/dev/null; }
else
  need shasum
  sha_check() { shasum -a 256 -c "$1" >/dev/null; }
fi

tag="${RUNNERDECK_TAG:-}"
if [ -z "$tag" ]; then
  tag="$(curl -fsSL "https://api.github.com/repos/${REPO}/releases/latest" \
    | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -n 1)"
fi
[ -n "$tag" ] || { echo "runnerdeck install: could not find the latest release" >&2; exit 1; }

# Same mark as `runnerdeck --version`. A pipe into `sh` still has a terminal
# on stdout, so the colour shows there. NO_COLOR and RUNNERDECK_NO_BANNER
# match the dashboard banner.
if [ "${RUNNERDECK_NO_BANNER:-}" != 1 ]; then
  if [ -t 1 ] && [ -z "${NO_COLOR+x}" ]; then
    printf '%b\n' \
      '' \
      '            \033[90m######\033[0m' \
      '        \033[90m##############\033[0m' \
      '    \033[90m########\033[0m      \033[90m######\033[0m \033[1;33m@\033[0m' \
      "  \033[90m#######\033[0m     \033[1;33m@@@\033[0m    \033[90m#\033[0m \033[1;33m@@@@@\033[0m  \033[1mWelcome to RunnerDeck\033[0m \033[33m${tag}\033[0m" \
      '  \033[90m####\033[0m    \033[1;33m@@@@@@@\033[0m     \033[1;33m@@@@\033[0m \033[90m#\033[0m  One dashboard for every self-hosted' \
      '  \033[90m####\033[0m    \033[1;33m@@@@@@\033[0m    \033[1;33m@@@@\033[0m  \033[90m##\033[0m  GitHub Actions runner.' \
      '  \033[90m####\033[0m  \033[1;33m@@@@@\033[0m  \033[1;33m@\033[0m  \033[1;33m@@@@@\033[0m \033[90m####\033[0m' \
      '  \033[90m###\033[0m\033[1;33m@@@@@@\033[0m \033[1;33m@@@\033[0m \033[1;33m@@@@@\033[0m   \033[90m####\033[0m  \033[90mBuilt by\033[0m Aniketh TS and contributors' \
      '  \033[90m#\033[0m\033[1;33m@@@@@@\033[0m   \033[1;33m@@@@@@@\033[0m     \033[90m####\033[0m  \033[90mLicense\033[0m  MIT' \
      '  \033[1;33m@@@@@\033[0m\033[90m#\033[0m      \033[1;33m@@@\033[0m     \033[90m######\033[0m' \
      '    \033[1;33m@\033[0m\033[90m#######\033[0m      \033[90m########\033[0m    \033[90mDocs and issues\033[0m' \
      '        \033[90m##############\033[0m        \033[4mhttps://github.com/AnikethTS/RunnerDeck\033[0m' \
      '            \033[90m######\033[0m' \
      ''
  else
    printf '%s\n' \
      '' \
      '            ######' \
      '        ##############' \
      '    ########      ###### @' \
      "  #######     @@@    # @@@@@  Welcome to RunnerDeck ${tag}" \
      '  ####    @@@@@@@     @@@@ #  One dashboard for every self-hosted' \
      '  ####    @@@@@@    @@@@  ##  GitHub Actions runner.' \
      '  ####  @@@@@  @  @@@@@ ####' \
      '  ###@@@@@@ @@@ @@@@@   ####  Built by Aniketh TS and contributors' \
      '  #@@@@@@   @@@@@@@     ####  License  MIT' \
      '  @@@@@#      @@@     ######' \
      '    @#######      ########    Docs and issues' \
      '        ##############        https://github.com/AnikethTS/RunnerDeck' \
      '            ######' \
      ''
  fi
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
zip="runnerdeck-${tag}.zip"
base="https://github.com/${REPO}/releases/download/${tag}"

echo "Downloading RunnerDeck ${tag}..."
curl -fsSL -o "$tmp/$zip" "${base}/${zip}"
curl -fsSL -o "$tmp/$zip.sha256" "${base}/${zip}.sha256"
(cd "$tmp" && sha_check "$zip.sha256") || {
  echo "runnerdeck install: checksum mismatch; nothing was installed" >&2
  exit 1
}

unzip -q "$tmp/$zip" -d "$tmp/unpacked"
src="$tmp/unpacked/runnerdeck-${tag}"
[ -f "$src/runnerdeck" ] || { echo "runnerdeck install: unexpected archive layout" >&2; exit 1; }

# Copy over the top of any existing install so local data is never deleted.
mkdir -p "$DEST"
cp -R "$src/." "$DEST/"
chmod +x "$DEST/runnerdeck"

cd "$DEST"
# --force so an upgrade or a move of the folder replaces a stale link.
./runnerdeck install --force
echo "RunnerDeck ${tag} is in ${DEST}"
