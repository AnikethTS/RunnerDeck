#!/usr/bin/env bash
# Drives the real `runnerdeck` command line against a throwaway pool and
# settings folder. Needs no GitHub runner; `update --check` uses `gh` if present.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

windows=0
case "$(uname -s)" in
  MINGW* | MSYS* | CYGWIN*) windows=1 ;;
esac
if [ "$windows" -eq 1 ]; then
  rd=(php app/bin/runnerdeck.php)
else
  rd=("$root/runnerdeck")
fi

winpath() {
  if command -v cygpath >/dev/null 2>&1; then
    cygpath -m "$1"
  else
    printf '%s' "$1"
  fi
}

scratch="$(winpath "$(mktemp -d)")"
trap 'rm -rf "$scratch"' EXIT
mkdir -p "${scratch}/runners" "${scratch}/db"
export RUNNERDECK_POOL_DIR="${scratch}/runners"
export RUNNERDECK_SETTINGS_FILE="${scratch}/settings.json"
export RUNNERDECK_HISTORY_FILE="${scratch}/db/history.sqlite"
unset RUNNERDECK_ORG RUNNERDECK_REPO RUNNERDECK_SCOPE RUNNERDECK_NO_BANNER NO_COLOR

version="$(tr -d '\r\n' <VERSION)"
out=""
err=""
status=0

fail() {
  echo "FAIL: $1" >&2
  [ -z "$out" ] || echo "stdout: $out" >&2
  [ -z "$err" ] || echo "stderr: $err" >&2
  exit 1
}

pass() { echo "ok  $1"; }

run() {
  status=0
  out="$("${rd[@]}" "$@" 2>"${scratch}/err")" || status=$?
  err="$(cat "${scratch}/err")"
}

expect() {
  local want="$1" label="$2"
  shift 2
  run "$@"
  [ "$status" -eq "$want" ] || fail "${label}: exit ${status}, wanted ${want}"
}

has() {
  case "$1" in
    *"$2"*) ;;
    *) fail "$3: missing '$2'" ;;
  esac
}

json() {
  printf '%s' "$out" | jq -e "$1" >/dev/null || fail "$2: JSON check '$1' failed"
}

# version, help and unknown input
for flag in -v --version version; do
  expect 0 "$flag" "$flag"
  [ "$out" = "RunnerDeck ${version}" ] || fail "$flag printed '${out}'"
done
pass "-v, --version and version print RunnerDeck ${version}"

expect 0 "--version --color" --version --color
has "$out" "Welcome to RunnerDeck" "banner"
has "$out" "One dashboard for every self-hosted" "banner"
pass "--version --color shows the logo and welcome message"

expect 0 "help" help
for command in list status start stop restart rolling-restart drain add delete rename force-idle resize \
  maintenance logs setup config auth doctor run update install uninstall completion version; do
  has "$out" "$command" "help"
done
pass "help lists every command"

expect 1 "unknown command" bogus
has "$err" "unknown command: bogus" "unknown command"
[ -z "$out" ] || fail "an unknown command printed on stdout"
expect 1 "unknown runner" stop ghost
has "$err" "unknown runner(s): ghost" "unknown runner"
expect 1 "unknown runner json" stop ghost --json
json '.ok == false' "unknown runner json"
pass "bad input exits 1 with a message, and as JSON with --json"

# an empty pool
expect 0 "list" list
has "$out" "No runners yet" "empty list"
expect 0 "list --json" list --json
json '.ok == true and (.runners | length) == 0' "list --json"
pass "an empty pool lists cleanly, as text and JSON"

# setup and config
expect 1 "config before setup" config set pool-cap 4
has "$err" "runnerdeck setup" "config before setup"
expect 0 "setup" setup --repo acme/widgets
has "$out" "Scope: repo (acme/widgets)" "setup"
expect 1 "setup bad repo" setup --repo nonsense
expect 0 "config get scope" config get scope
[ "$out" = "repo" ] || fail "scope was '${out}'"
expect 0 "config set" config set pool-cap 4
expect 0 "config set flag" config set auto-restart on
expect 0 "config list --json" config list --json
json '.settings["pool-cap"] == "4" and .settings["auto-restart"] == "1" and .settings.repo == "acme/widgets"' "config list"
expect 1 "config invalid" config set pool-cap 99
has "$err" "pool cap must be" "config invalid"
expect 1 "config unknown" config set bogus 1
expect 1 "config scope" config set org x
expect 0 "config unset" config unset pool-cap
expect 0 "config list" config list
case "$out" in *pool-cap*) fail "pool-cap survived unset" ;; esac
expect 0 "config list --all" config list --all
has "$out" "drain-timeout" "config list --all"
case "$out" in *totp*) fail "the TOTP secret is listed" ;; esac
pass "setup and config validate, save, list and clear settings"

