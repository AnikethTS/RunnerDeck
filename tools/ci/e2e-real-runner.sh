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
  set +o pipefail
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
  gh run list -R "$repo" --workflow "$workflow" --status completed -L 100 --json databaseId,createdAt \
    --jq '.[] | select((.createdAt | fromdateiso8601) < (now - 900)) | .databaseId' 2>/dev/null |
    while read -r old_id; do
      gh run delete "$old_id" -R "$repo" >/dev/null 2>&1 || true
    done
}

preflight() {
  if ! gh api "repos/${repo}" --jq '"repo " + .full_name + " private=" + (.private|tostring)'; then
    echo "token cannot read ${repo}" >&2
    return 1
  fi
  gh api "repos/${repo}/actions/runners" --jq '"runners visible: " + (.total_count|tostring)' ||
    { echo "token cannot list runners on ${repo} (needs Administration)" >&2; return 1; }
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

preflight

mkdir -p "${scratch}/pool" "${scratch}/storage/db"
export RUNNERDECK_SCOPE=repo
export RUNNERDECK_REPO="$repo"
export RUNNERDECK_LABEL="$tag"
export RUNNERDECK_POOL_DIR="${scratch}/pool"
export RUNNERDECK_SETTINGS_FILE="${scratch}/storage/settings.json"
export RUNNERDECK_HISTORY_FILE="${scratch}/storage/db/history.sqlite"
export RUNNER_ALLOW_RUNASROOT=1

cd "$root"
php -d max_execution_time=0 -S "127.0.0.1:${port}" -t app/public >"${scratch}/server.log" 2>&1 &
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

csrf_body="$(curl -fsS -c "$jar" -b "$jar" "${base}/api.php?action=csrf_token")"
token="$(CSRF_BODY="$csrf_body" php -r 'echo json_decode(getenv("CSRF_BODY"), true)["token"] ?? "";')"
if [ -z "$token" ]; then
  echo "no CSRF token" >&2
  exit 1
fi

probe_present=0
for _ in 1 2 3 4 5; do
  if probe_out="$(gh api "repos/${repo}/contents/.github/workflows/${workflow}" 2>&1)"; then
    probe_present=1
    break
  fi
  case "$probe_out" in
    *"Not Found"* | *"404"*) break ;;
  esac
  sleep 6
done
if [ "$probe_present" -ne 1 ]; then
  echo "could not read .github/workflows/${workflow} in ${repo}: ${probe_out}" >&2
  echo "copy tools/ci/e2e-probe-workflow.yml there if it is missing" >&2
  exit 1
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
