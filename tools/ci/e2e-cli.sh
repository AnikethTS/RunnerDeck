#!/usr/bin/env bash
# Registers a real runner on the E2E test repository using only the
# `runnerdeck` command line, runs a job on it, and takes it apart again.
# Needs E2E_REPO, E2E_TAG and GH_TOKEN; see docs/development.md.
set -euo pipefail

mode="${1:-run}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repo="${E2E_REPO:?E2E_REPO is required}"
tag="${E2E_TAG:?E2E_TAG is required}"
workflow="runnerdeck-e2e.yml"

if [ "$mode" = "cleanup" ]; then
  exec "${root}/tools/ci/e2e-real-runner.sh" cleanup
fi

windows=0
case "$(uname -s)" in
  MINGW* | MSYS* | CYGWIN*) windows=1 ;;
esac

winpath() {
  if command -v cygpath >/dev/null 2>&1; then
    cygpath -m "$1"
  else
    printf '%s' "$1"
  fi
}

scratch="$(winpath "${RUNNER_TEMP:-${TMPDIR:-/tmp}}")/rd-cli-${tag}"
mkdir -p "${scratch}/pool" "${scratch}/storage/db"
export RUNNERDECK_POOL_DIR="${scratch}/pool"
export RUNNERDECK_SETTINGS_FILE="${scratch}/storage/settings.json"
export RUNNERDECK_HISTORY_FILE="${scratch}/storage/db/history.sqlite"
export RUNNER_ALLOW_RUNASROOT=1
unset RUNNERDECK_ORG RUNNERDECK_REPO RUNNERDECK_SCOPE RUNNERDECK_LABEL NO_COLOR

cd "$root"
if [ "$windows" -eq 1 ]; then
  rd=(php app/bin/runnerdeck.php)
else
  rd=("${root}/runnerdeck")
fi

id=""

dump_logs() {
  echo "::group::RunnerDeck app log"
  cat "${scratch}/storage/runnerdeck.log" 2>/dev/null || true
  echo "::endgroup::"
  for log in "${scratch}"/pool/runner-*/runner.log; do
    [ -f "$log" ] || continue
    echo "::group::${log}"
    cat "$log"
    echo "::endgroup::"
  done
}

on_exit() {
  status=$?
  trap - EXIT
  if [ "$status" -ne 0 ]; then
    dump_logs
  fi
  if [ -n "$id" ]; then
    "${rd[@]}" stop "$id" --force >/dev/null 2>&1 || true
    "${rd[@]}" delete "$id" --force >/dev/null 2>&1 || true
  fi
  "${root}/tools/ci/e2e-real-runner.sh" cleanup || true
  exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

step() { echo "==> $1"; }

# Poll until the first runner's field on `status --json` equals the wanted value.
wait_for_field() {
  local field="$1" want="$2" seconds="$3" got=""
  local deadline=$((SECONDS + seconds))
  while [ "$SECONDS" -lt "$deadline" ]; do
    got="$("${rd[@]}" status --json 2>/dev/null | jq -r ".runners[0].${field} // empty" 2>/dev/null || true)"
    if [ "$got" = "$want" ]; then
      return 0
    fi
    sleep 5
  done
  echo "runner ${field} was '${got}', wanted '${want}' after ${seconds}s" >&2
  "${rd[@]}" status >&2 || true
  return 1
}

dispatch_and_wait() {
  local run_id="" state="" conclusion="" deadline
  for _ in $(seq 1 12); do
    if gh workflow run "$workflow" -R "$repo" -f "tag=${tag}" 2>/dev/null; then
      break
    fi
    sleep 5
  done
  for _ in $(seq 1 30); do
    run_id="$(gh run list -R "$repo" --workflow "$workflow" --event workflow_dispatch -L 30 \
      --json databaseId,displayTitle --jq ".[] | select(.displayTitle == \"e2e ${tag}\") | .databaseId" | head -n 1)"
    if [ -n "$run_id" ]; then
      break
    fi
    sleep 4
  done
  [ -n "$run_id" ] || { echo "the dispatched run never showed up" >&2; return 1; }
  echo "Run ${run_id}: https://github.com/${repo}/actions/runs/${run_id}"
  deadline=$((SECONDS + 600))
  while [ "$SECONDS" -lt "$deadline" ]; do
    state="$(gh run view "$run_id" -R "$repo" --json status,conclusion --jq '.status + " " + (.conclusion // "")')"
    case "$state" in
      "completed "*)
        conclusion="${state#completed }"
        break
        ;;
    esac
    sleep 8
  done
  gh run view "$run_id" -R "$repo" --log || true
  [ "$conclusion" = "success" ] || { echo "the probe run ended '${conclusion:-timeout}'" >&2; return 1; }
}

expect_failure() {
  local label="$1"
  shift
  if "${rd[@]}" "$@" >"${scratch}/out" 2>&1; then
    echo "${label}: should have failed" >&2
    cat "${scratch}/out" >&2
    return 1
  fi
}

step "Check the token and the probe workflow"
gh api "repos/${repo}" --jq '"repo " + .full_name' >/dev/null || { echo "token cannot read ${repo}" >&2; exit 1; }
gh api "repos/${repo}/contents/.github/workflows/${workflow}" >/dev/null || {
  echo "copy tools/ci/e2e-probe-workflow.yml to .github/workflows/${workflow} in ${repo}" >&2
  exit 1
}

step "setup, config and auth"
"${rd[@]}" setup --repo "$repo"
"${rd[@]}" config set label "$tag"
[ "$("${rd[@]}" config get label)" = "$tag" ]
[ "$("${rd[@]}" config get repo)" = "$repo" ]
"${rd[@]}" auth status

step "add a runner"
"${rd[@]}" add
id="$("${rd[@]}" list --json | jq -r '.runners[0].id')"
[ -n "$id" ] && [ "$id" != "null" ]
wait_for_field github idle 420
[ "$("${rd[@]}" status --json | jq -r '.runners[0].local_running')" = "true" ]

step "stop and start"
"${rd[@]}" stop "$id"
wait_for_field local_running false 60
wait_for_field github offline 180
"${rd[@]}" start "$id"
wait_for_field github idle 300

step "run a job on it"
dispatch_and_wait
[ -n "$("${rd[@]}" logs "$id" -n 20)" ]

step "rolling-restart"
"${rd[@]}" rolling-restart "$id" --timeout 120
wait_for_field github idle 300

step "rename"
"${rd[@]}" rename "$id" "${tag}-renamed"
wait_for_field github idle 300
[ "$("${rd[@]}" list --json | jq -r '.runners[0].agent_name')" = "${tag}-renamed" ]

step "maintenance blocks starts, then lifts"
"${rd[@]}" maintenance on --timeout 120
wait_for_field local_running false 60
expect_failure "start during maintenance" start "$id"
"${rd[@]}" maintenance off
"${rd[@]}" start "$id"
wait_for_field github idle 300

step "drain"
"${rd[@]}" drain "$id" --timeout 120
wait_for_field local_running false 60

step "delete"
"${rd[@]}" delete "$id"
id=""
[ "$("${rd[@]}" list --json | jq -r '.runners | length')" = "0" ]
if gh api "repos/${repo}/actions/runners" --jq '.runners[].name' | grep -q "^${tag}"; then
  echo "the runner is still registered on GitHub after delete" >&2
  exit 1
fi

echo "The command line drove a real runner through its whole life on this OS"
