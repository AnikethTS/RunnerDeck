# Development

Running the app itself needs nothing but PHP — `./run.sh` works with a bare
checkout, no build step, no `composer install`. Composer is only used for
**dev tooling** (tests, static analysis, lint):

```bash
cd app
composer install          # pulls in phpunit, phpstan, phpcs (dev-only)
composer run test          # PHPUnit
composer run stan          # phpstan (level 8)
composer run cs            # phpcs, PSR-12
composer run cs-fix        # phpcbf, auto-fixes what it can
composer audit             # known CVEs in dependencies
```

The PHP app, its Composer files and its unit tests live in `app/`, so run Composer commands from there. `composer install` also points git at `.githooks/` (a plain shell
pre-commit hook, no Node/Husky involved), so every commit runs `phpcs`,
`phpstan`, and the test suite before it's allowed through. If you already
had `vendor/` installed before this hook existed, wire it up once with
`composer run hooks-install`. A commit made without dev tooling installed
skips the checks with a warning rather than blocking you.

The JS/CSS side has its own dev-only tooling, the same "never a runtime
dependency" deal as Composer above — `run.sh` doesn't need `node_modules/`
any more than it needs `vendor/`:

```bash
npm install       # pulls in eslint, stylelint, Playwright (dev-only)
npm run lint:js   # eslint on app/public/assets/app.js and app/public/assets/js/
npm run lint:css  # stylelint on app/public/assets/style.css
npm run audit     # npm audit (see tools/ci/npm-audit.mjs)
npm run e2e       # Playwright — see tests/e2e/dashboard.spec.js
```

`npm run e2e:update-shots` regenerates the README/landing PNGs in dark
mode (and copies them into `docs/screenshots/`). Generate them in the
same Playwright image CI uses
(`mcr.microsoft.com/playwright:v1.64.1-noble`); host Fedora/macOS fonts
will otherwise fail the pixel compare.

CI prints `[1/N]` as each Playwright and PHPUnit test starts, and stops
the browser suite after 5 failures. A click that does not land fails in
5 seconds.

`npm run e2e` boots a real `php -S` server and drives the real dashboard in
a real (headless) browser — see the comment at the top of
`tests/e2e/dashboard.spec.js` for exactly what is and isn't real in there (GitHub
itself is never contacted; runner data for the richer UI tests is supplied
by mocking `action=status` responses, and `action=queue` is mocked so
tests never walk live workflow runs).

CI (`.github/workflows/ci.yml`) also runs **Shellcheck** on `run.sh`,
`tools/ci/*.sh`, and any `bin/*.sh`, and **gitleaks** on the git history
(placeholders in tests and the Settings PEM hint are allowlisted in
`.gitleaks.toml`). Playwright
compares dashboard, Settings, Add Runner, and live-log screenshots to
`.github/screenshots/` (and those files must match `docs/screenshots/`).
`.github/workflows/release.yml` scans the published
`ghcr.io/anikethts/runnerdeck` image with Trivy (CRITICAL/HIGH, unfixed
ignored) after push.

The `e2e-real-runner` job registers a real ephemeral runner on Linux, macOS and Windows hosted runners, dispatches `tools/ci/e2e-probe-workflow.yml` onto it, and waits for the job to pass. It needs a dedicated, throwaway test repository containing a copy of `tools/ci/e2e-probe-workflow.yml` at `.github/workflows/runnerdeck-e2e.yml` (copied once by hand, so the token never needs the Workflows permission), a repository variable `E2E_REPO` (`owner/repo`), and a secret `E2E_RUNNER_TOKEN`: a fine-grained token limited to that one repo with Administration, Actions and Contents read/write. Keep that repo private and empty of anything else, since a runner executes its workflows. Without the secret or variable the job fails. Pull requests from forks skip it, because they never receive the secret. Fork pull requests never get the secret.

