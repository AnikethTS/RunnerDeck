# Security Policy

RunnerDeck is a local-only dashboard that manages runner processes, shells
out to `gh`/`config.sh`, and optionally gates access behind TOTP. If you
find a security issue — auth bypass, CSRF, command injection, secret
leakage in logs, etc. — please report it privately rather than opening a
public issue.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting for this repo:

1. Go to the [Security tab](https://github.com/AnikethTS/RunnerDeck/security).
2. Click **Report a vulnerability**.
3. Include what you found, how to reproduce it, and the impact you'd
   expect (what an attacker could actually do with it).

This opens a private advisory visible only to you and the maintainer —
nothing is public until a fix is out and you're both ready to disclose it.

## What's in scope

- `src/Auth.php` / `src/Totp.php` / `src/Csrf.php` — the login/lockout/CSRF layer.
- `src/ProcessControl.php` / `src/Provisioner.php` / `src/Shell.php` — anything that shells out or manages processes.
- `public/api.php` and the `src/Api/*` action classes — the HTTP surface.
- Secret/token handling anywhere in `src/` (see `src/AppLog.php`'s redaction logic).

## What's out of scope

- Issues that require local shell access to the host RunnerDeck runs
  on — this is a single-user, local-only tool by design (see the README);
  local access is the trust boundary, not a vulnerability.
- Denial of service against your own local instance.

## Response

This is a small, actively-maintained open source project with one
maintainer — there's no formal SLA, but reports are read promptly and a fix
or mitigation is prioritized once confirmed.
