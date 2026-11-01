#!/usr/bin/env bash
set -euo pipefail

mode="${1:-run}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repo="${E2E_REPO:?E2E_REPO is required}"
tag="${E2E_TAG:?E2E_TAG is required}"
port="${E2E_PORT:-8090}"
base="http://127.0.0.1:${port}"
workflow="runnerdeck-e2e.yml"

winpath() {
  if command -v cygpath >/dev/null 2>&1; then
    cygpath -m "$1"
  else
    printf '%s' "$1"
  fi
}

scratch="$(winpath "${RUNNER_TEMP:-${TMPDIR:-/tmp}}")/rd-e2e-${tag}"

cleanup_github() {
  gh api "repos/${repo}/actions/runners" --paginate --jq '.runners[] | "\(.id) \(.name)"' 2>/dev/null |
    while read -r id name; do
      case "$name" in
        "$tag"*) gh api -X DELETE "repos/${repo}/actions/runners/${id}" >/dev/null 2>&1 || true ;;
      esac
    done
  gh run list -R "$repo" --workflow "$workflow" -L 30 --json databaseId,displayTitle,status \
    --jq ".[] | select(.displayTitle == \"e2e ${tag}\" and .status != \"completed\") | .databaseId" 2>/dev/null |
    while read -r run_id; do
      gh run cancel "$run_id" -R "$repo" >/dev/null 2>&1 || true
    done
}

if [ "$mode" = "cleanup" ]; then
  cleanup_github
  exit 0
fi

server_pid=""
jar="$(mktemp)"

stop_server() {
  if [ -n "$server_pid" ]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    server_pid=""
  fi
  case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*) taskkill //F //IM php.exe >/dev/null 2>&1 || true ;;
  esac
}

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
  if [ -n "${token:-}" ]; then
    curl -fsS -b "$jar" -X POST "${base}/api.php?action=bulk_delete" -H "X-CSRF-Token: ${token}" \
      --data-urlencode "runners=runner-base,runner-1,runner-2" >/dev/null 2>&1 || true
  fi
  stop_server
  cleanup_github
  rm -f "$jar"
  exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

mkdir -p "${scratch}/pool" "${scratch}/storage/db"
export RUNNERDECK_SCOPE=repo
export RUNNERDECK_REPO="$repo"
export RUNNERDECK_LABEL="$tag"
export RUNNERDECK_POOL_DIR="${scratch}/pool"
export RUNNERDECK_SETTINGS_FILE="${scratch}/storage/settings.json"
export RUNNERDECK_HISTORY_FILE="${scratch}/storage/db/history.sqlite"
export RUNNER_ALLOW_RUNASROOT=1

cd "$root"
php -d max_execution_time=0 -S "127.0.0.1:${port}" -t public >"${scratch}/server.log" 2>&1 &
server_pid=$!

ready=0
for _ in $(seq 1 40); do
  if curl -fsS "${base}/health.php" -o /dev/null 2>/dev/null; then
    ready=1
    break
  fi
  sleep 0.5
done
if [ "$ready" -ne 1 ]; then
  echo "dashboard never became healthy" >&2
  cat "${scratch}/server.log" >&2 || true
  exit 1
fi

token="$(curl -fsS -c "$jar" -b "$jar" "${base}/api.php?action=csrf_token" |
  php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"] ?? "";')"
if [ -z "$token" ]; then
  echo "no CSRF token" >&2
  exit 1
fi

echo "Publishing probe workflow to ${repo}"
probe="${root}/scripts/ci/e2e-probe-workflow.yml"
path=".github/workflows/${workflow}"
encoded="$(base64 <"$probe" | tr -d '\n')"
remote_sha=""
remote_content=""
if existing="$(gh api "repos/${repo}/contents/${path}" 2>/dev/null)"; then
  remote_sha="$(php -r 'echo json_decode($argv[1], true)["sha"] ?? "";' -- "$existing")"
  remote_content="$(php -r 'echo str_replace("\n", "", json_decode($argv[1], true)["content"] ?? "");' -- "$existing")"
fi
if [ "$remote_content" != "$encoded" ]; then
  args=(-X PUT "repos/${repo}/contents/${path}" -f message="Update e2e probe workflow" -f content="$encoded")
  if [ -n "$remote_sha" ]; then
    args+=(-f sha="$remote_sha")
  fi
  gh api "${args[@]}" >/dev/null
fi

echo "Adding an ephemeral runner through the dashboard API"
response="$(curl -sS -b "$jar" -X POST "${base}/api.php?action=add_runner" \
  -H "X-CSRF-Token: ${token}" --data-urlencode "ephemeral=1")"
echo "$response"
php -r 'exit((json_decode($argv[1], true)["ok"] ?? false) === true ? 0 : 1);' -- "$response"

echo "Waiting for the runner to appear online on GitHub"
online=0
for _ in $(seq 1 30); do
  count="$(gh api "repos/${repo}/actions/runners" --jq "[.runners[] | select(.status == \"online\" and (.name | startswith(\"${tag}\")))] | length" 2>/dev/null || echo 0)"
  if [ "$count" -ge 1 ]; then
    online=1
    break
  fi
  sleep 5
done
if [ "$online" -ne 1 ]; then
  echo "runner never came online" >&2
  exit 1
fi

echo "Dispatching the probe workflow"
dispatched=0
for _ in $(seq 1 12); do
  if gh workflow run "$workflow" -R "$repo" -f "tag=${tag}" 2>/dev/null; then
    dispatched=1
    break
  fi
  sleep 5
done
if [ "$dispatched" -ne 1 ]; then
  echo "could not dispatch ${workflow} on ${repo}" >&2
  exit 1
fi

run_id=""
for _ in $(seq 1 30); do
  run_id="$(gh run list -R "$repo" --workflow "$workflow" --event workflow_dispatch -L 30 \
    --json databaseId,displayTitle --jq ".[] | select(.displayTitle == \"e2e ${tag}\") | .databaseId" | head -n 1)"
  if [ -n "$run_id" ]; then
    break
  fi
  sleep 4
done
if [ -z "$run_id" ]; then
  echo "dispatched run never showed up" >&2
  exit 1
fi
echo "Run ${run_id}: https://github.com/${repo}/actions/runs/${run_id}"

deadline=$((SECONDS + 600))
conclusion=""
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

if [ "$conclusion" != "success" ]; then
  echo "probe run finished with conclusion '${conclusion:-timeout}'" >&2
  exit 1
fi
echo "Real runner executed the job on this OS"
