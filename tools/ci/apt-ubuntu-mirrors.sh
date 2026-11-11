#!/bin/sh
# Refresh apt indexes. GitHub-hosted runners sometimes cannot resolve
# archive.ubuntu.com, so those sources are pointed at a mirror that can.
set -eu

if [ "$(id -u)" -ne 0 ]; then
    if [ -x /usr/bin/sudo ]; then
        exec sudo -n sh "$0" "$@"
    fi
    echo "apt: root is required to refresh package indexes" >&2
    exit 1
fi

fallback_host=mirrors.edge.kernel.org
fallback_url=http://mirrors.edge.kernel.org/ubuntu
fallback_ok=0
switched=0

if getent hosts "$fallback_host" >/dev/null 2>&1; then
    fallback_ok=1
fi

rewrite_ubuntu_mirrors() {
    for file in /etc/apt/sources.list /etc/apt/sources.list.d/*; do
        if [ ! -f "$file" ]; then
            continue
        fi
        sed -i \
            -e "s|https\\?://azure.archive.ubuntu.com/ubuntu|${fallback_url}|g" \
            -e "s|https\\?://archive.ubuntu.com/ubuntu|${fallback_url}|g" \
            -e "s|https\\?://security.ubuntu.com/ubuntu|${fallback_url}|g" \
            "$file"
    done
}

host_in_sources() {
    host=$1
    for file in /etc/apt/sources.list /etc/apt/sources.list.d/*; do
        if [ -f "$file" ] && grep -q "$host" "$file"; then
            return 0
        fi
    done
    return 1
}

index_failed() {
    printf '%s\n' "$1" | grep -F -q 'Failed to fetch' && return 0
    printf '%s\n' "$1" | grep -F -q 'Could not resolve' && return 0
    printf '%s\n' "$1" | grep -F -q 'Temporary failure resolving' && return 0
    return 1
}

for host in archive.ubuntu.com azure.archive.ubuntu.com security.ubuntu.com; do
    if host_in_sources "$host" && ! getent hosts "$host" >/dev/null 2>&1; then
        if [ "$fallback_ok" -ne 1 ]; then
            echo "apt: ${host} does not resolve and ${fallback_host} does not either" >&2
            exit 1
        fi
        if [ "$switched" -eq 0 ]; then
            echo "apt: Ubuntu archives do not resolve; using ${fallback_url}"
            rewrite_ubuntu_mirrors
            switched=1
        fi
    fi
done

attempt=1
while [ "$attempt" -le 5 ]; do
    log=$(apt-get update 2>&1) && code=0 || code=$?
    printf '%s\n' "$log"
    if [ "$code" -eq 0 ] && ! index_failed "$log"; then
        exit 0
    fi
    if [ "$switched" -eq 0 ] && [ "$fallback_ok" -eq 1 ] && printf '%s\n' "$log" | grep -q 'ubuntu.com'; then
        echo "apt: Ubuntu archives failed; using ${fallback_url}"
        rewrite_ubuntu_mirrors
        switched=1
    fi
    if [ "$attempt" -eq 5 ]; then
        echo "apt: package indexes could not be downloaded" >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 15
done
