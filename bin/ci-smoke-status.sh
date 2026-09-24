#!/usr/bin/env bash
# Wait for action=status and check the snapshot shape. Used by host/Alpine/Docker CI smokes.
set -euo pipefail

url="${1:-http://127.0.0.1:8090/api.php?action=status}"
out="${2:-response.json}"

ok=0
for _ in $(seq 1 20); do
  if curl -fsS "$url" -o "$out"; then
    ok=1
    break
  fi
  sleep 0.5
done

if [ "$ok" -ne 1 ]; then
  echo "smoke: never got a response from ${url}" >&2
  exit 1
fi

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
