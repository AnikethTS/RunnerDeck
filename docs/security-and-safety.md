# Security & safety

## Hosting remotely

RunnerDeck defaults to localhost-only with no login — the only thing
between "who can reach this port" and "who can control your runners" is
your OS's own network isolation. If you want to reach your instance from
another device (a VPS, a home server you SSH into from your phone), that's
supported, but it's opt-in and has two parts, both required together:

1. **Require a login.** Either run `php bin/setup-totp.php`, or open
   **Settings → Login → Set up login** in the dashboard itself — both do
   the same thing: generate a TOTP secret, show it for you to add to an
   authenticator app (Google Authenticator, Authy, 1Password, etc.) via
   manual/text entry, and ask for a code back to confirm before saving
   anything. Once set, every page and API call redirects to a login screen
   until you enter a valid 6-digit code. Five wrong codes in a row locks
   login out for 5 minutes. Re-run either path any time to replace the
   secret (**Settings → Login → Replace secret**) — the old one stops
   working immediately.
2. **Put a TLS-terminating reverse proxy in front** (Caddy, nginx, Tailscale,
   etc.) — RunnerDeck itself stays plain HTTP, no certificate handling
   built in. Without TLS, the login code and session cookie both travel
   the network in the clear, which defeats the point of requiring a login
   at all.

`run.sh` still binds `127.0.0.1` even for this setup — the reverse proxy
runs on the *same machine* and forwards `proxy:443 → 127.0.0.1:8090`, so
there's no bind-address change needed (unlike the [Docker](docker.md)
image, which genuinely needs to listen on `0.0.0.0` inside its own
container).

Login is still single-user — there's no concept of separate accounts or
permissions. If you need that, this feature isn't it.

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
can start and stop real processes on the machine it runs on. Don't put
this behind a reverse proxy or expose the port on any network interface
beyond loopback — **unless** you've enabled login (`php bin/setup-totp.php`)
**and** that reverse proxy terminates TLS, the two required-together
preconditions covered in [Hosting remotely](#hosting-remotely) above.
Skipping either one turns "reachable beyond your machine" into "reachable
by anyone," which is exactly what this default posture exists to prevent.
It also downloads and executes GitHub's official runner package on first
use of each runner slot — the same binary GitHub's own setup page would
have you download by hand, checksum-verified before extraction.

All state-changing API calls (`start`, `stop`, `restart`, `start_all`,
`stop_all`, `resize`) require a session-bound CSRF token minted by
`index.php` and sent back by `assets/app.js` — this stops a malicious page
open in another tab from silently driving the dashboard, but on its own it
is not a substitute for keeping this off any network beyond loopback (see
[Hosting remotely](#hosting-remotely) above for the one supported way to
do that).

See also **[SECURITY.md](../SECURITY.md)** for how to report a
vulnerability.
