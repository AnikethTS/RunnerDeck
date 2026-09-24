# Getting started

## Platform support

This means modern versions of Linux/macOS/Windows — not "every version ever
shipped." Be specific about what that means before assuming an old box works:

| Platform | Support | Notes |
|---|---|---|
| Linux | Native | Needs `bash` (not just `sh`) and PHP 8.1+ with `posix`/`pcntl`. CI unit-tests PHP 8.1 and 8.5 on `ubuntu-latest`, plus a dedicated Alpine (musl) smoke test on the same PHP 8.5 image digest as the Dockerfile — Alpine doesn't ship `bash` by default, which is the one concrete distro gap this project has, and it's verified in CI rather than just claimed. Distros whose default PHP package is older than 8.1 need a backport/PPA. Process liveness uses `/proc`. |
| macOS | Native | Getting PHP 8.1+ in practice means Homebrew, which drops support for old macOS releases on a rolling basis — that's the real version floor, not anything in this codebase. CI unit-tests PHP 8.5 on `macos-latest` and smokes a PHP 8.1 boot there. Process liveness falls back to `ps`/`lsof` (no `/proc` on Darwin). |
| Windows | Via WSL2 | Run `.\run.ps1` — it forwards into WSL and runs `run.sh` there, so it inherits the Linux support above (WSL2 *is* a real Linux kernel). Requires **Windows 10 build 2004 (May 2020 update, 19041) or later, or Windows 11** — that's WSL2's own minimum, not something this project adds. There is no native-Windows code path and no WSL1 fallback: this app depends on `posix_kill`/`pcntl` (`SIGTERM`) for stopping runner processes, PHP does not ship those extensions on Windows, and WSL1 has no real Linux kernel for `/proc` to work the way this app expects. |
| Docker | Alternative to native/WSL2 | `docker compose up --build` — see [Docker](docker.md). Bypasses the PHP/WSL2 prerequisites entirely; the container is Linux regardless of host OS. Runner jobs that need a `docker` command of their own (build/run steps) need Docker-in-Docker or a mounted host socket, neither of which this image sets up — see the Docker doc for why. |

## Prerequisites

- **PHP CLI 8.1+** with the `posix` extension (process liveness checks) and
  `pcntl` (signal constants). Both are standard in most distro `php-cli`
  packages. On Windows, install these inside WSL2, not on the Windows side.
  - Debian/Ubuntu (incl. WSL2): `apt install php-cli`
  - Fedora: `dnf install php-cli php-process`
  - macOS (Homebrew): `brew install php`
  - Windows: `wsl --install`, then follow the Debian/Ubuntu instructions
    inside the WSL2 shell.
- **Optional:** the `pdo_sqlite` extension, for the CPU/RAM history chart
  (`apt install php-sqlite3` / `dnf install php-pdo` / bundled with
  Homebrew's `php`). Nothing else in the dashboard depends on it.
- **[`gh`](https://cli.github.com/)**, authenticated (`gh auth login`), with
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
   ./run.sh          # binds 127.0.0.1:8090
   ./run.sh 9000     # or pick your own port
   ```

   On Windows, run this from PowerShell instead (see [Platform
   support](#platform-support) above):

   ```powershell
   .\run.ps1
   .\run.ps1 -Port 9000
   ```

2. **Open `http://127.0.0.1:8090/`.** If nothing's configured yet, you'll
   land on a setup screen: pick org scope (needs a GitHub org you admin) or
   repo scope (needs a repo you own), fill in the org or `owner/repo`, and
   save — no restart needed. Change it later anytime from the **Settings**
   button in the header. This is saved to a small local, gitignored file
   (`storage/settings.json`), not `.env`.

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
`public/assets/style.css`; installed icons are separate from the optional
header logo override.

Installation options vary by browser. Chrome's install promotion requires
HTTPS (with a localhost/loopback exception for development); visiting another
machine at `http://192.168.x.x:8090/` from a phone does not meet that requirement.
See [Chrome's installation criteria](https://web.dev/articles/install-criteria).
A manual home-screen shortcut may still be available, depending on the browser.
There is no service worker or offline support: the app still needs a connection
to the running RunnerDeck server.