The `e2e-live` job runs `tests/e2e-live/dashboard-live.spec.js` with Playwright against the real dashboard on Ubuntu (latest, 22.04, 22.04 arm), macOS (Apple silicon and Intel), Windows (latest and 2022), and Fedora, using the same test repo and secrets as `e2e-real-runner`. Fedora has no hosted runner, so the dashboard and its runners run in a `fedora:44` container while Playwright drives it from the host (`E2E_EXTERNAL_SERVER=1`). It clicks Add Runner, Start, Stop, Restart, Pause/Resume AR, View live log, Rename, Clear work, Export JSON, Pool size Apply, the bulk actions, Stop All, Start All, Rolling Restart, Maintenance, Drain All, Delete, and the Settings save, and checks the runner state on GitHub after each. It also runs a real workflow job and times every header tab. Not covered: Upgrade agents and Safe reboot. Run it locally with `E2E_REPO`, `GH_TOKEN` and `E2E_TAG` set and `npm run e2e:live`; it registers real runners on your machine.

### Packaging

Everything that ships is built by one tool, `tools/package/build.py --target <name>` (repeat `--target`, or use `windows` for all three Windows targets). CI and the release workflow call exactly this, so what CI tests is what ships. Artifacts are named `RunnerDeck-<version>-<os>-<arch>[-setup].<ext>` (`x64` and `arm64`) with one `SHA256SUMS-<os>-<arch>` file per platform, and the version always comes from `VERSION`.

| Path | What lives there |
|---|---|
| `desktop/` | The Go desktop shell (module `github.com/AnikethTS/RunnerDeck/desktop`, entry point `cmd/runnerdeck`): tray, window, process supervision, first-run checks. Platform code is in `_windows.go` / `_other.go` files, the rest is shared. |
| `packaging/common/` | Inputs shared by every target, such as `php-branch.txt`. |
| `packaging/windows/installer/` | The Inno Setup script. |
| `tools/package/` | `build.py`, `common.py` (version, checksums, naming, app staging) and one module per OS, plus the smoke tests. |
| `tools/ci/` | CI-only helper scripts. |

**Linux.** `linux-deb` builds `RunnerDeck-<version>-linux-all.deb`, a Debian/Ubuntu package assembled in pure Python (no `dpkg` needed to build it). It installs the app to `/usr/share/runnerdeck`, a `runnerdeck` command in `/usr/bin` (the shared launcher in `packaging/common/runnerdeck`, which keeps data in `~/.local/share/runnerdeck`), a desktop entry, icons and a systemd user unit, and depends on the distro's `php-cli`, `php-curl`, `php-mbstring` and `php-sqlite3`. `tools/package/smoke_linux.py` installs it with `apt`, runs it, checks `/health.php` and removes it again. `tools/package/test_package.py` unit-tests the package layout and the formula and runs without installing anything.

**macOS.** `brew-formula` renders `packaging/macos/runnerdeck.rb.in` into `dist/runnerdeck.rb` for the release's source archive (`--source-zip`, `--tag`). The formula installs the app into `libexec`, the same shared launcher into `bin`, and a `service` block for `brew services`. Homebrew only installs formulae from a tap, so the file is attached to each release and the release workflow's `homebrew-tap` job commits it to `AnikethTS/homebrew-runnerdeck`, whose own workflow installs and tests it on macOS. That job needs a fine-grained personal access token with write access to the contents of that repository only, stored as the repository secret `HOMEBREW_TAP_TOKEN`; without it the job only prints a notice and the file has to be copied into the tap by hand. Homebrew refuses to load a third-party tap until it is trusted, so users run `brew tap anikethts/runnerdeck` and `brew trust anikethts/runnerdeck` before `brew install anikethts/runnerdeck/runnerdeck`. A native `.app`/`.dmg` and a Linux tray window are not built yet.

**Windows.** `windows-zip` builds the zip, `windows-exe` the single self-contained exe, and `windows-setup` the installer wizard. They use the official PHP for Windows (checksum verified against windows.php.net, branch in `packaging/common/php-branch.txt`), the app, and `RunnerDeck.exe`, built from `desktop/`. The launcher keeps all state under `%LOCALAPPDATA%\RunnerDeck` through the `RUNNERDECK_*` variables, and ties the PHP processes to a Windows Job Object so they stop when it exits. `tools/package/smoke_windows.py` runs the packaged zip or exe and checks that PHP stops with the launcher, and `tools/package/smoke_installer.py` installs the Setup wizard silently, starts it, checks a second launch and uninstalls. Build locally with Go installed: `python3 tools/package/build.py --target windows-zip`.

