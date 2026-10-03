#!/usr/bin/env bash
# Wait for the app and check action=status snapshot shape.
# Host/Alpine smokes use loopback php -S (no TOTP). Docker binds 0.0.0.0
# inside the container and requires TOTP — set RUNNERDECK_SMOKE_TOTP_SECRET
# to the same value as RUNNERDECK_AUTH_TOTP_SECRET and this script logs in.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
base="${SMOKE_BASE:-http://127.0.0.1:8090}"
url="${1:-${base}/api.php?action=status}"
out="${2:-response.json}"
jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT

ok=0
for _ in $(seq 1 20); do
  if curl -fsS "${base}/health.php" -o /dev/null; then
    ok=1
    break
  fi
  sleep 0.5
done

if [ "$ok" -ne 1 ]; then
  echo "smoke: never got a response from ${base}/health.php" >&2
  exit 1
fi

if [ -n "${RUNNERDECK_SMOKE_TOTP_SECRET:-}" ]; then
  csrf_json="$(curl -fsS -c "$jar" -b "$jar" "${base}/api.php?action=csrf_token")"
  token="$(php -r 'echo json_decode($argv[1], true)["token"] ?? "";' -- "$csrf_json")"
  if [ -z "$token" ]; then
    echo "smoke: csrf_token missing" >&2
    echo "$csrf_json" >&2
    exit 1
  fi
  code="$(php -r '
    require $argv[1] . "/src/bootstrap.php";
    echo \RunnerDeck\Totp::code($argv[2]);
  ' -- "$root" "$RUNNERDECK_SMOKE_TOTP_SECRET")"
  login="$(curl -fsS -c "$jar" -b "$jar" -X POST "${base}/api.php?action=login" \
    -H "X-CSRF-Token: ${token}" --data-urlencode "code=${code}")"
  php -r '
    $d = json_decode($argv[1], true);
    if (!is_array($d) || empty($d["ok"])) {
        fwrite(STDERR, "smoke: login failed\n");
        exit(1);
    }
  ' -- "$login"
fi

curl_args=(-fsS)
if [ -s "$jar" ]; then
  curl_args+=(-c "$jar" -b "$jar")
fi
curl "${curl_args[@]}" "$url" -o "$out"

echo "--- response ---"
cat "$out"
echo

php -r '
$data = json_decode(file_get_contents($argv[1]), true);
if (!is_array($data) || !array_key_exists("health", $data) || !array_key_exists("runners", $data)) {
    fwrite(STDERR, "unexpected response shape\n");
    exit(1);
}
echo "smoke test OK\n";
' "$out"
