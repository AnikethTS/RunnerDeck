#!/usr/bin/env bash
# Binds to 127.0.0.1 only — never expose this beyond localhost.
set -euo pipefail

PORT="${1:-8090}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v php >/dev/null 2>&1; then
    if command -v flatpak-spawn >/dev/null 2>&1; then
        exec flatpak-spawn --host "${BASH_SOURCE[0]}" "${PORT}"
    fi
    echo "php not found, and no flatpak-spawn to reach the host. Install php-cli." >&2
    exit 1
fi

export PHP_CLI_SERVER_WORKERS=4

echo "Runner Dashboard on http://127.0.0.1:${PORT} (localhost only)"
exec php -S 127.0.0.1:"${PORT}" -t "$DIR/public"
