# Changelog

All notable changes to RunnerDeck are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versioning
follows [SemVer](https://semver.org/).

## [Unreleased]

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

### Changed

- The CPU/RAM history SQLite file now lives at `storage/db/history.sqlite`
  instead of directly under `storage/`, alongside `settings.json`.

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
