# Security & safety

## Hosting remotely

RunnerDeck defaults to localhost-only with no login — the only thing
between "who can reach this port" and "who can control your runners" is
your OS's own network isolation. If you want to reach your instance from
another device (a VPS, a home server you SSH into from your phone), that's
supported, but it's opt-in and has two parts, both required together:

1. **Require a login.** Off loopback this is enforced: `./run.sh` will
   not bind `0.0.0.0` without TOTP, and PHP refuses the dashboard/API
   (except `/health.php`) until you run `php bin/setup-totp.php` on the
   machine. On loopback you can still open **Settings → Login → Set up
   login** in the dashboard. Both paths generate a TOTP secret for an
   authenticator app (Google Authenticator, Authy, 1Password, etc.) via
   manual/text entry, and ask for a code back to confirm before saving.
   You also get **one-time recovery codes** (store them offline). Once
   set, every page and API call needs a valid 6-digit code or a recovery
   code. Five wrong tries lock login out for 5 minutes. Replace the
   secret from Settings; the old one and unused recovery codes stop
   working. Optional idle timeout (`RUNNERDECK_SESSION_IDLE_MINUTES`,
   1–1440) ends the session after that many minutes with no requests.
   **Sign out other sessions** on Settings invalidates stolen cookies
   from other browsers. Hidden dashboard tabs do not poll, so they count
   as idle. Blank idle keeps the cookie until the browser closes. There
   is still one operator secret, not a second user.
2. **Terminate TLS in front** (Caddy, nginx, Traefik, Tailscale, etc.) —
   RunnerDeck itself stays plain HTTP. Prefer **php-fpm** on loopback
   ([deploy/Caddyfile.php-fpm](../deploy/Caddyfile.php-fpm),
   [deploy/nginx-php-fpm.conf.example](../deploy/nginx-php-fpm.conf.example)).
   Proxying `./run.sh` (`php -S`) is the same TLS story with a four-worker
   cap. Full steps: **[Hosting](hosting.md)**. Without TLS, the login code
   and session cookie both travel the network in the clear, which defeats
   the point of requiring a login at all. TOTP on public 443 is still
   only one factor — add an IP allowlist, mTLS, or skip public 443
   ([Beyond TOTP](hosting.md#beyond-totp-on-443)).

`./run.sh` is PHP’s built-in server on `127.0.0.1` — four workers, a live
log occupying one of them, no php-fpm isolation. Fine for one operator.
A same-machine proxy can still forward `443 → 127.0.0.1:8090`, or FastCGI
to `127.0.0.1:9000`. Same-machine proxies can send `X-Forwarded-Proto`
and the session cookie will be `Secure`. If the proxy is not on loopback,
set `RUNNERDECK_TRUST_PROXY=1` (see [Hosting](hosting.md)). HTTPS
responses also send `Strict-Transport-Security`.

Login is still single-user — one TOTP secret, recovery codes if the
phone is gone, SSH/`php bin/setup-totp.php` to replace it. A stolen
session cookie (XSS in another app on this origin, unlocked laptop)
can still start and stop runners until idle timeout, user-agent
mismatch, logout, or **Sign out other sessions**. JWT-style API keys
and a second operator account are out of scope.

## What lives on the host

The UI is not the asset. On this machine sit the `gh` token
(`~/.config/gh` or the container mount), the runner pool, and each
slot’s `_work` (job checkout, sometimes workflow secrets on disk).
Anyone with a shell as the operator — or root on the VPS — can drive
GitHub as that token and read the same files the dashboard can. Treat a
compromised host as a compromised org or repo, rotate the token, and
check runner registrations. Network gates in [Hosting](hosting.md)
reduce who can reach the login page; they do not sandbox `gh`.

## Safety notes

This is built for a **single-user, single-machine, localhost-only** setup —
`run.sh` binds `127.0.0.1` deliberately and that should not be changed. The
one exception is the [Docker image](docker.md), which binds `0.0.0.0`
*inside* its own container — required for Docker's port mapping to reach it
at all — and relies on that port mapping, pinned to `127.0.0.1:8090:8090`
in the included `docker-compose.yml`, to stay loopback-only from the host's
point of view instead. Don't widen that mapping, same as you wouldn't
change `run.sh`'s bind address. The backend shells out to `gh` with
whatever scope your login token has (real admin access to your org's
runners in org scope, or to that one repo's runners in repo scope), and it
can start and stop real processes on the machine it runs on. Don't put this behind a reverse proxy or expose the port on any network
interface beyond loopback — **unless** you've enabled login
(`php bin/setup-totp.php`) **and** that reverse proxy terminates TLS.
`RUNNERDECK_BIND_HOST=0.0.0.0` without TOTP now exits. Publishing
Docker as `-p 8090:8090` (all interfaces) still needs TOTP inside the
container because the image binds `0.0.0.0`. Skipping TLS while login
is on still sends the session cookie in the clear.
It also downloads and executes GitHub's official runner package on first
use of each runner slot — the same binary GitHub's own setup page would
have you download by hand, checksum-verified before extraction.

All state-changing API calls (`start`, `stop`, `restart`, `start_all`,
`stop_all`, `resize`) require a session-bound CSRF token minted by
`index.php` and sent back by `assets/app.js` — this stops a malicious page
open in another tab from silently driving the dashboard, but on its own it
is not a substitute for keeping this off any network beyond loopback (see
[Hosting remotely](#hosting-remotely) above for the one supported way to
do that). Auto-restart still goes through that same `POST action=start`;
`GET action=status` only reports the flag and never starts a process.

Session cookies always go through one helper: `HttpOnly`, `SameSite=Lax`,
and `Secure` when the request is HTTPS (`X-Forwarded-Proto` from a
loopback proxy, or `RUNNERDECK_TRUST_PROXY=1`). Successful TOTP login regenerates the session id.
An optional idle timeout (Settings) expires that session after N minutes
without a request.
PHP responses also send `X-Frame-Options: DENY`, `X-Content-Type-Options:
nosniff`, `Referrer-Policy: no-referrer`, and a CSP limited to same-origin
assets plus a per-request script nonce.

See also **[SECURITY.md](../SECURITY.md)** for how to report a
vulnerability.
