#!/usr/bin/env bash
# Print a systemd unit or launchd agent for this checkout. --install writes it.
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: bin/install-service.sh [--port PORT] [--install]

Print a service definition that starts this checkout's run.sh after boot
(Linux systemd) or at login (macOS launchd). Paths come from this checkout
and the current user. Nothing is written unless you pass --install.
EOF
}

xml_escape() {
    local s=$1
    s=${s//&/\&amp;}
    s=${s//</\&lt;}
    s=${s//>/\&gt;}
    printf '%s' "$s"
}

linux_unit() {
    local user=$1 group=$2
    cat <<EOF
[Unit]
Description=RunnerDeck (self-hosted GitHub Actions runner pool UI)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${user}
Group=${group}
WorkingDirectory=${ROOT}
ExecStart=${ROOT}/run.sh ${PORT}
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
}

linux_print() {
    local user group
    user="$(id -un)"
    group="$(id -gn)"
    echo "Save this as /etc/systemd/system/runnerdeck.service (it starts at boot as ${user}):"
    echo
    linux_unit "$user" "$group"
    cat <<EOF

Then run:
  sudo systemctl daemon-reload
  sudo systemctl enable --now runnerdeck.service

Or: bin/install-service.sh --install
Stop it with: sudo systemctl disable --now runnerdeck.service
EOF
}

linux_install() {
    local user group dest tmp
    command -v systemctl >/dev/null 2>&1 || { echo "systemctl not found" >&2; exit 1; }
    user="$(id -un)"
    group="$(id -gn)"
    dest="/etc/systemd/system/runnerdeck.service"
    tmp="$(mktemp)"
    linux_unit "$user" "$group" >"$tmp"
    if [[ "$(id -u)" -eq 0 ]]; then
        install -m 0644 "$tmp" "$dest"
        systemctl daemon-reload
        systemctl enable --now runnerdeck.service
    else
        command -v sudo >/dev/null 2>&1 || { echo "sudo is required to write ${dest}" >&2; rm -f "$tmp"; exit 1; }
        sudo install -m 0644 "$tmp" "$dest"
        sudo systemctl daemon-reload
        sudo systemctl enable --now runnerdeck.service
    fi
    rm -f "$tmp"
    echo "Installed ${dest} and started runnerdeck.service (http://127.0.0.1:${PORT}/)."
}

darwin_plist() {
    local root port out err
    root="$(xml_escape "$ROOT")"
    port="$(xml_escape "$PORT")"
    out="$(xml_escape "${HOME}/Library/Logs/runnerdeck.out.log")"
    err="$(xml_escape "${HOME}/Library/Logs/runnerdeck.err.log")"
    cat <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.runnerdeck</string>
    <key>ProgramArguments</key>
    <array>
        <string>${root}/run.sh</string>
        <string>${port}</string>
    </array>
    <key>WorkingDirectory</key>
    <string>${root}</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>${out}</string>
    <key>StandardErrorPath</key>
    <string>${err}</string>
</dict>
</plist>
EOF
}

darwin_print() {
    echo "Save this as ~/Library/LaunchAgents/com.runnerdeck.plist (it starts at login):"
    echo
    darwin_plist
    cat <<EOF

Then run:
  mkdir -p ~/Library/LaunchAgents ~/Library/Logs
  launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.runnerdeck.plist

Or: bin/install-service.sh --install
Stop it with: launchctl bootout gui/$(id -u)/com.runnerdeck
A login agent does not run before the first login after a reboot.
EOF
}

darwin_install() {
    local dest uid
    uid="$(id -u)"
    dest="${HOME}/Library/LaunchAgents/com.runnerdeck.plist"
    mkdir -p "${HOME}/Library/LaunchAgents" "${HOME}/Library/Logs"
    darwin_plist >"$dest"
    chmod 0644 "$dest"
    launchctl bootout "gui/${uid}/com.runnerdeck" 2>/dev/null || true
    launchctl bootstrap "gui/${uid}" "$dest"
    echo "Installed ${dest} and started com.runnerdeck (http://127.0.0.1:${PORT}/)."
}

run_install_service() {
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    PORT="${RUNNERDECK_PORT:-8090}"
    INSTALL=0

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --install) INSTALL=1 ;;
            --port)
                shift
                [[ $# -gt 0 ]] || { echo "missing value for --port" >&2; exit 2; }
                PORT="$1"
                ;;
            -h|--help) usage; exit 0 ;;
            *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
        esac
        shift
    done

    if [[ ! "$PORT" =~ ^[0-9]+$ ]] || [[ "$PORT" -lt 1 || "$PORT" -gt 65535 ]]; then
        echo "port must be 1-65535, got: ${PORT}" >&2
        exit 2
    fi

    case "${RUNNERDECK_SERVICE_OS:-$(uname -s)}" in
        Linux)
            if [[ "$INSTALL" -eq 1 ]]; then linux_install; else linux_print; fi
            ;;
        Darwin)
            if [[ "$INSTALL" -eq 1 ]]; then darwin_install; else darwin_print; fi
            ;;
        *)
            echo "This script covers Linux systemd and macOS launchd. On Windows the installer can start RunnerDeck at sign-in." >&2
            exit 2
            ;;
    esac
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    run_install_service "$@"
fi
