# Getting started

## Platform support

This means modern versions of Linux/macOS/Windows — not "every version ever
shipped." Be specific about what that means before assuming an old box works:

| Platform | Support | Notes |
|---|---|---|
| Linux | Native | Needs `bash` (not just `sh`) and PHP 8.1+ with `posix`/`pcntl`. Patch the host runtime to 8.2.35+, 8.3.36+, 8.4.27+, or 8.5.12+ (CVE-2026-109618 / CVE-2026-109619). PHP 8.1 is EOL with no official patch — use a distro backport or move up. CI unit-tests PHP 8.1 and 8.5.12 on `ubuntu-latest`, PHP 8.1 / 8.3.36 / 8.5.12 on `ubuntu-22.04` (x64) and PHP 8.1 on `ubuntu-22.04-arm`, plus distro-`apt` PHPUnit on Ubuntu 22.04 and Debian 12, plus distro-`dnf` PHPUnit on Fedora, Rocky 9, and RHEL UBI 9 (posix/pcntl from `php-process`), plus a dedicated Alpine (musl) smoke test on the same PHP 8.5.12 image digest as the Dockerfile — Alpine doesn't ship `bash` by default, which is the one concrete distro gap this project has, and it's verified in CI rather than just claimed. Distros whose default PHP package is older than 8.1 need a backport/PPA. Process liveness uses `/proc`. |
| macOS | Native | Getting PHP 8.1+ in practice means Homebrew, which drops support for old macOS releases on a rolling basis — that's the real version floor, not anything in this codebase. CI unit-tests PHP 8.5.12 on `macos-latest` and PHP 8.1 / 8.5.12 on `macos-15`, and PHP 8.5.12 on Intel `macos-15-intel` (oldest GitHub-hosted macOS still offered after macos-14's 2 Nov 2026 retirement; macos-13 is already gone). Boot smokes PHP 8.1 on `macos-latest`, `macos-15`, and `macos-15-intel`. Process liveness falls back to `ps`/`lsof` (no `/proc` on Darwin). |
| Windows | Native, or via WSL2 | `.\run.ps1` runs natively and manages **Windows** runners (`config.cmd`/`run.cmd`), so `runs-on: windows` jobs work. Needs PHP 8.1+ on `PATH` (`winget install PHP.PHP`), `gh`, and the `curl`/`tar` that ship with Windows 10 1803+. Processes are controlled through PowerShell, `tasklist` and `taskkill` instead of `posix`/`pcntl`. Limits on Windows: stop is always a forced `taskkill /T /F` (a running job is killed, not drained by signal); `php -S` is single-threaded there, so an open live log blocks other requests until it closes (use php-fpm-style hosting for multi-user setups); cgroup caps (`RUNNERDECK_SLOT_*`) and syslog do nothing; CI runs the Windows unit tests and a real process-control smoke on `windows-latest` and `windows-2022` (PHP 8.1 and 8.5), not the full suite (it is Unix-only). `.\run.ps1 -Wsl` instead forwards into WSL2 and manages **Linux** runners inside it (Windows 10 build 19041+/11; no WSL1). |
| Docker | Alternative to native/WSL2 | Enroll TOTP first (`docker compose run --rm -it runnerdeck php bin/setup-totp.php`), then `docker compose up --build` — see [Docker](docker.md). |

> **Fedora note.** Fedora 45 ships OpenSSL 4, and the GitHub runner's bundled .NET
> could not load it when this was tested (`No usable version of libssl was found`
> from `config.sh`). Runners provisioned on Fedora 45 fail to register until GitHub's
> runner supports OpenSSL 4 or a libssl 3 compatibility package is installed. CI runs
> the live dashboard suite on Fedora 44.

## Prerequisites

- **PHP CLI 8.1+** with the `posix` extension (process liveness checks) and
  `pcntl` (signal constants). Both are standard in most distro `php-cli`
  packages. Not needed on native Windows (no such extensions there); with `-Wsl`, install them inside WSL2.
  - Debian/Ubuntu (incl. WSL2): `apt install php-cli`
  - Fedora: `dnf install php-cli php-process`
  - macOS (Homebrew): `brew install php`
  - Windows: `wsl --install`, then follow the Debian/Ubuntu instructions
    inside the WSL2 shell.
