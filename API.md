# API reference

RunnerDeck's UI is just a client of its own API — `public/api.php`, plus two
standalone endpoints (`public/log_stream.php`, `public/download_log.php`)
for streaming and downloading logs. Everything here is `127.0.0.1`-only by
default (see [Safety notes](README.md#safety-notes)); by default there's no
API key or user account, so "who can call this" is exactly "who can reach
this port" — unless you've enabled the optional TOTP login (see
[Hosting remotely](README.md#hosting-remotely)), in which case every action
below needs an authenticated session first (see **Auth**).

## Stability

No framework, no OpenAPI spec, no version prefix — this is Keep-a-Changelog
stability, not semver-in-the-URL stability: action names and the fields
documented below won't be renamed or removed without a `CHANGELOG.md` entry
under `### Changed`/`### Removed`, and new optional fields may be added to a
response at any time. If you're scripting against this, treat unknown
fields as forward-compatible noise, not an error.

## Auth

By default, GET actions need nothing — no session, no token. POST actions
require a CSRF token, since the only thing standing between this app and a
forged request from another browser tab is that token (see `src/Csrf.php`).
The UI gets one for free (embedded in `index.php` as `window.__CSRF__` on
every page load); a script that isn't rendering that page can fetch one
directly:

```bash
curl -sS -c cookies.txt 'http://127.0.0.1:8090/api.php?action=csrf_token'
# {"ok":true,"token":"<64 hex chars>"}
```

Reuse that cookie jar and pass the token back on every POST, either as a
`csrf_token` form field or an `X-CSRF-Token` header:

```bash
curl -sS -b cookies.txt -X POST 'http://127.0.0.1:8090/api.php?action=start' \
  -H "X-CSRF-Token: $TOKEN" \
  --data-urlencode 'runner=runner-base'
```

The token is session-bound and doesn't expire on its own, but a fresh
`action=csrf_token` call is cheap — don't bother caching it across scripts.

### If TOTP login is enabled

Every action except `action=csrf_token` returns `401` until the session is
logged in — get a token, log in with it, then reuse the same cookie jar for
everything else:

```bash
curl -sS -c cookies.txt 'http://127.0.0.1:8090/api.php?action=csrf_token'
# {"ok":true,"token":"<64 hex chars>"}

curl -sS -b cookies.txt -c cookies.txt -X POST 'http://127.0.0.1:8090/login.php' \
  --data-urlencode "csrf_token=$TOKEN" \
  --data-urlencode "code=$(totp-code-from-your-authenticator-app)"
# 302 to index.php on success; re-renders the login form (200) with an error on failure

curl -sS -b cookies.txt 'http://127.0.0.1:8090/api.php?action=status'
# now works — the session is authenticated
```

Five wrong codes in a row locks login out for 5 minutes, same as the UI
(`src/Auth.php`). There's no separate API token or key to mint — a script
authenticates exactly the way a browser does.

## Response shape

Every response is JSON with at least an `ok` boolean. A `false` response
almost always includes a human-readable `message`. HTTP status follows the
failure reason, not just 200-vs-not:

| Status | Meaning |
|---|---|
| `200` | success |
| `401` | TOTP login is enabled and this session isn't logged in — see **Auth** |
| `403` | missing/invalid CSRF token, or an opt-in feature (update checks) is disabled |
| `404` | unknown action, or an unknown runner id |
| `409` | the target runner(s) are busy (see **Busy checks** below), or the app isn't configured yet |
| `422` | bad input (missing/malformed required field) |

### Busy checks

`stop`, `restart`, `rename`, `delete_runner`, `stop_all`, `bulk_stop`, and
`bulk_delete` all check GitHub's `busy` flag before acting, and refuse
(`409`) if the runner(s) might be mid-job or that can't be verified. Pass
`force=1` (POST field or query string) to skip the check and act anyway —
the same escape hatch the UI's confirmation dialogs use.

## GET actions

### `action=status`

The full dashboard snapshot — this is what the UI polls every 5s.

- `lines` (optional, default `15`, max `500`) — log-tail lines per runner.
- Returns `409` if RunnerDeck itself isn't configured yet (`{ok: false, message: "..."}`).

```bash
curl -sS 'http://127.0.0.1:8090/api.php?action=status&lines=5'
```

```jsonc
{
  "generated_at": 1789160000,
  "health": { "logged_in": true, "org_access_ok": true, "message": "OK" },
  "runners": [
    {
      "id": "runner-base",
      "configured": true,
      "agent_name": "self-hosted-runnerdeck",
      "local_running": true,
      "pid": 4242,
      "log_tail": ["Listening for Jobs"],
      "cpu_percent": 1.2,
      "rss_kb": 204800,
      "uptime_seconds": 305,
      "github": { "status": "online", "busy": false, "labels": ["self-hosted-runnerdeck"] }
    }
  ],
  "stats": { "total": 1, "running": 1, "avg_cpu_percent": 1.2, "total_rss_kb": 204800 },
  "system": { "cpu_percent": 4.5, "cpu_cores": 8, "mem_used_kb": 3200000, "mem_total_kb": 16000000, "mem_percent": 20.0 }
}
```

`runners[].github` is `null` if GitHub has no record under that runner's
registered name yet (still provisioning, or access issue — see `health`).

### `action=log`

A runner's log tail (not the live stream — see `log_stream.php` below).

- `runner` (required) — a runner id (`runner-base`, `runner-1`, ...).
- `lines` (optional, default `200`, max `2000`).

```bash
curl -sS 'http://127.0.0.1:8090/api.php?action=log&runner=runner-base&lines=50'
# {"ok":true,"lines":["...","..."]}
```

### `action=history`

Rolling one-hour CPU/RAM history, sampled once per `action=status` call
(see [History](README.md)). `available: false` means `pdo_sqlite` isn't
installed — `samples` is always present but empty in that case.

```bash
curl -sS 'http://127.0.0.1:8090/api.php?action=history'
# {"ok":true,"available":true,"samples":[{"ts":1789159700,"avg_cpu":1.2,"total_rss_kb":204800}]}
```

### `action=system`

Whole-machine CPU/RAM (not just runners) — the same thing embedded in
`action=status`'s `system` field, exposed on its own since the UI polls it
independently every 2s (no GitHub API cost, so no reason to wait on the
slower runner poll).

```bash
curl -sS 'http://127.0.0.1:8090/api.php?action=system'
# {"ok":true,"system":{"cpu_percent":4.5,"cpu_cores":8,"mem_used_kb":3200000,"mem_total_kb":16000000,"mem_percent":20.0}}
```

### `action=check_updates`

Compares this install's `VERSION` against RunnerDeck's own latest GitHub
release. Returns `403` unless `RUNNERDECK_CHECK_UPDATES=1` is set — see
[Configuration](README.md#configuration).

```bash
curl -sS 'http://127.0.0.1:8090/api.php?action=check_updates'
# {"ok":true,"current":"1.1.0","latest":"1.1.0","update_available":false,"error":null}
```

### `action=csrf_token`

See **Auth** above. The one action that stays reachable even when TOTP
login is enabled — it's how a script bootstraps into `login.php` in the
first place.

## POST actions

All of these need the CSRF token (see **Auth**) and, except `save_settings`
and `add_runner`, take `runner` (a single id) or `runners` (comma-separated
ids for the `bulk_*` actions).

| Action | Required fields | Notes |
|---|---|---|
| `save_settings` | `scope` (`org`/`repo`), `org` or `repo`, `label` (optional), `check_updates` (`1` or omitted) | Persists to `storage/settings.json` |
| `logout` | — | Ends the current session; a no-op response if TOTP login isn't enabled |
| `totp_begin` | — | Generates a pending TOTP secret (not saved yet), returned as `secret` and an `otpauth://` `uri`. Reachable without login only while login isn't enabled yet — see [Hosting remotely](README.md#hosting-remotely) |
| `totp_confirm` | `code` | Verifies `code` against the pending secret from `totp_begin`; on success, saves it and logs the session in. `422` on a wrong code |
| `start` | `runner` | No busy check — starting is never destructive |
| `stop` | `runner` | Busy-checked |
| `restart` | `runner` | Busy-checked |
| `rename` | `runner`, `name` | Busy-checked; deregisters and re-registers under the new name |
| `add_runner` | `name` (optional — auto-named if blank) | |
| `delete_runner` | `runner` | Busy-checked; deletes local files, deregisters from GitHub — no undo |
| `start_all` | `count` (optional, default `10`, max `30`) | |
| `stop_all` | — | Busy-checked across the whole pool in one GitHub call |
| `bulk_start` | `runners` | |
| `bulk_stop` | `runners` | Busy-checked across the selection in one GitHub call |
| `bulk_delete` | `runners` | Busy-checked; no undo |
| `resize` | `count` (optional, default `10`, max `30`) | Same as `start_all` |

Every action here responds `{"ok": true, "message": "..."}` on success (or
`{"ok": true, "id": "runner-2", "message": "..."}` for `add_runner`
specifically). Example:

```bash
curl -sS -b cookies.txt -X POST 'http://127.0.0.1:8090/api.php?action=stop' \
  -H "X-CSRF-Token: $TOKEN" --data-urlencode 'runner=runner-base'
# {"ok":true,"message":"runner-base stopped (pid 4242)"}
```

## Log streaming and download

These aren't under `api.php` — they're their own front controllers.

### `GET /log_stream.php?runner=<id>`

Server-Sent Events. No auth beyond a valid `runner` id — this is a
long-lived `text/event-stream` connection (the UI's live log viewer uses
it directly via `EventSource`), not something most scripts need over the
plain `action=log` tail. If TOTP login is enabled, an unauthenticated
request gets a plain `401 unauthorized` body instead of a stream.

### `GET /download_log.php?runner=<id>&from=<epoch>&to=<epoch>`

The full log file, or a slice of it. `from`/`to` are optional Unix epoch
seconds; omit both for the complete file. See the log viewer's "Download
from / to" fields for the UI equivalent, and `public/download_log.php`
for exactly how partial-timestamp lines are handled. Same `401` behavior
as `log_stream.php` above when TOTP login is enabled and unauthenticated.

```bash
curl -sS 'http://127.0.0.1:8090/download_log.php?runner=runner-base' -o runner-base.log
```
