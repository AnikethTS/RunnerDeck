#!/bin/sh
# chown the data volume, then run the dashboard once.
# Compose leaves restart off so a missing TOTP enrollment stays stopped
# after this process prints the setup command and exits.
set -eu

# Only a root entrypoint can chown a host mount. The image defaults to
# uid 1000, which cannot, so the host directory must already be writable
# by that user. Override the user back to root if you still want this.
if [ "$(id -u)" -eq 0 ]; then
    uid="${RUNNERDECK_DATA_UID:-}"
    gid="${RUNNERDECK_DATA_GID:-$uid}"
    case "$uid" in
        ''|*[!0-9]*)
            ;;
        *)
            case "$gid" in
                ''|*[!0-9]*)
                    gid="$uid"
                    ;;
            esac
            if [ -d /data ]; then
                chown -R "${uid}:${gid}" /data
            fi
            ;;
    esac
fi

cd /app
exec "$@"
