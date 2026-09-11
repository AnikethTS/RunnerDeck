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

echo "RunnerDeck on http://127.0.0.1:${PORT} (localhost only)"
php -r '
    $dir = $argv[1];
    $file = getenv("RUNNERDECK_HISTORY_FILE") ?: "$dir/storage/db/history.sqlite";
    $parent = dirname($file);
    $writable = is_dir($parent) ? is_writable($parent) : is_writable(dirname($parent));
    $ext = extension_loaded("pdo_sqlite") ? "pdo_sqlite available" : "pdo_sqlite MISSING, history chart disabled";
    $state = is_file($file) ? "existing" : "not yet created";
    fwrite(STDOUT, "History DB: {$file} ({$ext}, {$state}, " . ($writable ? "writable" : "NOT WRITABLE") . ")\n");
' -- "$DIR"
exec php -S 127.0.0.1:"${PORT}" -t "$DIR/public"
