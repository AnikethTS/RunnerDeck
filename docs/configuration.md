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
| `RUNNERDECK_LABEL` | no | `self-hosted-runnerdeck` | Shared label across the pool; also `runner-base`'s own registered name |
| `RUNNERDECK_POOL_DIR` | no | `<repo>/../runners` | Where `runner-base`, `runner-1`, ... live |
| `RUNNERDECK_GH_BIN` | no | auto-detected | Explicit path to `gh`, if it's not resolvable from PATH in whatever context launches `run.sh` |
| `RUNNERDECK_CHECK_UPDATES` | no | off | `1` to enable the header's "update available" check against this project's own GitHub Releases |
| `RUNNERDECK_AUTH_TOTP_SECRET` | no | off | Set via `php bin/setup-totp.php` or **Settings → Login** in the dashboard, not by hand — requires an authenticator app code to use the dashboard or API. See [Security & safety](security-and-safety.md#hosting-remotely) |
| `RUNNERDECK_AUTO_RESTART` | no | off | `1` to automatically restart a runner that crashed unexpectedly (up to 3 attempts before giving up and flagging it). Toggle from Settings |

Optional: drop a `public/assets/logo.png` in to show a logo in the header —
it's gitignored and entirely optional, the dashboard works fine without one.
