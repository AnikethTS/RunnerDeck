FROM php:8.5-cli-alpine@sha256:dae77e6aa4934d22b903da93e0e506c34032f5d8f8f91693d2cbf6e2724ddf73

RUN apk add --no-cache bash curl tar sqlite-dev git \
        icu-libs krb5-libs libgcc libstdc++ libintl openssl lttng-ust zlib \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pcntl posix pdo_sqlite \
    && apk del .build-deps

RUN GH_VERSION=2.100.0 \
    && GH_SHA256=e4d4bb4498e8d007abe545b6568926793ace1b6447da598294a610018cb164be \
    && curl -fsSL -o /tmp/gh.tar.gz \
       "https://github.com/cli/cli/releases/download/v${GH_VERSION}/gh_${GH_VERSION}_linux_amd64.tar.gz" \
    && echo "${GH_SHA256}  /tmp/gh.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/gh.tar.gz -C /tmp \
    && mv "/tmp/gh_${GH_VERSION}_linux_amd64/bin/gh" /usr/local/bin/gh \
    && rm -rf /tmp/gh.tar.gz "/tmp/gh_${GH_VERSION}_linux_amd64"

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
