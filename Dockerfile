FROM php:8.6.0RC4-cli-alpine@sha256:c9c90988b028655d7601a8d780ab5e068abd1c5f430cc0ee642755b94dd46583

RUN apk add --no-cache bash curl tar sqlite-dev git \
        icu-libs krb5-libs libgcc libstdc++ libintl openssl lttng-ust zlib \
    && apk upgrade --no-cache brotli-libs curl libcurl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pcntl posix pdo_sqlite \
    && apk del .build-deps

COPY scripts/ci/install-gh.sh /tmp/install-gh.sh
RUN sh /tmp/install-gh.sh && rm /tmp/install-gh.sh

WORKDIR /app
COPY . .

ENV RUNNERDECK_POOL_DIR=/data/runners
ENV RUNNERDECK_SETTINGS_FILE=/data/storage/settings.json
ENV RUNNERDECK_HISTORY_FILE=/data/storage/db/history.sqlite
RUN mkdir -p /data/runners /data/storage/db

ENV RUNNERDECK_BIND_HOST=0.0.0.0
# GitHub's config.sh refuses UID 0 without this.
ENV RUNNER_ALLOW_RUNASROOT=1

EXPOSE 8090
CMD ["./run.sh"]