- **Optional:** the `pdo_sqlite` extension, for the CPU/RAM history chart
  (`apt install php-sqlite3` / `dnf install php-pdo` / bundled with
  Homebrew's `php`). Nothing else in the dashboard depends on it.
- **[`gh`](https://cli.github.com/)**, authenticated (`gh auth login`) **or**
  a GitHub App in Settings (installation token, no personal login), with
  access matching the scope you pick (see `RUNNERDECK_SCOPE` in
  [Configuration](configuration.md)). GitHub's API has no concept of a
  personal-account-level runner — it's org or repo:
  - **Org scope** (default) — you need **admin access to the GitHub org**
    you're registering runners under, and a token with the `admin:org`
    scope: `gh auth refresh -h github.com -s admin:org`.
  - **Repo scope** — no org needed, runners are scoped to (and only run
    jobs from) one repo you're an admin of, which you are by default for
    repos you own. Needs the `repo` scope, which `gh auth login`'s default
    scopes already include — nothing extra to request in the common case.
- `curl` and `tar` (or `unzip` on Windows) for downloading and extracting
  the runner package — both are standard on Linux/macOS.

Not sure if your machine meets all of the above? Run:

```bash
php bin/doctor.php
```

It checks the PHP version and extensions, `bash`/`curl`/`tar`, whether `gh`
is installed and authenticated, whether the configured scope can actually
read runners, and whether the pool and storage directories are writable —
and exits non-zero if anything required is missing, so it's safe to use in
a setup script too.

## First-time setup

1. **Run it** — no `.env` required to get started:

   ```bash
   ./run.sh          # binds 127.0.0.1:8090 (php -S, one operator)
   ./run.sh 9000     # or pick your own port
   ```

   That built-in server has four workers; a live log holds one of them.
   A TLS hostname should use php-fpm instead — [Hosting](hosting.md).

   On Windows, run this from PowerShell instead (see [Platform
   support](#platform-support) above):

   ```powershell
   .\run.ps1             # native Windows runners
   .\run.ps1 -Port 9000
   .\run.ps1 -Wsl        # Linux runners inside WSL2
   ```

2. **Open `http://127.0.0.1:8090/`.** If nothing's configured yet, you'll
   land on a setup screen: pick org scope (needs a GitHub org you admin) or
   repo scope (needs a repo you own), fill in the org or `owner/repo`, and
   save — no restart needed. Change it later anytime from the **Settings**
   button in the header. This is saved to a small local, gitignored file
   (`storage/settings.json`), not `.env`. Putting this on a public hostname
   needs TOTP plus TLS (php-fpm preferred) — see [Hosting](hosting.md).

3. Click **Start All** (or set a **Pool size** and **Apply**). Everything —
   downloading the runner binaries, registering with GitHub, and launching
   the processes — happens from there. No manual runner setup required.

Prefer config files over the UI (e.g. for a scripted/systemd deployment)?
Copy `.env.example` to `.env` and fill it in instead — real environment
variables win over `.env`, which wins over anything saved through the UI,
so this is always available as an override.

## Add to Home Screen

RunnerDeck includes a web app manifest and bundled icons derived from
`.github/logo.png`. Where supported, use the browser's **Install app** or
**Add to Home Screen** action to open it in a standalone window. The manifest
uses the default light palette's `--brand` and `--surface-1` colors from
`public/assets/style.css`; installed icons match the same art as the
header logo (`public/assets/logo.png`).

Installation options vary by browser. Chrome's install promotion requires
HTTPS (with a localhost/loopback exception for development); visiting another
machine at `http://192.168.x.x:8090/` from a phone does not meet that requirement.
See [Chrome's installation criteria](https://web.dev/articles/install-criteria).
A manual home-screen shortcut may still be available, depending on the browser.
There is no service worker or offline support: the app still needs a connection
to the running RunnerDeck server.
