# Hosting (TLS reverse proxy)

RunnerDeck is still a **single-user operator UI** for runners on **this
machine**. “Cloud hosting” here means: a VPS or home server where Caddy,
nginx, or Traefik terminates TLS and forwards to RunnerDeck on loopback.
It is not multi-tenant SaaS, and it is not a substitute for `gh` + runner
binaries on that same host.

## Required together

1. **TOTP login** — Settings → Login, or `php bin/setup-totp.php`.
2. **TLS on the public hostname** — the proxy holds the certificate.
   RunnerDeck itself stays plain HTTP.
3. **Do not publish port 8090 on the internet.** Keep `./run.sh` on
   `127.0.0.1:8090` (the default). The proxy on the same machine connects
   to that. Docker’s compose file similarly maps `127.0.0.1:8090:8090`
   unless you intentionally change it.

Skipping login or TLS while the UI is reachable beyond loopback means
anyone who can hit the port can start and stop runners.

## Same-machine proxy (typical VPS)

```
Internet --443/TLS--> Caddy or nginx  -->  127.0.0.1:8090  php ./run.sh
```

Examples:

- [deploy/Caddyfile](../deploy/Caddyfile) — automatic HTTPS
- [deploy/nginx.conf.example](../deploy/nginx.conf.example) — bring your own certs

`run.sh` does not change bind address for this. Live logs use Server-Sent
Events for about 30 minutes; the examples disable proxy buffering / set a
long read timeout so the stream is not cut off.

**Session cookies:** `Secure` is set when the request is HTTPS, including
`X-Forwarded-Proto: https` from a proxy whose `REMOTE_ADDR` is loopback
(`127.0.0.1` / `::1`). That is the same-machine case above.

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

`GET /health.php` returns `{"ok":true,"version":"..."}` without TOTP and
without calling GitHub. Use it for load-balancer probes. It does not mean
the runner pool is healthy.

## php-fpm (optional)

You can put nginx `fastcgi_pass` in front of php-fpm with `root` =
`public/` instead of reverse-proxying `php -S`. Point `SCRIPT_FILENAME` at
`$document_root$fastcgi_script_name`. Keep `./run.sh` (or systemd) for
`bin/watch-auto-restart.php` either way — php-fpm does not start that
loop. `deploy/runnerdeck.service` still runs `run.sh`.

## Docker

The image already listens on `0.0.0.0:8090` *inside* the container. For a
public hostname, put Caddy/nginx on the host (or a sibling container) and
keep the host publish as loopback, or set `RUNNERDECK_TRUST_PROXY=1` and
TOTP if the published port is only reachable through that TLS proxy.
Do not map `0.0.0.0:8090:8090` without login and TLS.

See [Security & safety](security-and-safety.md) and [Docker](docker.md).