# maintenance and quiet
expect 0 "maintenance status" maintenance
has "$out" "Maintenance is off" "maintenance status"
expect 0 "maintenance on" maintenance on --force
expect 0 "maintenance status on" maintenance status
has "$out" "Maintenance is on" "maintenance status"
expect 0 "maintenance off" maintenance off -q
[ -z "$out" ] || fail "-q still printed '${out}'"
expect 0 "maintenance status off" maintenance status
has "$out" "Maintenance is off" "maintenance off"
expect 1 "maintenance bad mode" maintenance sideways
pass "maintenance toggles and -q is silent"

# helpers the shell completion scripts call
expect 0 "__complete settings" __complete settings
has "$out" "drain-timeout" "__complete settings"
expect 0 "__complete runners" __complete runners
pass "completion helpers answer"

# completion scripts parse in their shells
for shell in bash zsh fish powershell; do
  expect 0 "completion ${shell}" completion "$shell"
  has "$out" "runnerdeck" "completion ${shell}"
  printf '%s\n' "$out" >"${scratch}/completion.${shell}"
done
expect 1 "completion tcsh" completion tcsh
bash -n "${scratch}/completion.bash" || fail "bash completion does not parse"
if command -v zsh >/dev/null 2>&1; then
  zsh -n "${scratch}/completion.zsh" || fail "zsh completion does not parse"
fi
if command -v fish >/dev/null 2>&1; then
  fish --no-execute "${scratch}/completion.fish" || fail "fish completion does not parse"
fi
if command -v pwsh >/dev/null 2>&1; then
  PS_FILE="$(winpath "${scratch}/completion.powershell")" pwsh -NoProfile -Command '
    $errors = $null
    [System.Management.Automation.Language.Parser]::ParseFile($env:PS_FILE, [ref]$null, [ref]$errors) | Out-Null
    if ($errors.Count -gt 0) { $errors | ForEach-Object { Write-Error $_.Message }; exit 1 }
  ' || fail "PowerShell completion does not parse"
fi
pass "completion scripts parse in every shell installed here"

# install and uninstall (a source checkout)
bin="${scratch}/bin"
if [ "$windows" -eq 1 ]; then
  expect 1 "install on Windows" install --dir "$bin"
  has "$err" "Windows installer" "install on Windows"
else
  expect 0 "install" install --dir "$bin"
  has "$out" "Installed:" "install"
  [ -L "${bin}/runnerdeck" ] || fail "no link was created"
  linked="$("${bin}/runnerdeck" --version)"
  [ "$linked" = "RunnerDeck ${version}" ] || fail "the linked command printed '${linked}'"
  expect 0 "install again" install --dir "$bin"
  has "$out" "Already installed" "install twice"
  expect 0 "uninstall" uninstall --dir "$bin"
  [ ! -e "${bin}/runnerdeck" ] || fail "uninstall left the link"
  expect 1 "uninstall again" uninstall --dir "$bin"
fi
pass "install links the checkout's command and uninstall removes it"

# the manual page
if command -v mandoc >/dev/null 2>&1; then
  mandoc -T lint -W warning packaging/common/runnerdeck.1 || fail "the man page has lint warnings"
  pass "the man page lints clean"
elif command -v groff >/dev/null 2>&1; then
  groff -ww -man -z packaging/common/runnerdeck.1 || fail "the man page has groff warnings"
  pass "the man page lints clean"
fi

# update check against the real release feed
if command -v gh >/dev/null 2>&1 && [ -n "${GH_TOKEN:-}${GITHUB_TOKEN:-}" ]; then
  export GH_TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
  expect 0 "update --check" update --check --json
  json ".ok == true and .current == \"${version}\" and (.latest | type) == \"string\"" "update --check"
  pass "update --check reads the latest release"
fi

echo "CLI smoke test passed"
