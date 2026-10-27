#!/usr/bin/env bash
# Distro-package PHP (apt) + Composer + PHPUnit. Used by the Ubuntu 22.04 /
# Debian 12 container jobs and the WSL2 Ubuntu 22.04 job.
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# WSL checkout lives on /mnt/d (9P). Composer extract + PHPUnit from there
# takes many minutes; copy onto the Linux disk first.
if [[ "$root" == /mnt/* ]]; then
    dest=/tmp/runnerdeck-ci
    rm -rf "$dest"
    mkdir -p "$dest"
    tar -C "$root" --exclude='./.git' --exclude='./vendor' --exclude='./node_modules' -cf - . \
        | tar -C "$dest" -xf -
    root="$dest"
fi
cd "$root"

php -v
php -r 'exit((extension_loaded("posix") && extension_loaded("pcntl")) ? 0 : 1);'

curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --install-dir=/tmp --filename=composer
# post-install-cmd sets core.hooksPath; skip it in CI. Container checkouts
# are often owned by a different uid than the job user, so git config
# fails with "dubious ownership" (exit 128).
php /tmp/composer install --prefer-dist --no-progress --no-scripts
php /tmp/composer run test
