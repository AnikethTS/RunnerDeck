<img src=".github/logo.png" alt="RunnerDeck logo" width="96" />

# RunnerDeck

[![CI](https://github.com/AnikethTS/RunnerDeck/actions/workflows/ci.yml/badge.svg)](https://github.com/AnikethTS/RunnerDeck/actions/workflows/ci.yml)

A small, local-only web UI for managing a pool of self-hosted GitHub Actions
runners on a single machine: see their GitHub-reported status (online/offline,
busy/idle), see whether the local process is actually running, tail their
logs live, and start/stop/restart them — individually or all at once —
without hand-editing pidfiles or SSHing in to run `ps aux | grep`.

Plain PHP, no framework, no build step, no Composer dependencies. A bit of
vanilla JS for auto-refresh and live log tailing. That's it.

## Why this exists

Self-hosted runners are cheap to run many of on one machine, but tedious to
operate: is `runner-7` actually alive, or did its process die three hours ago
while GitHub still shows it "busy"? Which of these logs has the failure? Did
that last "Start All" click actually work, or did it just pile up a second
copy on top of the first? RunnerDeck exists to make those questions fast
to answer, and to make starting/stopping a fleet of runners a button instead
of a script incantation.

## How it works

- **Local process state** comes from reading each runner directory directly
  — its `.runner` config, its `runner.pid` file, and (as a fallback, since
  pidfiles can go stale or get lost) a live scan of `/proc` matching on the
  actual `Runner.Listener` executable path. A runner is never silently
  "invisible" just because its pidfile disappeared.
- **GitHub-reported state** comes from `gh api orgs/<org>/actions/runners`.
- **Starting an unconfigured runner slot** downloads the official runner
  package for this machine's OS/arch (via the same
  `orgs/.../actions/runners/downloads` endpoint GitHub's own "Add new
  runner" page uses), verifies its checksum, extracts it, requests a fresh
  org registration token, and runs `config.sh` — the same flow you'd do by
  hand, done for you. No manual pre-setup step.
- **Stopping** sends `SIGTERM` to the actual process (by pidfile, or by the
  `/proc` fallback if the pidfile is missing) and waits for a graceful exit.
- Before any **Stop**, the dashboard checks GitHub's `busy` flag for that
  runner and asks for confirmation if it's mid-job (or if that status can't
  be verified at all — it fails closed, not open).

## Platform support

| Platform | Support | Notes |
|---|---|---|
| Linux | Native | Primary target. Process liveness uses `/proc`. |
| macOS | Native | Process liveness falls back to `ps`/`lsof` (no `/proc` on Darwin). |
| Windows | Via WSL2 | Run `.\run.ps1` — it forwards into WSL and runs `run.sh` there. There is no native-Windows code path: this app depends on `posix_kill`/`pcntl` (`SIGTERM`) for stopping runner processes, and PHP does not ship those extensions on Windows. |

## Prerequisites

- **PHP CLI 8.1+** with the `posix` extension (process liveness checks) and
  `pcntl` (signal constants). Both are standard in most distro `php-cli`
  packages. On Windows, install these inside WSL2, not on the Windows side.
  - Debian/Ubuntu (incl. WSL2): `apt install php-cli`
  - Fedora: `dnf install php-cli php-process`
  - macOS (Homebrew): `brew install php`
  - Windows: `wsl --install`, then follow the Debian/Ubuntu instructions
    inside the WSL2 shell.
- **[`gh`](https://cli.github.com/)**, authenticated (`gh auth login`), with
  access matching the scope you pick (see `RUNNERDECK_SCOPE` below):
  - **Org scope** (default) — you need **admin access to the GitHub org**
    you're registering runners under, and a token with the `admin:org`
    scope: `gh auth refresh -h github.com -s admin:org`.
  - **Personal account scope** — no org needed, runners register under your
    own account. If `gh` reports it can't read your runners, it'll print
    the exact scope to request (e.g. `gh auth refresh -h github.com -s
    manage_runners:user`) — run that command and retry.
- `curl` and `tar` (or `unzip` on Windows) for downloading and extracting
  the runner package — both are standard on Linux/macOS.

## First-time setup

1. **Copy the env file and fill it in:**

   ```bash
   cp .env.example .env
   $EDITOR .env   # set RUNNERDECK_SCOPE, and RUNNERDECK_ORG if scope=org
   ```

   Leave `RUNNERDECK_SCOPE=org` (the default) and set `RUNNERDECK_ORG` for
   org-managed runners, or set `RUNNERDECK_SCOPE=user` to manage runners
   under your own personal GitHub account instead — no org required.

2. **Run it:**

   ```bash
   ./run.sh          # binds 127.0.0.1:8090
   ./run.sh 9000     # or pick your own port
   ```

   On Windows, run this from PowerShell instead (see [Platform
   support](#platform-support)):

   ```powershell
   .\run.ps1
   .\run.ps1 -Port 9000
   ```

   Open `http://127.0.0.1:8090/` and click **Start All** (or set a **Pool
   size** and **Apply**). Everything — downloading the runner binaries,
   registering with GitHub, and launching the processes — happens from
   there. No manual runner setup required.

## Configuration

All via `.env` (see `.env.example`) or real environment variables (env vars
win if both are set):

| Variable | Required | Default | Meaning |
|---|---|---|---|
| `RUNNERDECK_SCOPE` | no | `org` | `org` to manage org-level runners, or `user` to manage runners under your own personal account instead |
| `RUNNERDECK_ORG` | only if `scope=org` | — | GitHub org the runners belong to |
| `RUNNERDECK_LABEL` | no | `self-hosted-runnerdeck` | Shared label across the pool; also `runner-base`'s own registered name |
| `RUNNERDECK_POOL_DIR` | no | `<repo>/../runners` | Where `runner-base`, `runner-1`, ... live |
| `RUNNERDECK_GH_BIN` | no | auto-detected | Explicit path to `gh`, if it's not resolvable from PATH in whatever context launches `run.sh` |

Optional: drop a `public/assets/logo.png` in to show a logo in the header —
it's gitignored and entirely optional, the dashboard works fine without one.

## Directory layout

```
runnerdeck/
  public/
    index.php       server-rendered dashboard page
    api.php         JSON API (status, start/stop/restart, pool resize)
    log_stream.php  Server-Sent Events log tailing
    assets/         app.js, style.css, optional logo.png
  src/
    bootstrap.php        single load point required by every public/*.php
    Config.php           env-based configuration
    Csrf.php              session-bound CSRF token minting/verification
    RunnerPool.php        discovers runner dirs, checks process liveness
    GithubClient.php      shells out to `gh`
    Provisioner.php       downloads, installs, and registers a runner
    ProcessControl.php    start/stop/restart, individual and pool-wide
    Dashboard.php         merges local + GitHub state into one snapshot
    Shell.php             timeout-guarded subprocess helper
  tests/            PHPUnit unit tests for the pure-logic pieces above
  .githooks/        pre-commit hook (cs/stan/test), wired up by `composer install`
  deploy/           optional process-supervision examples (systemd, launchd)
  run.sh            Linux/macOS entry point
  run.ps1           Windows entry point (forwards into WSL2)
```

## Development

Running the app itself needs nothing but PHP — `./run.sh` works with a bare
checkout, no build step, no `composer install`. Composer is only used for
**dev tooling** (tests, static analysis, lint):

```bash
composer install          # pulls in phpunit, phpstan, phpcs (dev-only)
composer run test          # PHPUnit
composer run stan          # phpstan (level 5)
composer run cs            # phpcs, PSR-12
composer run cs-fix        # phpcbf, auto-fixes what it can
```

`composer install` also points git at `.githooks/` (a plain shell
pre-commit hook, no Node/Husky involved), so every commit runs `phpcs`,
`phpstan`, and the test suite before it's allowed through. If you already
had `vendor/` installed before this hook existed, wire it up once with
`composer run hooks-install`. A commit made without dev tooling installed
skips the checks with a warning rather than blocking you.

CI (`.github/workflows/ci.yml`) runs all of the above plus a boot smoke test
on Linux and macOS for every push/PR. `.github/workflows/release.yml`
publishes a zipped GitHub Release whenever a `vX.Y.Z` tag is pushed.

Want to contribute? See [CONTRIBUTING.md](CONTRIBUTING.md) — it covers PR
expectations and the project's policy on AI-assisted contributions.

## Safety notes

This is built for a **single-user, single-machine, localhost-only** setup —
`run.sh` binds `127.0.0.1` deliberately and that should not be changed. The
backend shells out to `gh` with whatever scope your login token has (real
admin access to your org's runners in org scope, or to your own account's
runners in personal-account scope), and it can start and stop real
processes on the machine it runs on. Don't put this behind a reverse proxy or expose the port on any network
interface beyond loopback. It also downloads and executes GitHub's official
runner package on first use of each runner slot — the same binary GitHub's
own setup page would have you download by hand, checksum-verified before
extraction.

All state-changing API calls (`start`, `stop`, `restart`, `start_all`,
`stop_all`, `resize`) require a session-bound CSRF token minted by
`index.php` and sent back by `assets/app.js` — this stops a malicious page
open in another tab from silently driving the dashboard, but it is not a
substitute for keeping this off any network beyond loopback.

## License

MIT — see `LICENSE`.
