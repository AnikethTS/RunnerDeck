#!/usr/bin/env bash
# Applies `runnerdeck update --yes` for this machine's package.
# Linux installs the published .deb, macOS upgrades the Homebrew formula,
# and Windows runs the Setup wizard silently into a temp folder.
# The copy under test is version 0.0.1, so the published release is newer.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

pass() {
  printf 'ok  %s\n' "$1"
}

case "$(uname -s)" in
  Linux) method=deb ;;
  Darwin) method=brew ;;
  MINGW* | MSYS* | CYGWIN*) method=windows ;;
  *) fail "unsupported OS $(uname -s)" ;;
esac

command -v gh >/dev/null 2>&1 || fail "gh is required"
command -v php >/dev/null 2>&1 || fail "php is required"
if [ -z "${GH_TOKEN:-${GITHUB_TOKEN:-}}" ]; then
  fail "GH_TOKEN is required to download the release"
fi
export GH_TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
export RUNNERDECK_NO_UPDATE_NOTICE=1
export RUNNERDECK_INSTALL_METHOD="$method"

if [ ! -f app/vendor/autoload.php ]; then
  fail "run composer install in app/ first"
fi

winpath() {
  if command -v cygpath >/dev/null 2>&1; then
    cygpath -m "$1"
  else
    printf '%s\n' "$1"
  fi
}

copy="$(mktemp -d)"
install_dir=""
cleanup() {
  rm -rf "$copy"
  if [ -n "$install_dir" ]; then
    rm -rf "$install_dir"
  fi
}
trap cleanup EXIT

cp -a app runnerdeck VERSION "$copy/"
printf '0.0.1\n' >"$copy/VERSION"
copy_m="$(winpath "$copy")"

if [ "$method" = brew ]; then
  export HOMEBREW_NO_AUTO_UPDATE=1
  export HOMEBREW_NO_ENV_HINTS=1
  export HOMEBREW_GITHUB_API_TOKEN="${HOMEBREW_GITHUB_API_TOKEN:-$GH_TOKEN}"
  # setup-php pours php@8.5 from shivammathur/php and links it as php.
  # Homebrew will not install the formula's php until that copy is gone.
  while read -r formula; do
    case "$formula" in
      php | php@*) brew uninstall --ignore-dependencies --force "$formula" ;;
    esac
  done < <(brew list --formula -1)
  # A fully qualified name trusts that one formula without a prompt.
  brew install anikethts/runnerdeck/runnerdeck
  hash -r || true
  pass "Homebrew formula is installed"
fi

if [ "$method" = windows ]; then
  install_dir="$(mktemp -d)"
  export RUNNERDECK_UPDATE_SILENT=1
  if command -v cygpath >/dev/null 2>&1; then
    RUNNERDECK_UPDATE_DIR="$(cygpath -w "$install_dir")"
  else
    RUNNERDECK_UPDATE_DIR="$install_dir"
  fi
  export RUNNERDECK_UPDATE_DIR
fi

if [ "$method" = deb ]; then
  sudo -n true || fail "sudo is required to install the Debian package"
fi

set +e
check="$(php "$copy_m/app/bin/runnerdeck.php" update --check --json 2>"$copy/check.err")"
status=$?
set -e
if [ "$status" -ne 0 ]; then
  fail "update --check exited ${status}: $(cat "$copy/check.err") ${check}"
fi
latest="$(printf '%s\n' "$check" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? (string) ($d["latest"] ?? "") : "";')"
if [ -z "$latest" ]; then
  fail "update --check did not report a latest version: $check"
fi
pass "update --check sees ${latest} (this copy is 0.0.1)"

php "$copy_m/app/bin/runnerdeck.php" update --yes
pass "update --yes finished (${method})"

hash -r || true
strip() {
  printf '%s' "$1" | tr -d '\r'
}
case "$method" in
  deb)
    shown="$(strip "$(runnerdeck --version)")"
    [ "$shown" = "RunnerDeck ${latest}" ] || fail "installed version is '${shown}'"
    ;;
  brew)
    shown="$(strip "$(runnerdeck --version)")"
    [ "$shown" = "RunnerDeck ${latest}" ] || fail "Homebrew version is '${shown}', release is ${latest}"
    ;;
  windows)
    exe="${install_dir}/RunnerDeck.exe"
    [ -f "$exe" ] || fail "RunnerDeck.exe was not installed in ${install_dir}"
    shown="$(strip "$("$exe" --version)")"
    [ "$shown" = "RunnerDeck ${latest}" ] || fail "installer version is '${shown}'"
    ;;
esac
pass "the installed command reports RunnerDeck ${latest}"
