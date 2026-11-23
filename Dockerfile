FROM golang:1.27.4-alpine@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 AS gh
RUN apk add --no-cache git curl ca-certificates
COPY tools/ci/build-gh.sh /tmp/build-gh.sh
RUN sh /tmp/build-gh.sh /out/gh

# Debian, not Alpine. GitHub's runner is a glibc .NET program: config.sh
# fails on Alpine looking for ld-linux-x86-64.so.2 and libicu.
FROM php:8.6.0-cli-bookworm@sha256:519fe569bdde71e366206a99e1996519fc12897f6fba1cd70b9815ce18f7639c

RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade linux-libc-dev \
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
RUN groupadd --system --gid 1000 runnerdeck \
    && useradd --system --uid 1000 --gid runnerdeck --create-home \
        --home-dir /home/runnerdeck --shell /usr/sbin/nologin runnerdeck \
    && mkdir -p /data/runners /data/storage/db /home/runnerdeck/.config \
    && chown -R runnerdeck:runnerdeck /data /home/runnerdeck \
    && chmod 0755 /app/docker/entrypoint.sh

ENV HOME=/home/runnerdeck
ENV RUNNERDECK_BIND_HOST=0.0.0.0
USER runnerdeck

EXPOSE 8090
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["./run.sh"]
