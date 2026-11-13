FROM golang:1.27.3-alpine@sha256:fa3d596898d9f126af7c01d14ee7597bab3f39cccf392ef36b69fe77cd681f18 AS gh
RUN apk add --no-cache git curl ca-certificates
COPY tools/ci/build-gh.sh /tmp/build-gh.sh
RUN sh /tmp/build-gh.sh /out/gh

# Debian, not Alpine. GitHub's runner is a glibc .NET program: config.sh
# fails on Alpine looking for ld-linux-x86-64.so.2 and libicu.
FROM php:8.5.12-cli-bookworm@sha256:d3a60f0f036cc0a408f0a5c63f58bf68557180a8fb5d2d8049d222139054d810

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash ca-certificates curl tar git \
        libicu72 libkrb5-3 libssl3 zlib1g libsqlite3-0 \
        libsqlite3-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install pcntl posix pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=gh /out/gh /usr/local/bin/gh

WORKDIR /app
COPY . .

ENV RUNNERDECK_POOL_DIR=/data/runners
ENV RUNNERDECK_SETTINGS_FILE=/data/storage/settings.json
ENV RUNNERDECK_HISTORY_FILE=/data/storage/db/history.sqlite
RUN mkdir -p /data/runners /data/storage/db \
    && chmod 0755 /app/docker/entrypoint.sh

ENV RUNNERDECK_BIND_HOST=0.0.0.0
# GitHub's config.sh refuses UID 0 without this.
ENV RUNNER_ALLOW_RUNASROOT=1

EXPOSE 8090
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["./run.sh"]
