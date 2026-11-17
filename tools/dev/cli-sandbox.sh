#!/usr/bin/env bash
# Opens a shell where `runnerdeck` works on a throwaway pool and settings, so
# you can try the command line without touching your real runners.
#   tools/dev/cli-sandbox.sh            # temp folder, removed on exit
#   tools/dev/cli-sandbox.sh ~/rd-test  # keep the sandbox in this folder
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SANDBOX="${1:-$(mktemp -d "${TMPDIR:-/tmp}/runnerdeck-sandbox.XXXXXX")}"
mkdir -p "$SANDBOX/db" "$SANDBOX/runners" "$SANDBOX/bin"
ln -sf "$ROOT/runnerdeck" "$SANDBOX/bin/runnerdeck"

export RUNNERDECK_SETTINGS_FILE="$SANDBOX/settings.json"
export RUNNERDECK_HISTORY_FILE="$SANDBOX/db/history.sqlite"
export RUNNERDECK_POOL_DIR="$SANDBOX/runners"
export PATH="$SANDBOX/bin:$PATH"
unset RUNNERDECK_ORG RUNNERDECK_REPO RUNNERDECK_SCOPE

cat <<MSG
RunnerDeck CLI sandbox: $SANDBOX
  settings and runners live there; your real pool is untouched.
  Try: runnerdeck setup --repo <owner>/<a-throwaway-repo>
       runnerdeck add --ephemeral
  Type exit to leave.
MSG

bash --rcfile <(echo 'PS1="(rd-sandbox) \w \$ "') -i || true

case "$SANDBOX" in
    "${TMPDIR:-/tmp}"/runnerdeck-sandbox.*)
        runnerdeck stop --all --force >/dev/null 2>&1 || true
        rm -rf "$SANDBOX"
        echo "Removed $SANDBOX"
        ;;
    *) echo "Kept $SANDBOX" ;;
esac
