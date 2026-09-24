FROM php:8.5-cli-alpine@sha256:dae77e6aa4934d22b903da93e0e506c34032f5d8f8f91693d2cbf6e2724ddf73

RUN apk add --no-cache bash curl tar sqlite-dev git \
        icu-libs krb5-libs libgcc libstdc++ libintl openssl lttng-ust zlib \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pcntl posix pdo_sqlite \
    && apk del .build-deps

COPY bin/install-gh.sh /tmp/install-gh.sh
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
