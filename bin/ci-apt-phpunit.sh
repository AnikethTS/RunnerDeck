#!/usr/bin/env bash
# Distro-package PHP + Composer + PHPUnit. Used by the apt (Ubuntu/Debian)
# and dnf (Fedora / Rocky / RHEL UBI) container jobs.
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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