The console launcher is a guided setup. It prints the welcome banner itself (PHP may not run yet), unpacks the runtime, runs `php.exe -v` and, on `STATUS_DLL_NOT_FOUND` (0xC0000135), offers to download Microsoft's Visual C++ Redistributable, checks that it is signed by Microsoft Corporation, and runs it elevated. It then checks `gh` (offering `winget install GitHub.cli` and `gh auth login`), starts the server, and on any failure keeps the window open until Enter. Prompts only appear in an interactive console; `--yes` accepts them all and a non-interactive run never blocks. The single exe embeds the same PHP and app as `payload.zip` with `go:embed` (build tag `payload`) and unpacks it on first start into `%LOCALAPPDATA%\RunnerDeck\runtime\<hash>` (older unpacked versions are removed). Self-unpacking executables are more likely than plain ones to trigger antivirus warnings, which is another reason to sign them later.

The installer is built with [Inno Setup](https://jrsoftware.org/isinfo.php) (`packaging/windows/installer/runnerdeck.iss`, needs `ISCC.exe`; the Windows runner images include it). It is per-user (`{autopf}` resolves to `%LOCALAPPDATA%\Programs` when not elevated), installs missing prerequisites in its `[Code]` section (the Visual C++ runtime and the WebView2 runtime via `DownloadTemporaryFile`, a Microsoft signature check and an elevated `runas`; the GitHub CLI via winget; a postinstall `gh auth login`; `/TASKS=` skips them in silent runs), adds a Start menu folder with an Uninstall entry, an optional desktop shortcut and an optional `HKCU\...\Run` entry, and its uninstaller stops the app, removes the unpacked runtime and asks before deleting your data. It installs a second build of the launcher, linked with `-H=windowsgui -X main.defaultMode=tray`: a windowless tray app (`fyne.io/systray`) whose prompts are Windows message boxes and whose GitHub sign-in or winget steps open a console window only when needed. The dashboard opens in a native window using `github.com/jchv/go-webview2` (pure Go, no cgo, needs the WebView2 runtime; without it, or with `--no-browser`, there is no window and the browser is used). The window runs on the main thread while the tray runs on its own; closing it hides it, and the tray's Quit closes it for real. `--background` creates it hidden. A named mutex keeps it to one instance (a second launch focuses the existing window), which `AppMutex` also lets the installer detect. `--tray` and `--console` force either mode. The release workflow builds and attaches only the installer, `SHA256SUMS-windows-x64` and one attestation; the console exe and the zip are built by the `Package · Windows zip` CI job as downloadable artifacts but are not published on releases.

### Choosing which CI runs

Pull requests get the fast set by default: lint, the secret scan, and the unit suite with the coverage gate. Add labels to run more, as in other projects that use this pattern:

| Label | Adds |
|---|---|
| `ci:fast` | Lint, secret scan, unit suite with coverage (always on) |
| `ci:unit` | Every `Unit ·` job: Linux, macOS, Windows subset, distro containers |
| `ci:quality` | Random-order test run |
| `ci:smoke` | Boot smoke tests: hosts, Alpine, Docker image |
| `ci:e2e` | Browser UI tests with a mocked API |
| `ci:live` | Live dashboard and real-runner tests on Ubuntu, macOS and Windows (uses GitHub API quota) |
| `ci:live-extended` | Live dashboard tests on older OS versions and Fedora (uses GitHub API quota) |
| `ci:package` | Builds the Windows zip, single exe and Setup installer, and smoke-tests each |
| `ci:full` | Everything above except `ci:live-extended`. **Required before merging.** |

Pushes to `main`, the nightly run and manual runs always run everything. The `CI · Merge gate` job passes only when every job that ran passed, and on a pull request only when `ci:full` is set. Adding or removing a label queues another CI run after the current one finishes, and does not cancel it. (ChangeRadar's annotations check attaches to the first run on a commit, and GitHub ignores checks from a cancelled run.) Fork pull requests never run the live jobs, because they do not receive the secrets.

CI also enforces a minimum line coverage (`tools/ci/coverage-gate.php`, currently 75%), runs the suite in random order with three seeds, and re-runs everything nightly. Raise the coverage floor whenever coverage improves; never lower it to land a change. The same Clover report is uploaded to Codecov. A public repository can do that without a token; set `CODECOV_TOKEN` if Codecov asks for one, and install the Codecov GitHub App for the pull-request comment.

The Windows code paths are unit-tested on Linux with `Platform::fakeWindows()` and a faked `Shell` (`app/tests/WindowsSupportTest.php`); the `windows-test` CI job runs that file and `tools/ci/windows-smoke.php`, which drives the real PowerShell, CIM and `taskkill` calls on `windows-latest` and `windows-2022`. The rest of the suite is Unix-only and is not run there.

CI (`.github/workflows/ci.yml`) runs all of the above plus a boot smoke test
on `ubuntu-latest`, `ubuntu-22.04`, `macos-latest`, `macos-15`, and Intel `macos-15-intel` (PHP 8.1),
PHPUnit on those hosts plus `ubuntu-22.04-arm`, distro-`apt` PHPUnit on
Ubuntu 22.04 and Debian 12, distro-`dnf` PHPUnit on Fedora, Rocky 9, and
RHEL UBI 9, a dedicated Alpine (musl)
container (PHP 8.5.12, same digest as the Dockerfile), and the
[Docker image](docker.md) itself (`docker build` + env/lib assertions +
boot + hit `action=status` through the real port mapping) for every
push/PR — see [Platform support](getting-started.md#platform-support) —
with the default `GITHUB_TOKEN` scoped narrowly per job rather than
workflow-wide, and third-party actions pinned to commit SHAs rather than
mutable version tags. PHP lint and JS lint run as parallel jobs; workflows
are checked with `actionlint`. PRs to `main` also run
`.github/workflows/changeradar.yml`: ChangeRadar 0.2.5 via the published
Action (SHA-pinned wrapper; latest `changeradar.jar` unless `version` is
set; fail-open; the Action posts artifact, compact comment, and Checks
notices; SARIF stays on the artifact, not Code Scanning).
`.github/workflows/release.yml` publishes a
zipped GitHub Release (checksum + signed provenance) and pushes
`ghcr.io/anikethts/runnerdeck` whenever a `vX.Y.Z` tag is pushed — bump
the root `VERSION` file to match in the same commit, before tagging; it's
what the optional in-app update check compares against. Dependabot
(`.github/dependabot.yml`) keeps the dev-tooling Composer/npm
dependencies and the Docker base image current on a weekly schedule, and
moves pinned Actions SHAs daily (including ChangeRadar). Leave the
analyzer `version` input unset so the JAR stays on the latest release.

Security checks beyond linting: `.github/workflows/semgrep.yml` and
`.github/workflows/codeql.yml` (JavaScript; PHP is covered by Semgrep) run static analysis on
every push/PR;
`.github/workflows/dependency-review.yml` blocks a PR that introduces a
known-vulnerable or newly license-incompatible dependency;
`.github/workflows/socket.yml` runs a Socket project report, a supply-chain
scan of the manifests, on pull requests and on `main` once a free
`SOCKET_SECURITY_API_KEY` repository secret is set;
`.github/workflows/zizmor.yml` audits the workflows for unpinned actions,
broad tokens, and script injection, and uploads the results to code scanning;
`.github/workflows/govulncheck.yml` scans the `desktop/` Go module for known
vulnerabilities;
`.github/workflows/scorecard.yml` runs an OpenSSF Scorecard pass. `main` is
protected with a **repository ruleset** (not classic branch protection) so
Scorecard can read the rules with the default `GITHUB_TOKEN`. GitHub
secret scanning + push protection and Dependabot security updates are both
enabled on the repo itself (not something in this codebase to configure,
but worth knowing they're on).

Want to contribute? See [CONTRIBUTING.md](../CONTRIBUTING.md) — it covers
PR expectations and the project's policy on AI-assisted contributions.

## Directory layout

```
runnerdeck/
  app/               the PHP application and everything that tests it
    composer.json, phpunit.xml, phpcs.xml, phpstan.neon
    public/
    index.php       server-rendered dashboard page
    api.php         JSON API front controller (`action=` handlers in app/src/Http/Action/)
    log_stream.php  Server-Sent Events log tailing
    login.php       optional TOTP login screen (see Security & safety)
    logout.php      POST + CSRF; ends a TOTP session and redirects to login
    settings.php    scope/org/repo/label, GitHub App, automation, crash webhook, login, settings/host-bundle import
    history.php     24h CPU/RAM/disk-free charts (local samples)
    crashes.php     7-day crash bars and list
    outbox.php      webhook retry queue (CSRF drop; GET does not send)
    bundles.php     daily host-bundle files (download / CSRF delete)
    download_snapshot.php  one stored host-YYYY-MM-DD.json
    download_log.php      runner.log (+ rotated) download
    download_applog.php   storage/runnerdeck.log download
    download_settings.php redacted settings.json download
    download_bundle.php   host disaster-recovery JSON (optional webhook via POST)
    totp_setup.php  optional TOTP enroll / rotate
    health.php      unauthenticated `{"ok":true}` probe (no version; `ready` / `pressure` / `reboot_ready`)
    metrics.php     Prometheus text (local pool/pressure/queue cache/outbox; no GitHub)
    assets/         app.js (live dashboard), js/theme.js (theme and help tips), style.css
                    (shared --page-gutter / --page-max; 900 / 720 / 520 breakpoints), logo.png
  src/
    bootstrap.php   autoload (`RunnerDeck\` → `app/src/`) + env bootstrap
    Configuration/  Config (env), Settings (settings.json), SettingsPortable (export/import)
    Security/       Auth, TOTP, CSRF, session, client gate, security headers, hosting warnings
    System/         Platform (OS differences), Shell (every external command)
    Logging/        AppLog (redacting application log)
    Presentation/   dashboard snapshot and first-paint HTML, page layout, terminal banner
    GitHub/         gh client, GitHub App, queue cache, agent version, orphans, backoff/clock/quota
    Host/           host mode, resource pressure, system stats, per-slot cgroup and disk limits
    Telemetry/      CPU/RAM/disk history, Prometheus metrics, watcher heartbeat
    Backup/         host disaster-recovery bundle and its daily snapshot
    Pool/           runner dirs, start/stop, provisioning, process control, log retention
    Lifecycle/      auto-restart, drain, idle state, rolling restart/upgrade, crash state and history
    Alerting/       crash webhook, disk alert, outbox retry, syslog, queue/long-job alerts
    Http/           API router (`RunnerDeck\Http\Api`)
    Http/Action/    one class per `api.php?action=`, grouped as Runners/, Authentication/,
                    Administration/ and Monitoring/ (`RunnerDeck\Http\Action\<Group>\`)
  app/tests/        PHPUnit unit tests for the pure-logic pieces above
  tests/e2e/        Playwright UI tests with a mocked API
  tests/e2e-live/   Playwright tests against real GitHub (see below)
  desktop/          Go desktop shell: tray, window, supervisor (see Packaging)
  packaging/        installer scripts and inputs shared by every package target
  tools/package/    the build tool for every release artifact and its smoke tests
  tools/ci/         CI-only scripts (distro PHPUnit, smoke status, pinned gh install)
  .githooks/        pre-commit hook (cs/stan/test), wired up by `composer install`
  deploy/           php-fpm, Caddy/nginx, IP allowlist/mTLS; systemd/launchd
  bin/install-service.sh  print or install a systemd unit / launchd agent for run.sh
  run.sh            local php -S entry (also starts watch-auto-restart)
  app/bin/watch-auto-restart.php  loop for crash restart, ephemeral, scale-to-queue, idle-stop, alerts, daily bundle snapshot, log retention
  app/bin/drain-stop.php      wait-for-idle then SIGTERM (host reboot)
  run.ps1           Windows entry point (native; -Wsl runs Linux runners in WSL2)
  Dockerfile        alternative container entry point (see Docker doc)
  docker-compose.yml
```
