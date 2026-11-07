# Changelog

All notable changes to RunnerDeck are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versioning
follows [SemVer](https://semver.org/).

## [Unreleased]

### Changed

- The dashboard poll keeps the table scroll position, the selected rows, and keyboard focus when a row's markup has not changed.
- A runner page action refreshes that page in place. Delete still returns to the list.
- Pull requests scan the Docker image with the same Trivy rules as a release. A release pushes the image only after that scan passes.

## [1.14.1] - 2026-11-07

### Fixed

- The Docker image rebuilds GitHub CLI 2.103.3 with `golang.org/x/net` v0.62.0. The 1.14.0 release scan stopped on CVE-2026-78661 (HIGH) in the official `gh` binary, which still vendors v0.60.0, and no newer `gh` release includes the fix. 1.14.1 contains everything in 1.14.0 and publishes the Windows installer and Debian package.

## [1.14.0] - 2026-11-07

### Added

- CI runs a Socket project report on pull requests and on `main` (`.github/workflows/socket.yml`). The job waits for a free Socket API token in the `SOCKET_SECURITY_API_KEY` repository secret.
- CI audits GitHub Actions workflows with zizmor (`.github/workflows/zizmor.yml`) and scans the `desktop/` Go module with govulncheck (`.github/workflows/govulncheck.yml`). Neither needs an API token.
- The coverage job uploads its Clover report to Codecov. The 75% gate still decides pass or fail. Install the Codecov GitHub App for the pull-request comment; a public repository can upload without a token.

### Fixed

- Upgrade agents tells you what it did. The older-package banner uses the listener version on disk, so it clears after the binaries are replaced. The request is allowed enough time to drain and download every configured slot.
- Dashboard actions show the server's result on that runner's row. The row pulses while the request runs, then shows the outcome there. A request that dies shows the error on the same row.
- The dashboard leads with a compact status strip and the runner list. Host charts sit behind Last hour. Each row keeps Start, Stop, and Drain in view. More opens that runner’s page, with its status, log, and the rest of its actions.
- The desktop module requires Go 1.26.10, so govulncheck no longer fails on standard-library fixes from earlier 1.26 releases.
- Runner jobs can unpack actions on a host where `tar` returns ENOSYS. When `bsdtar` works, RunnerDeck puts a `tar` wrapper first on the runner PATH and in each slot's `.path`, including after rename, restart, and re-add. `doctor.php` and the dashboard say the shim is in use. Hosts where `tar` works are unchanged.
- The Socket project report on a push to `main` scans that branch and no longer comments on pull request 0.
- Workflow checkouts no longer keep a git credential in the workspace. Release and provenance scripts take tags and attestation paths from the environment. Dependabot waits 7 days before opening an update. The Fedora and UBI unit images are pinned by digest, and the Socket CLI install is hash-pinned.

## [1.13.0] - 2026-11-06

### Added

- `bin/install-service.sh` prints a systemd unit (Linux, starts at boot as the current user) or a launchd agent (macOS, starts at login) for this checkout. `--install` writes it and starts it; without that flag it only prints the steps.

- A Homebrew tap, `AnikethTS/homebrew-runnerdeck` (`brew tap anikethts/runnerdeck`, `brew trust anikethts/runnerdeck`, then `brew install anikethts/runnerdeck/runnerdeck`), and a release job that updates it when a `HOMEBREW_TAP_TOKEN` secret is configured.

### Changed

- The README now explains the SmartScreen "Windows protected your PC" screen the unsigned installer triggers (More info, then Run anyway) and how to verify the download.

### Fixed

- A failed runner-package extract no longer leaves a slot that looks installed. Start checks for `bin/Runner.Listener` (`.exe` on Windows), extracts into a side directory and moves it into place only when that file is there, and deletes the side directory when extract fails. If `tar` exits with "Function not implemented" (ENOSYS), the error names that and tries `bsdtar`, then PHP's PharData. `doctor.php` extracts a one-file archive into the pool directory so this shows up before the first start.

## [1.12.1] - 2026-11-05

### Fixed

- The Docker image upgrades `libexpat` at build time. The 1.12.0 release build reused a cached layer with `libexpat` 2.9.0 (three HIGH CVEs, fixed in 2.10.0) and its image scan failed, so 1.12.0 never published its Windows installer or Debian package. 1.12.1 contains everything in 1.12.0 and publishes them.

## [1.12.0] - 2026-11-05

### Added

- A Windows installer, `RunnerDeck-<version>-windows-x64-setup.exe`, built with Inno Setup and attached to every release as the Windows download. It installs for your account without administrator rights, offers the tools RunnerDeck needs (Microsoft's Visual C++ runtime, the Edge WebView2 runtime and the GitHub CLI, only when missing, each downloaded and checked for Microsoft's signature or installed with winget) and a GitHub sign-in, and adds a Start menu folder with an Uninstall entry, an optional desktop shortcut and an optional start-with-Windows entry. It uninstalls from Settings, the Start menu or the tray menu and asks before deleting your data.
- The installed Windows app opens RunnerDeck in its own window, drawn with Microsoft Edge WebView2, instead of a browser tab, and lives in the system tray (Open dashboard, Show server log, Uninstall, Quit). Closing the window hides it to the tray. Without WebView2 it falls back to your browser. Starting with Windows launches into the tray (`--background`).
- A console build for people who want no installer: a guided `RunnerDeck.exe` (welcome banner, PHP check, Visual C++ and GitHub CLI prompts, `--yes` to accept them) in a single-file form and a zip. CI builds both as downloadable artifacts of the `Package · Windows zip` job (label `ci:package`); releases do not attach them.

- A Debian/Ubuntu package, `RunnerDeck-<version>-linux-all.deb`, with a `runnerdeck` command, a desktop entry and a systemd user unit, and a Homebrew formula (`runnerdeck.rb`) generated for each release. Both come from the new `tools/package/build.py` targets `linux-deb` and `brew-formula` and are attached to releases. A new `Package · Linux deb` CI job (label `ci:package`) builds, installs, runs and removes the package.

- A welcome message with the version, credits and links when you start RunnerDeck with `run.sh` or `run.ps1`, or run `bin/doctor.php` or `bin/setup-totp.php`. Set `RUNNERDECK_NO_BANNER=1` to hide it.

- CI job names follow one `Group · Platform · detail` pattern.
- CI runs a fast set on pull requests by default and more with `ci:*` labels (`ci:unit`, `ci:live`, `ci:full`, …), like other projects that gate CI by label. A `CI · Merge gate` job requires `ci:full` before merge.

- The live dashboard suite also runs on Ubuntu 22.04 (x64 and arm), Intel macOS, Windows 2022, and inside a Fedora 44 container.

- CI job that drives the dashboard in a real browser on Linux, macOS and Windows against real runners, clicking each button and checking the result on GitHub.

- CI job that registers a real runner through the dashboard API on Linux, macOS and Windows, dispatches a workflow onto it in a dedicated test repo, and checks the job succeeds.

- Stricter CI: a line-coverage gate (75%), PHPUnit runs in random order with three seeds, risky tests and notices fail the run, PHP 8.2/8.3/8.4 unit-test jobs, and a nightly scheduled run.

- CI also tests and boot-smokes on Intel macOS (`macos-15-intel`).

- Native Windows support. `run.ps1` now runs the dashboard on Windows and
  manages Windows runners (`config.cmd`/`run.cmd`, zip package), so
  `runs-on: windows` jobs work. Process control uses PowerShell/CIM and
  `taskkill`; stop asks the runner to exit first and force-kills it only if
  needed. WSL2 is no longer needed on Windows; `run.ps1 -Wsl` is only for
  managing Linux runners from a Windows PC. A CI job on `windows-latest` and `windows-2022` runs the Windows tests and a real process-control smoke.

### Changed

- The repository is reorganised. The Go desktop shell moved from `packaging/windows/launcher` to `desktop/`, the build scripts from `scripts/` to `tools/` (`tools/package/build.py --target ...` is now the single build entry point), and the browser tests to `tests/e2e` and `tests/e2e-live`. Release files follow `RunnerDeck-<version>-<os>-<arch>[-setup].<ext>` with one `SHA256SUMS-<platform>` file per platform.
- The PHP app moved into `app/` (`app/src`, `app/public`, `app/bin`, `app/tests`, `app/composer.json`); `run.sh` and `run.ps1` stay at the root as the entry points, and your `.env`, `storage/` and the default runner folder keep their old locations. Commands that referred to `bin/` are now `php app/bin/doctor.php` and `php app/bin/setup-totp.php`. Inside `app/src`, folders are named by responsibility: `Configuration`, `Security`, `System`, `Logging`, `Presentation`, `Telemetry`, `Backup`, `Lifecycle` and `Alerting` replace `Core`, `Ui`, `Alert` and parts of `Host` and `Pool`, and the HTTP actions are grouped under `Http/Action/{Runners,Authentication,Administration,Monitoring}`. Class names are unchanged; only namespaces moved.

- The queued-jobs scan fetches each completed run's jobs once and keeps them, so a refresh makes about 3 GitHub calls instead of up to 27. This cuts API quota use on busy repos.

- Dashboard and hosting alerts stay pinned under the top bar while the
  page scrolls, including the older-runner notice.
- CI prints `[1/N]` as each Playwright and PHPUnit test starts. The
  browser suite fails a missed click in 5 seconds, stops after 5
  failures, and checks that cancel on stop, drain, upgrade, clear work,
  and delete does not call the API.
- Stop, restart, drain, roll restart, upgrade, clear work, and removing a
  GitHub-only runner ask before they run. Settings import, recovery-code
  regeneration, signing out other sessions, snapshot delete, and outbox
  drop do too. The confirm button names the action.
- The dashboard reuses one GitHub runner list for about 8 seconds and
  measures every slot's disk in a single `du` about every 30 seconds. A
  status or queue poll that is still in flight is not started again.
  Busy/idle can lag about 8 seconds, and disk size about 30.

### Fixed

- The release workflow no longer runs the Windows installer smoke test in the source-zip job, where it would have failed.

## [1.11.1] - 2026-10-31

### Security

- The GHCR image upgrades Alpine `brotli-libs` to 1.2.1-r0 and `curl` /
  `libcurl` to 8.23.0-r0. The `v1.11.0` publish scan stopped on
  CVE-2026-111497 (critical) and CVE-2026-92392 (high).

## [1.11.0] - 2026-10-31

### Added

- **Live job on a busy row**: workflow, job name (link to the run), and
  elapsed time. Org scope reads in-progress runs on the org, so the row
  is not limited to recently pushed repos. Missing GitHub data fails open.

### Security

- Pin dev-only `minimatch` to **11.0.1** (GHSA-g3hv-75wg-m249). ESLint and
  stylelint still run. This is not a runtime dependency.

- Pin GitHub CLI **2.103.3** in the Docker image (Go 1.27.3, `x/net` 0.60.0,
  `x/mod` 0.41.0) so Trivy on GHCR is not blocked by CVE-2026-56864,
  CVE-2026-56865, CVE-2026-78667, CVE-2026-78669, CVE-2026-97031, and
  CVE-2026-111943 in the older `gh` 2.100.0 binary.

- A public hostname reached from a loopback proxy (`php -S` on
  `127.0.0.1`) still loads, but the dashboard and `php bin/doctor.php`
  warn when neither an IP allowlist nor mTLS is set. php-fpm still
  enforces the extra gate because it sees the real client.

- PHP refuses public Host / public client IP unless an extra gate is on:
  `RUNNERDECK_IP_ALLOWLIST` match or `RUNNERDECK_REQUIRE_MTLS=1` with
  `SSL_CLIENT_VERIFY=SUCCESS`. Loopback, Tailscale CGNAT, and IPv6 ULA skip
  this. Empty allowlist is fail-closed. `health.php` / `metrics.php` stay
  ungated. `TRUST_PROXY` only chooses which IP the allowlist checks.

### Changed

- Settings sections, the org/repo scope fields, and the webhook test are
  server-rendered. The live dashboard still updates in the browser.

- `HostBundle` and `HostBundleSnapshot` live in `RunnerDeck\Host`. `DiskAlert`
  lives in `RunnerDeck\Alert`. CI-only scripts live in `scripts/ci/`.
  Shellcheck follows that path. Operator commands stay in `bin/`. Public
  URLs are unchanged.

## [1.10.0] - 2026-10-28

### Added

- **Host history page** (`history.php`): 24h CPU, runner RSS, and pool disk-free
  charts from samples already written on dashboard polls. Dashboard sparklines
  stay at one hour. Missing sqlite fails open.

- **Crash timeline** (`crashes.php`): 7 UTC-day bar chart plus the recent crash
  list from the existing sqlite log. No GitHub.

- **Webhook outbox page** (`outbox.php`): pending retries (host, age, last
  error). POST drop with CSRF. GET does not send.

- **Host-bundle snapshots page** (`bundles.php`): list daily files, download,
  delete one (CSRF). TOTP is never shown. Does not write a new snapshot.

- **gh api backoff**: HTTP 429 / secondary rate limit cools down polls
  (30s, doubling, cap 15 min, or `Retry-After`). Dashboard banner. Fail-open
  if the cooldown file is missing. GET status still does not start runners.

- **Clock-skew warning**: when GitHub’s `Date` header is 5+ minutes off this
  host, the dashboard banner says so. Missing/unparseable `Date` fails open.

- **Daily host-bundle snapshot**: optional copy of the disaster-recovery JSON
  into `storage/bundles/host-YYYY-MM-DD.json` (7 days). TOTP is never
  included; crash webhook only with opt-in. Runs from watch (including
  maintenance) or an open dashboard tab. Not from `health.php` / `/metrics.php`.

- **Log retention**: optional days (1–365) to delete idle-slot `runner.log`,
  `runner.log.1`, and `_diag` files older than that. Never while the process
  is running. Never deletes `_work`. Runs from watch, including maintenance.

- **GitHub API quota**: last `X-RateLimit-*` from `gh api --include`. Banner
  when remaining is under 100 or ≤10% of the limit. The GitHub stats card
  shows remaining/limit when known. Missing headers fail open.

- **Outbox gave up**: after 8 failed webhook retries, a dashboard banner
  points at Outbox for 7 days. A later successful delivery or a drop clears
  it.

- **Pin runner package SHA-256**: Settings / `RUNNERDECK_AGENT_SHA`. Add
  and Upgrade refuse a GitHub checksum that does not match. If GitHub omits
  the checksum, the downloaded file is still checked. The agent-behind
  banner is suppressed while a pin is set.

- **Help on controls**: an **i** next to stats, and hover text on page
  names and pool/settings buttons, so Drain, Pause AR, Outbox, Bundles, and
  the rest state what they do. Help cards sit below the control (or above
  if there is no room) so they stay on screen.

- **Settings sections**: GitHub, Pool, Alerts, Access, and Host are tabs
  instead of one long page. Save still writes every tab. Sticky Save bar
  stays at the bottom.

- **Responsive shell**: shared page gutter and max width so the top bar,
  banners, main, settings, and ops pages line up. Stats, charts, settings
  tabs, and tables adapt at 900 / 720 / 520 px.

- **CI on older hosts**: PHPUnit and boot smokes on Ubuntu 22.04 (x64 and
  arm) and macOS 15, distro `apt` PHP on Ubuntu 22.04 and Debian 12, and
  distro `dnf` PHP on Fedora, Rocky 9, and RHEL UBI 9 (`php-process` for
  posix/pcntl). The existing `ubuntu-latest` / `macos-latest` job names
  stay. GitHub no longer hosts macOS 13; macOS 14 retires 2 Nov 2026.
  PHPUnit now asserts posix/pcntl/`pdo_sqlite`, `/proc` RAM, listener
  pid checks, cgroup v2 writes, and linux/darwin/windows runner packages
  so those distro jobs fail if the host PHP is incomplete.

- **CI scanners**: Shellcheck on `run.sh` and `bin/*.sh`, gitleaks on PRs,
  Trivy on the GHCR image at release, and Playwright screenshot snapshots
  of the README/landing stills (dark mode) so UI drift fails the e2e job.

### Changed

- Split `src/` into PSR-4 folders that match namespaces: `Core`, `GitHub`,
  `Host`, `Pool`, `Alert`, `Ui`, and `Http` (router plus one class per
  `api.php` action). Autoload is still `RunnerDeck\` → `src/`.

- **Header**: sticky bar, brand links home, version on every page, org/label
  chip next to Log out, and a single-row page nav that scrolls on a narrow
  screen. Page help is on the link itself, not an extra **i** per item.

- **Landing page**: platform table matches current CI (Ubuntu 22.04,
  Debian 12 apt, Fedora / Rocky / RHEL UBI dnf, macOS 15), and the feature
  list covers tabbed Settings plus history / crashes / outbox / bundles.

- **README and landing stills**: dashboard, live log, Add Runner, and
  Settings screenshots are dark mode (header, tabs, and current Settings).

- **E2E CI**: Playwright runs in the pinned `mcr.microsoft.com/playwright`
  image so screenshot pixels match the stills. Chromium is not installed
  via `--with-deps` on the GitHub-hosted Ubuntu image.

## [1.9.0] - 2026-10-24

### Added

- **Prometheus `/metrics.php`**: unauthenticated scrape of local pool size,
  pressure, queue cache, and webhook outbox. No GitHub, no start, no webhook.

- **Webhook retry**: failed crash-webhook POSTs persist in
  `storage/webhook_outbox.json` and retry with backoff. Success drops the
  item. Settings Test is not retried. Not flushed from `health.php` or
  `/metrics.php`.

- **Syslog**: optional RFC5424 datagram of crash/alert events to `/dev/log`
  (or `RUNNERDECK_SYSLOG_SOCKET`). Fail-open if the socket is missing.

- **Per-slot cgroup caps**: optional CPU percent and memory MiB on start
  (Linux cgroup v2). Missing cgroup or permissions fail open.

- **GitHub App**: optional App ID, installation ID, and PEM private key
  (Settings, `storage/github_app.pem`) mint an installation token for
  `gh api` so this host does not need a personal `gh auth login`.

- **gh auth stale**: dashboard banner when GitHub returns 401/403, expired
  token, SSO, or missing permissions — including a failed GitHub App mint.

- **Failed job flag**: row badge when the last completed GitHub job on
  that slot failed or timed out (from the queued-jobs cache). A later
  success clears it. GET status does not call GitHub for this.

- **Host disaster-recovery bundle**: download/restore this host’s settings,
  slot flags, Pause AR, agent roster, host mode, and 7-day crash events as
  one JSON file. TOTP is never included; the crash webhook URL is omitted
  unless you opt in. Restore does not copy runner binaries, `_work`, or
  `gh` credentials. Import of host mode does not fire the webhook.

- **Watch heartbeat**: when auto-restart, scale-to-queue, idle-stop,
  queue/long-job alerts, or ephemeral slots are on, the dashboard warns
  if `watch-auto-restart.php` has not ticked in ~20 seconds.

- **Auto-clear `_work`**: optional `RUNNERDECK_AUTO_CLEAR_WORK` deletes
  the work tree after idle-stop or drain-stop only (not a regular Stop).
  Refuses if the process is still running.

- **Host-mode webhook**: the crash webhook fires once when this host
  first enters maintenance or `reboot_ready`. Not sent from `health.php`.

- **Long job alert**: optional `RUNNERDECK_LONG_JOB_MINUTES` fires the
  crash webhook once per in-progress GitHub job on this host that has
  run that long.

- **Agent behind**: dashboard banner and row flag when a slot's local
  GitHub Actions runner package is older than GitHub's current download
  for this OS/arch (cached 1 hour). Use Upgrade agents.

- **Settings export/import**: download a JSON copy without the TOTP
  secret or crash webhook URL; import overlays recognised keys and never
  replaces login.

### Security

- Pin `fast-uri` 3.1.9 so CI `npm audit` is not blocked by
  GHSA-w74x-v6f9-j3xg / GHSA-xhq7-xmqj-769h / GHSA-wjr9-j795-jxcr /
  GHSA-333x-w4r6-6crp (dev lint tree only).

### Changed

- Pull requests run ChangeRadar 0.2.5 via the published Action
  (`AnikethTS/ChangeRadar@531ea25`). JAR is GitHub's latest release
  unless `version` is set. Dependabot moves that Action SHA daily, so a
  later ChangeRadar tag does not need a hand edit.

## [1.8.0] - 2026-10-22

### Security

- Pin the Docker/Alpine smoke image to **PHP 8.5.12** (`php:8.5.12-cli-alpine`)
  for CVE-2026-109618 / CVE-2026-109619. CI unit/e2e/lint jobs use 8.5.12
  and 8.3.36. `php bin/doctor.php` warns when the host runtime is still
  below those floors, or is PHP 8.1 (EOL, no official patch). Operators
  still patch the host PHP packages themselves.

### Added

- **Safe reboot**: drain-stop the pool and set `reboot_ready` so you can
  reboot the machine yourself. Does not run `reboot`. Starting a runner
  clears the banner. `health.php` `ready` is false while this is set.

- **Queue wait alert**: optional `RUNNERDECK_QUEUE_WAIT_MINUTES` fires
  the crash webhook once when a job on this label has waited that long.

- **Host pressure webhook**: the crash webhook fires once when disk/RAM
  floors start refusing starts. Not sent from `health.php`.

- **Stuck busy**: GitHub `busy` with no local process for three polls
  flags the row. **Force idle** DELETEs the GitHub runner and clears
  local registration; files stay; the next Start re-registers.

- **Host pressure**: refuse Start / add / scale-to-queue when the pool
  disk or Linux MemAvailable is below a floor (default 1 GiB / 256 MiB,
  `0` = off). Running jobs are not stopped. Dashboard banner,
  `php bin/doctor.php`, and `health.php` `ready` / `pressure`.

- Opt-in **idle stop**: after N GitHub-idle minutes, stop the slot
  (`RUNNERDECK_IDLE_STOP_MINUTES`). Skips busy and Pause-AR slots, and
  does nothing while jobs are queued for this label.

- **Pool cap** (`RUNNERDECK_POOL_CAP`): never start more than N running
  slots (Start, add, scale-to-queue).

- **Per-slot extra labels** on Add runner, merged with the pool label
  and Settings extras on `config.sh`.

- Busy/idle rows can show the **last completed GitHub jobs** (name,
  conclusion, duration) from the queued-jobs walk.

- **GitHub runners not on this host**: names this machine registered
  that have no local slot, with Remove from GitHub.

### Changed

- Pull requests run ChangeRadar 0.2.2 via the published Action
  (`AnikethTS/ChangeRadar@5c888d9c`). Artifact, sticky comment, and
  Checks notices come from the Action. JAR is GitHub's latest release
  unless `version` is set. Fail-open (no `--policy`). Do not upload
  SARIF to Code Scanning.

## [1.7.0] - 2026-10-20

### Added

- Busy rows show the **live GitHub job** (name, repo, link) from the
  queued-jobs walk — not the 5s status poll.

- Opt-in **scale to queue**: `watch-auto-restart.php` starts stopped
  slots when jobs are waiting on this label (`RUNNERDECK_SCALE_TO_QUEUE`).

- **Ephemeral** slots (`config.sh --ephemeral`): one job, then
  RunnerDeck re-registers and starts the slot again. Not treated as a crash.

- **Upgrade agents** drains one slot at a time, replaces the local
  GitHub Actions runner package, and brings it back.

- **Maintenance** drain-stops the pool and refuses starts until you resume.

- Dashboard **Queued jobs** panel: GitHub jobs waiting on this pool's
  label, with a start-idle or add-runner hint. Polled separately from
  status (~25s disk cache). Org scope scans a few recently pushed
  repos; does not auto-start slots.

- Settings shows a **This host** card: Host, bind, HTTPS, php -S vs
  php-fpm, TOTP, and TRUST_PROXY — the same facts as the hosting banners,
  always visible (including on localhost).

- Pull requests run ChangeRadar 0.1.0 (`base.sha`..`head.sha`): upload
  `report.md` / `report.json` and post a sticky bot comment with the
  report and logo. Fail-open (no `--policy`). Pinned to
  `AnikethTS/ChangeRadar@38b9ae98fd47ae15f679ca442dc0af0d17f9954a`
  (`v0.1.0`).

- Per-slot **Pause AR** so a flaky runner stays down while pool-wide
  auto-restart stays on. Resume from the same button.

- Drain buttons show remaining seconds and busy/idle from the existing
  GitHub poll while waiting.

- Rolling restart: drain-stop then start one configured slot at a time
  (row, selected, or all). Honors drain timeout; `force=1` skips the wait.

- Dashboard, Settings, and login show hosting warnings when the Host is
  not localhost or the request is HTTPS: missing TLS, TOTP as the only
  gate, `php -S` instead of php-fpm, and `RUNNERDECK_TRUST_PROXY` from a
  non-loopback client. `php bin/doctor.php` warns on a non-loopback bind.

## [1.6.0] - 2026-10-13

### Changed

- Dashboard rows, stats, and the recent-log panel are painted in PHP. Polls
  request `action=status&view=1` and swap that markup in; JavaScript no
  longer duplicates the table HTML. Log out is a POST form (`logout.php`).
- Settings is a narrower card layout with a sticky Save bar. Login session
  actions are separate forms (no longer nested in Save).
- Last-hour CPU/RAM charts show axis ticks (% or MB), the latest sample,
  and the hour's min–max — not only an unlabeled line.

### Added

- Recent log can filter by level (all / error / info) and clear the file
  (`action=clear_applog`, CSRF). Clear truncates current plus rotated
  logs and writes one `log.cleared` info line.
- Dashboard GitHub cell shows the runner OS from the existing list-runners
  payload (no extra API call).
- Settings / `RUNNERDECK_EXTRA_LABELS` adds extra GitHub runner labels on
  add and rename (comma-separated, next to the shared pool label).
- The runner filter matches GitHub labels as well as name and agent version.
- **Download** on the dashboard Recent log panel saves `runnerdeck.log`
  (and `runnerdeck.log.1` after rotation).

### Fixed

- Header logo ships in the repo (`public/assets/logo.png`). A fresh clone
  shows it next to RunnerDeck; it was gitignored so clone-and-run had no
  image.
- CI pins `setup-php` 2.40.0 so macOS jobs can install PHP again
  (`macos-latest` no longer works with 2.37.2).
- Pin `source-map-js` 1.2.2 for
  [GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q).
  `npm audit fix --force` is still not used (it would install stylelint 7.7.0
  for the unpatched `braces` advisory).

## [1.5.0] - 2026-10-06

### Security

- Document that `gh`, runner dirs, and `_work` live on the host: a VPS
  compromise is an org/repo compromise. TOTP is not the only gate on
  443 — Tailscale, or [deploy/Caddyfile.restricted](deploy/Caddyfile.restricted)
  / [deploy/nginx-restricted.conf.example](deploy/nginx-restricted.conf.example)
  (IP allowlist and/or mTLS).

- Non-loopback binds and non-loopback requests require TOTP in code
  (`run.sh` exits; PHP returns 403 except `/health.php`). Recovery codes
  and **Sign out other sessions** cover a lost authenticator and a stolen
  cookie. `/health.php` no longer includes the version string.

### Fixed

- CI `npm audit` no longer fails solely on [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
  (`braces` through 3.0.3, no patched npm release). `npm audit fix --force`
  would install stylelint 7.7.0. Drop the allowlist in
  `scripts/npm-audit.mjs` once a fixed `braces` can be pinned.

### Added

- **php-fpm** examples for a TLS hostname (`deploy/php-fpm.conf`,
  Caddy/nginx FastCGI, systemd watch + fpm units). `./run.sh` stays
  `php -S` (four workers; a live log occupies one) for one operator.

- **TLS reverse proxy** examples for Caddy and nginx (`deploy/`,
  [docs/hosting.md](docs/hosting.md)). `GET /health.php` is a probe that
  skips TOTP and does not include the version. `X-Forwarded-Proto` is trusted from loopback; set
  `RUNNERDECK_TRUST_PROXY=1` when the proxy is not on loopback. HTTPS
  responses send HSTS.

- Drain timeout is a Settings field (`RUNNERDECK_DRAIN_TIMEOUT`, 1–3600
  seconds, default 600) so a short drain wait is not a `.env` edit.
- A **Pool disk** stat card sums per-slot `_work` / logs. An optional
  Settings / `RUNNERDECK_DISK_WEBHOOK_THRESHOLD` (GiB) fires the crash
  webhook once when a slot or the pool first crosses that size.
- The runner table can be filtered by **idle / busy / crashed /
  mismatch** in addition to the name box, using the status snapshot.
- Each runner row shows the **local agent version** (from `.runner` or
  `Runner.Listener.deps.json`) so a stale binary next to a healthy
  process is visible without extra GitHub calls.
- Optional **idle session timeout** when TOTP login is on
  (`RUNNERDECK_SESSION_IDLE_MINUTES`, Settings → Login). Hidden tabs
  do not poll, so they count as idle.
- Successful **start / stop / delete / rename** (and drain / clear-work /
  settings saves, without secrets) are written to `storage/runnerdeck.log`
  (`level: info`).
- **Stop** waits for the process to exit after SIGTERM, then SIGKILL.
- A persistent local/GitHub **mismatch** fires the crash webhook once.
- Live tails and log downloads include `runner.log.1` after rotation.
  Oversized `runner.log` is rotated on start/stop, not while the listener
  is still writing to the file.

## [1.4.0] - 2026-09-26

### Added

- The dashboard shows a **Recent errors** panel from `storage/runnerdeck.log`
  (start/provision/`gh` failures already written there, redacted).
- Settings has a **Test** button next to the crash-loop webhook URL field —
  sends a clearly-labeled test notification to whatever's currently typed,
  without needing to save first, so Slack/Discord/generic delivery can be
  confirmed before relying on it.
- Crash history: a small SQLite table (`src/CrashHistory.php`, same
  database as CPU/RAM history) counts crashes per runner over the last 7
  days, independent of the live `crash_flagged` state, so a flaky runner
  that keeps getting auto-restarted still shows up. The dashboard table
  shows it as a "N crashes (7d)" badge when non-zero. Clicking the badge
  lists timestamps; the Local column can be sorted by that count. An
  optional Settings threshold fires the same webhook when the count first
  reaches N in 7 days, not only on a crash-loop.
- `run.sh` starts `bin/watch-auto-restart.php` beside the PHP server so
  auto-restart still POSTs `start` when no dashboard tab is open.
  `action=status` stays a GET that does not spawn processes.
- **Drain** waits until GitHub `busy` is false (default 600s,
  `RUNNERDECK_DRAIN_TIMEOUT`) then SIGTERM. Row/bulk/all buttons, API
  `drain_stop` / `drain_stop_all` / `bulk_drain_stop`, and
  `php bin/drain-stop.php`.
- Per-slot disk (`_work`, `_diag`, `runner.log`) on the Local column,
  sortable. **Clear work** on a stopped runner removes `_work`.
- `runner.log` rotates to `runner.log.1` at 5 MB (on start and on each
  status inspect) so slot logs do not grow forever.

### Security

- Session cookies always go through `Session::start()`: `HttpOnly`,
  `SameSite=Lax`, and `Secure` when the request is HTTPS (including
  `X-Forwarded-Proto` behind a TLS proxy). Successful TOTP login still
  calls `session_regenerate_id(true)`.
- PHP responses send `X-Frame-Options: DENY`, `X-Content-Type-Options:
  nosniff`, `Referrer-Policy: no-referrer`, and a CSP that allows only
  same-origin assets plus a per-request script nonce.

### Changed

- Landing page and README screenshots retaken from a live pool (dashboard,
  live log, add runner, Settings), plus a short demo GIF.
- First-run setup is a normal POST to `index.php` (same `SaveSettingsAction`
  path as Settings), with errors rendered in the page instead of `alert()`.
- The dashboard paints stats, health banner, and runner rows in PHP on the
  first response so the table is not empty until JavaScript runs.
- PHPStan is at level 8.
- Status polls (5s) pause while the dashboard tab is hidden, then fetch
  once when it becomes visible again. Headless auto-restart is a sibling
  process from `run.sh`, not the hidden-tab poll. Whole-machine CPU/RAM
  comes from the same snapshot (`action=system` is still available for
  scripts).
- Dashboard snapshots call `gh api --paginate` for runners first and only
  run `gh auth status` when that fails, so a healthy poll is one GitHub
  CLI process instead of two. Pages beyond GitHub's default 30 are
  included (`per_page=100`).
- The runner table is not rebuilt from `innerHTML` when the rows, filter,
  sort, and selection have not changed, so a 5s poll does not steal
  focus from the filter box.
- CI splits PHP/JS lint, trims the PHPUnit matrix to Ubuntu 8.1/8.5 and
  macOS 8.5, smokes PHP 8.1 on the host and PHP 8.5 Alpine (same digest as
  the Dockerfile), asserts Docker env/libs, runs `actionlint` and
  `npm audit`, and publishes `ghcr.io/anikethts/runnerdeck` on version tags.

### Fixed

- Linux provision now runs GitHub's `bin/installdependencies.sh` after
  extract (skipped on Alpine; the Docker image installs those libs via
  `apk` instead) so a bare host gets libicu/libkrb5 instead of a missing
  shared-library error when the runner binary starts.
- Delete renames the slot out of the pool before `rm -rf` (60s timeout),
  so a timed-out delete cannot leave a half-wiped directory that still
  looks like a runner.
- Docker: `RUNNER_ALLOW_RUNASROOT=1` so `config.sh` can run as root;
  `docker.md` no longer suggests in-container `gh auth login` while the
  compose file mounts `~/.config/gh` read-only.

## [1.3.0] - 2026-09-21

### Added

- Optional crash-loop webhook (`RUNNERDECK_CRASH_WEBHOOK_URL`, Settings):
  POSTs a notification the moment a crash-loop is flagged, so it's not
  only visible if a dashboard tab happens to be open. Slack and Discord
  incoming webhook URLs are detected automatically and get their native
  payload shape; anything else gets a plain JSON payload.

### Changed

- Settings moved from a modal on the dashboard to its own page
  (`settings.php`), matching the existing `login.php`/`totp_setup.php`
  pattern: a server-rendered form. Reflows properly on mobile instead of
  a fixed-width dialog, and removes the settings-modal open/close/backdrop
  JS entirely.
- Local/GitHub mismatch streaks are counted in `CrashState`
  (`mismatch_flagged`) rather than in JavaScript. CPU/RAM history charts
  are drawn as SVG in PHP and embedded in the status snapshot, so the UI
  dropped its extra `action=history` poll. Theme preference is a cookie
  so the first paint can set `data-theme` without a flash.

### Fixed

- The light/dark toggle and cookie-backed `data-theme` now apply on
  Settings (and login / TOTP setup), not only the dashboard. Shared
  chrome lives in `src/Layout.php`; `assets/js/theme.js` is loaded on
  those pages without pulling in the rest of the dashboard JS.

- TOTP login lockouts now show the exact remaining wait time instead of a vague "few minutes" message.
- `ApiTest`, `ProvisionerTest`, `ProcessControlTest`, and `CrashWebhookTest`
  didn't isolate `RUNNERDECK_SETTINGS_FILE`, so their failure-path fixtures
  were writing real entries into this repo's own `storage/runnerdeck.log`
  on every `composer run test`. No behavior change, test-only.

### Security

- `action=status` (a GET, which has no CSRF check by design) briefly
  called `ProcessControl::startIndividual()` directly for auto-restart,
  meaning any page loaded in the same browser — a plain `<img src>` to
  that URL — could make RunnerDeck spawn a real runner process with no
  user interaction and no token. Reverted: the GET only ever decides
  `should_auto_restart`; the client still has to `POST action=start`
  (CSRF-protected) for the restart to actually happen. Added a regression
  test (`DashboardTest::testSnapshotNeverSpawnsAProcessEvenWhenAutoRestartShouldFire`)
  asserting `Dashboard::snapshot()` never spawns a process, so this can't
  silently come back.

## [1.2.0] - 2026-09-16

### Added

- Failures from `gh`, `config.sh`, and start/stop are appended as JSON
  lines to `storage/runnerdeck.log` (rotated into `runnerdeck.log.1` at
  256 KiB). Secrets (TOTP, `gh` tokens, `--token` values) are stripped
  before write. Polling `gh` errors are throttled to once a minute so a
  down API cannot fill the disk.
- Unit tests for start/stop/provision: `ProcessDecision` covers pidfile vs
  live-listener decisions, and `ProcessControl`/`Provisioner` run against
  temp dirs with a fake `Shell` so checksum failure, config.sh, and
  start/stop never spawn a real runner or call GitHub.
- The log download can now be narrowed to a time range (`from`/`to`
  fields in the log viewer) instead of only the full file. Filters by
  whatever timestamps the runner's own console output includes;
  untimestamped lines (most job output) inherit the last-seen timestamp.
- **RunnerDeck's first community-contributed features**, all via
  `up-for-grabs` issues:
  - An **Export JSON** toolbar button downloads the full current runner
    list — unaffected by the filter box — as a timestamped `.json` file.
  - `/` focuses the runner filter and `r` triggers Refresh, guarded
    against firing while typing in an editable field, modifier-key
    combinations, IME composition, and key-repeat.
  - The live log viewer gained a search field that highlights matches as
    you type, including newly streamed lines — built with text nodes and
    `<mark>`, never `innerHTML`, so log content stays safe either way.
  - A web app manifest and bundled icons (from `.github/logo.png`) let
    supported browsers install RunnerDeck via "Add to Home Screen" —
    browser- and HTTPS-dependent, no service worker or offline support.
  - The "Local process" column header now has independent CPU and
    Uptime sort controls, instead of CPU-only.
- `API.md`: a full reference for `api.php`'s actions plus the two
  standalone log endpoints, including how to get a CSRF token from a
  script instead of a browser session (`action=csrf_token`).
- A `Dockerfile` and `docker-compose.yml`, as an alternative to the
  PHP-CLI/WSL2 path — see the README's [Docker](README.md#docker) section.
  Runner pool/settings/history relocate to a mounted `/data` volume; the
  container binds `0.0.0.0` internally (only there — every other install
  still binds `127.0.0.1`) with the host-side port mapping pinned to
  `127.0.0.1:8090:8090` to stay loopback-only end to end. Runner jobs that
  need their own `docker` command need DinD or a mounted socket, neither
  of which this image sets up. Podman works as a drop-in (`podman compose
  up --build`) — the volume mounts carry a `:z` SELinux relabel option for
  rootless Podman on SELinux-enforcing distros; Docker ignores it
  elsewhere.
- Optional TOTP login, for anyone hosting their instance somewhere beyond
  localhost behind a TLS-terminating reverse proxy — see the README's
  [Hosting remotely](README.md#hosting-remotely) section. Set up either via
  `php bin/setup-totp.php` or **Settings → Login** in the dashboard itself.
  Off by default, zero behavior change for the existing localhost-only
  setup. Single-user only, no accounts/roles. A dependency-free RFC 6238
  implementation (`src/Totp.php`) verifies codes; 5 wrong codes in a row
  locks login out for 5 minutes (`src/Auth.php`). The secret is stored the
  same way as every other setting, in `storage/settings.json`.
- CI/CD hardening: `main` now requires all CI checks to pass (and blocks
  force-pushes) before anything can merge — previously nothing enforced
  that. Workflows also gained `timeout-minutes` on every job,
  `concurrency`-based cancellation of superseded runs on rapid pushes, and
  dependency caching (`composer.lock`/`package-lock.json` are now
  committed so `setup-php`/`setup-node` can cache reliably, and `npm ci`
  replaces `npm install` in CI). The Docker smoke test now builds via
  `docker/build-push-action` with GitHub Actions layer caching instead of
  a from-scratch `docker build` every run.

- Crash-loop detection and auto-restart now live server-side
  (`src/CrashState.php`), instead of independently in every open browser
  tab. `action=status` returns `crash_flagged`/`just_flagged`/
  `should_auto_restart` per runner (see `API.md`); the client reacts to
  these instead of tracking its own state. A toast notification now fires
  the moment a runner is flagged as crash-looping, in addition to the
  existing row badge — previously the only signal was that inline badge,
  easy to miss if you weren't looking at that row. The auto-restart toggle
  moved from an unbound toolbar checkbox (persisted only via
  `localStorage`, per-browser) to a real setting
  (`RUNNERDECK_AUTO_RESTART`, Settings dialog), consistent everywhere.

### Changed

- PHP classes live in the `RunnerDeck\` namespace, loaded by a small
  `spl_autoload_register` in `src/bootstrap.php` (`RunnerDeck\Foo` →
  `src/Foo.php`). Composer is still dev-only (PSR-4 autoload-dev for tests).
  HTTP URLs and JSON bodies are unchanged.
- `setup-php` in CI now gets `GITHUB_TOKEN` so unauthenticated GitHub
  rate limits are less likely to stall PHP downloads on macOS. Boot-smoke
  job timeout is 15 minutes (was 5) so a slow macos-14 PHP install cannot
  cancel the required check.
- `public/api.php` is a thin front controller. Each `action=` is a
  handler class under `src/Api/`, dispatched by `src/Api.php`. URLs,
  CSRF, auth gating, and response bodies are unchanged.

### Fixed

- The live log viewer now stops reconnecting and returns to the login page
  when a TOTP-authenticated session expires.

## [1.1.0] - 2026-09-12

### Added

- `bin/doctor.php`, a preflight check for PHP version/extensions, `bash`/
  `curl`/`tar`, `gh` install and auth status, GitHub API access for the
  configured scope, and pool/storage directory permissions.
- CI security hardening: `composer audit` in the lint job, a Semgrep static
  analysis pass (`p/security-audit` + `p/php`), and a Dependency Review
  check on PRs. Enabled Dependabot security updates on the repo.
- JS/CSS dev tooling (`package.json`, dev-only, same as Composer): eslint
  and stylelint, plus a Playwright E2E suite (`e2e/dashboard.spec.js`)
  covering first-run setup, the filter box, sorting, bulk-select, and the
  status-mismatch flag against a real browser and a real PHP backend.
- `run.sh` now prints the history DB's resolved path, whether
  `pdo_sqlite` is available, and whether it's writable, right alongside
  the "RunnerDeck on http://..." startup line.
- The history chart now says so when `pdo_sqlite` isn't installed,
  instead of showing "collecting data…" forever with no explanation.
- Two new stat cards, **System CPU** and **System RAM**, showing
  whole-machine usage (`src/SystemStats.php`) alongside the existing
  per-runner stats.
- An opt-in **update check** (`RUNNERDECK_CHECK_UPDATES`, off by
  default): compares this install's `VERSION` file against this
  project's latest GitHub Release and shows a header badge if a newer
  one exists. Toggle it from Settings. `src/UpdateCheck.php`.

### Changed

- The CPU/RAM history SQLite file now lives at `storage/db/history.sqlite`
  instead of directly under `storage/`, alongside `settings.json`.
- `public/assets/app.js` (826 lines, one IIFE) is now split into ES
  modules under `public/assets/js/` (api, utils, reliability, table,
  history-chart, stats, modals), with `app.js` as the entry point.
  No build step involved — browsers load ES modules natively. Purely
  structural; no behavior change.
- `Dashboard::snapshot()` was hitting GitHub's runners API twice per
  poll — once as an access probe inside `GithubClient::authStatus()`
  whose result was thrown away, once for real in `listRunners()`.
  Removed the redundant call (`GithubClient::checkLogin()` now covers
  the login check; `listRunners()` doubles as the access check).
  `authStatus()` still exists, probe included, for `bin/doctor.php`'s
  one-shot use. The auto-refresh interval also dropped from 12s to 5s,
  safe now that each poll costs about half the GitHub API calls it did.
- The System CPU/RAM stat cards now poll independently every 2s via a
  new `action=system` endpoint, instead of waiting on the main 5s
  runner/GitHub poll — they're pure local `ps`/`nproc`/`/proc` reads
  with no GitHub API cost, so there's no reason to cap them at the
  same interval as data that does.

### Fixed

- `api.php?action=status` would hard-crash instead of returning a clean
  JSON error if `gh` was authenticated but RunnerDeck itself wasn't
  configured yet — found while building the E2E harness.

## [1.0.0] - 2026-09-11

First tagged release. Everything below had already shipped to `main`
incrementally; this tag marks the point where RunnerDeck is considered
stable enough to depend on a specific version of.

### Added

- Runner lifecycle management: add, rename, and delete runners from the UI,
  individually or in bulk (checkbox multi-select with bulk start/stop/delete).
- Live status for each runner: GitHub-reported state (online/offline,
  busy/idle) side by side with the actual local process state, live
  CPU/RAM/uptime, and a rolling one-hour history chart backed by a local
  SQLite file.
- A row is flagged when local and GitHub-reported status disagree for
  several consecutive polls, and an optional auto-restart can bring a
  crashed runner back up on its own, with backoff.
- Live log tailing per runner, a filter box for the runner table, and a
  full log download separate from the tail.
- Org-scoped or single-repo-scoped runner management, configurable from an
  in-UI first-run setup screen and a Settings dialog — no `.env` file
  required to get started.
- Cross-platform: native on Linux and macOS, Windows via WSL2, CI-tested
  down to Alpine/musl.
- CSRF-protected API, SHA-pinned GitHub Actions, and Dependabot for both
  Composer and Actions dependencies.
