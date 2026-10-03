# Hosting (TLS reverse proxy)

RunnerDeck is still a **single-user operator UI** for runners on **this
machine**. “Cloud hosting” here means: a VPS or home server where Caddy,
nginx, or Traefik terminates TLS and PHP serves `public/` on loopback.
It is not multi-tenant SaaS, not a load-balanced farm, and it is not a
substitute for `gh` + runner binaries on that same host.

## Required together

1. **TOTP login** — Settings → Login, or `php bin/setup-totp.php`.
2. **TLS on the public hostname** — the proxy holds the certificate.
   RunnerDeck itself stays plain HTTP.
3. **Do not publish PHP on the internet.** Keep php-fpm on
   `127.0.0.1:9000` or `./run.sh` on `127.0.0.1:8090`. Docker’s compose
   file similarly maps `127.0.0.1:8090:8090` unless you change it.

Skipping login or TLS while the UI is reachable beyond loopback means
anyone who can hit the port can start and stop runners.

## Built-in server (`./run.sh`)

`php -S` is PHP’s development server. `run.sh` starts it with four
workers (`PHP_CLI_SERVER_WORKERS`, overridable). A live log is a
Server-Sent Events stream that holds one of those workers for about
30 minutes (`public/log_stream.php`). There is no php-fpm process
isolation. That is fine for **one operator on this machine**. It is
not a load-balanced service.

For a TLS hostname, use php-fpm below. Proxying `php -S` still works
(see [Proxying the built-in server](#proxying-the-built-in-server))
and keeps the four-worker limit.

## php-fpm (TLS hostname)

php-fpm runs as **the same account that owns `gh` auth and the runner
pool** — not `www-data`. The auto-restart loop is a separate process;
php-fpm does not start it.

```
Internet --443/TLS--> Caddy or nginx  -->  127.0.0.1:9000  php-fpm
                                         php bin/watch-auto-restart.php
```

1. Install `php-fpm` (Fedora: `dnf install php-fpm`; Debian/Ubuntu:
   `apt install php-fpm`).
2. Edit paths in [deploy/php-fpm.conf](../deploy/php-fpm.conf),
   [deploy/runnerdeck-php-fpm.service](../deploy/runnerdeck-php-fpm.service),
   and [deploy/runnerdeck-watch.service](../deploy/runnerdeck-watch.service).
   Point `ExecStart` at your `php-fpm` binary (`/usr/sbin/php-fpm` or
   `/usr/sbin/php-fpm8.x`).
3. `systemctl --user enable --now runnerdeck-php-fpm runnerdeck-watch`
   and `loginctl enable-linger "$USER"`.
4. Point Caddy or nginx at `public/` and FastCGI `127.0.0.1:9000`:
   - [deploy/Caddyfile.php-fpm](../deploy/Caddyfile.php-fpm)
   - [deploy/nginx-php-fpm.conf.example](../deploy/nginx-php-fpm.conf.example)

The pool is `ondemand` with 16 children. A live log still occupies one
worker for up to 30 minutes; `max_execution_time` and
`request_terminate_timeout` are 2100 seconds so php-fpm does not kill
that stream at the default 30-second PHP limit. `health.php` is an
uptime probe, not a signal that you should put this behind a pool of
frontends.

**Session cookies:** `Secure` is set when the request is HTTPS,
including `X-Forwarded-Proto: https` from a proxy whose `REMOTE_ADDR`
is loopback (`127.0.0.1` / `::1`).

## Proxying the built-in server

```
Internet --443/TLS--> Caddy or nginx  -->  127.0.0.1:8090  php ./run.sh
```

Examples (same four-worker / SSE limits as local `./run.sh`):

- [deploy/Caddyfile](../deploy/Caddyfile) — automatic HTTPS
- [deploy/nginx.conf.example](../deploy/nginx.conf.example) — bring your own certs

Live logs use Server-Sent Events for about 30 minutes; those examples
disable proxy buffering / set a long read timeout so the stream is not
cut off. `run.sh` does not change bind address for this.

## Proxy not on loopback

If PHP sees a non-loopback client (nginx in another container, a load
balancer, Cloudflare → origin), set:

```bash
export RUNNERDECK_TRUST_PROXY=1
```

Only do this when you trust the hop that sets `X-Forwarded-Proto`. Anyone
who can talk to PHP directly could then forge that header. Prefer keeping
PHP on loopback so random clients never reach it.

## Health check

`GET /health.php` returns `{"ok":true}` without TOTP and without the
version string. Use it for a local uptime probe, not as a fingerprint
of which release you run.

## Docker

The image still uses `./run.sh` (`php -S` inside the container) and
requires TOTP because it binds `0.0.0.0`. For a public hostname, put
Caddy/nginx on the host and keep the host publish as loopback, or set
`RUNNERDECK_TRUST_PROXY=1` if the published port is only reachable
through that TLS proxy.

See [Security & safety](security-and-safety.md) and [Docker](docker.md).
