# Configuration

Three layers, highest priority first: **real environment variables** (e.g.
a systemd unit's `Environment=`) → **`.env`** (see `.env.example`) →
**settings saved through the UI** (`storage/settings.json`). You only need
one of these — most people will just use the in-app setup screen:

| Variable | Required | Default | Meaning |
|---|---|---|---|
| `RUNNERDECK_SCOPE` | no | `org` | `org` to manage org-level runners, or `repo` to scope runners to a single repo instead |
| `RUNNERDECK_ORG` | only if `scope=org` | — | GitHub org the runners belong to |
| `RUNNERDECK_REPO` | only if `scope=repo` | — | `owner/repo` the runners belong to |
| `RUNNERDECK_LABEL` | no | `self-hosted-runnerdeck` | Shared label across the pool; also `runner-base`'s own registered name. The dashboard queued-jobs panel counts GitHub jobs whose labels include this value |
| `RUNNERDECK_EXTRA_LABELS` | no | off | Comma-separated extra GitHub labels applied with the shared label on add/rename. Per-slot extras on Add runner are stored in the slot and merged on `config.sh`. Settings → Repository |
| `RUNNERDECK_POOL_DIR` | no | `<repo>/../runners` | Where `runner-base`, `runner-1`, ... live |
| `RUNNERDECK_GH_BIN` | no | auto-detected | Explicit path to `gh`, if it's not resolvable from PATH in whatever context launches `run.sh` |
| `RUNNERDECK_CHECK_UPDATES` | no | off | `1` to enable the header's "update available" check against this project's own GitHub Releases |
| `RUNNERDECK_AUTH_TOTP_SECRET` | no | off | Set via `php bin/setup-totp.php` or **Settings → Login** in the dashboard, not by hand — requires an authenticator app code to use the dashboard or API. See [Security & safety](security-and-safety.md#hosting-remotely) |
| `RUNNERDECK_SESSION_IDLE_MINUTES` | no | off | Whole number 1–1440. When login is on, the session ends after this many minutes with no requests. Hidden dashboard tabs do not poll, so they count as idle. Settings → Login |
| `RUNNERDECK_AUTO_RESTART` | no | off | `1` to automatically restart a runner that crashed unexpectedly (up to 3 attempts before giving up and flagging it). Toggle from Settings |
| `RUNNERDECK_SCALE_TO_QUEUE` | no | off | `1` to start stopped slots when GitHub jobs are waiting on this label (`bin/watch-auto-restart.php`). Settings → Automation |
| `RUNNERDECK_POOL_CAP` | no | off | Whole number 1–30. Never start more running slots than this (Start, Start All, add, scale-to-queue). Settings → Automation |
| `RUNNERDECK_IDLE_STOP_MINUTES` | no | off | Whole number 1–1440. `watch-auto-restart.php` stops a slot that has been GitHub-idle this long. Skips busy and Pause-AR slots; does nothing while jobs are queued. Settings → Automation |
| `RUNNERDECK_AUTO_CLEAR_WORK` | no | off | `1` to delete `_work` after idle-stop or drain-stop (including drain-all, maintenance, and safe reboot). Regular Stop leaves the tree. Refuses if the process is still running. Settings → Automation |
| `RUNNERDECK_QUEUE_WAIT_MINUTES` | no | off | Whole number 1–1440. Fire the crash webhook once when a job waiting on this label has been queued this long. Settings → Automation |
| `RUNNERDECK_LONG_JOB_MINUTES` | no | off | Whole number 1–1440. Fire the crash webhook once when an in-progress job assigned to a runner on this host has run this long. Settings → Automation |
| `RUNNERDECK_MIN_FREE_DISK_GIB` | no | `1` | Refuse Start / add / scale-to-queue when the pool filesystem has less than this many GiB free. `0` = off. Unknown disk fails open. Settings → Automation |
| `RUNNERDECK_MIN_FREE_RAM_MIB` | no | `256` | Refuse starts when Linux `MemAvailable` is below this many MiB. `0` = off. Unreadable RAM (macOS) fails open. Settings → Automation |
| `RUNNERDECK_CRASH_WEBHOOK_URL` | no | off | POSTed once per crash-loop onset, when a runner's 7-day crash count first reaches `RUNNERDECK_CRASH_WEBHOOK_THRESHOLD` if that is set, when disk first reaches `RUNNERDECK_DISK_WEBHOOK_THRESHOLD` GiB, when host pressure starts refusing starts, when a queued job first exceeds `RUNNERDECK_QUEUE_WAIT_MINUTES`, when an in-progress job first exceeds `RUNNERDECK_LONG_JOB_MINUTES`, and once when this host first enters maintenance or `reboot_ready`. Slack/Discord incoming webhook URLs are detected and get their native payload shape; anything else gets a plain JSON payload. Set from Settings |
| `RUNNERDECK_CRASH_WEBHOOK_THRESHOLD` | no | off | Whole number ≥ 1. When set, the crash webhook also fires once as a runner's 7-day crash count first reaches this value. Set from Settings |
| `RUNNERDECK_DRAIN_TIMEOUT` | no | `600` | Seconds to wait for GitHub `busy=false` before drain-stop or rolling restart gives up (1–3600). Settings → Automation, `drain_stop` / `rolling_restart` API actions, and `php bin/drain-stop.php` |
| `RUNNERDECK_DISK_WEBHOOK_THRESHOLD` | no | off | Whole number ≥ 1 (GiB). When set, the crash webhook also fires once as a slot or the pool total first reaches this size. Set from Settings |
| `RUNNERDECK_TRUST_PROXY` | no | off | `1` to honor `X-Forwarded-Proto` from a non-loopback reverse proxy. Same-machine proxy to `127.0.0.1:8090` or php-fpm on `:9000` already trusts that header. See [Hosting](hosting.md) |
| `RUNNERDECK_GITHUB_APP_ID` | no | off | GitHub App ID. With installation ID and `storage/github_app.pem`, `gh api` uses an installation token instead of `gh auth login`. Settings → GitHub App |
| `RUNNERDECK_GITHUB_APP_INSTALLATION_ID` | no | off | Installation ID for that app on this org or repo |
| `RUNNERDECK_GITHUB_APP_KEY_FILE` | no | `storage/github_app.pem` | PEM private key path. Never stored in `settings.json` |

The header uses `public/assets/logo.png` (shipped with the repo, same art as
`.github/logo.png`). Replace that file if you want your own mark.
