#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

php -v
php -r 'exit((extension_loaded("posix") && extension_loaded("pcntl")) ? 0 : 1);'

curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --install-dir=/tmp --filename=composer
php /tmp/composer install --prefer-dist --no-progress --no-scripts
php /tmp/composer run test
