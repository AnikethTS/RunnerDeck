#!/bin/sh
# chown the data volume, then run the dashboard once.
# Compose leaves restart off so a missing TOTP enrollment stays stopped
# after this process prints the setup command and exits.
set -eu

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

cd /app
exec "$@"
