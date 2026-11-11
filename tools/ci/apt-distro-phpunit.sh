#!/bin/sh
# Run the unit suite on the distro PHP inside an apt-based container.
set -eu

export DEBIAN_FRONTEND=noninteractive
sh "$(dirname "$0")/apt-ubuntu-mirrors.sh"
apt-get install -y --no-install-recommends \
    ca-certificates curl git unzip \
    php-cli php-xml php-mbstring php-sqlite3 php-zip php-curl
php -v
php -r 'exit((extension_loaded("posix") && extension_loaded("pcntl")) ? 0 : 1);'
bash "$(dirname "$0")/ci-apt-phpunit.sh"
