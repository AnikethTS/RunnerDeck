# How it works

- **Local process state** comes from reading each runner directory directly
  — its `.runner` config, its `runner.pid` file, and (as a fallback, since
  pidfiles can go stale or get lost) a live scan of `/proc` matching on the
  actual `Runner.Listener` executable path. A runner is never silently
  "invisible" just because its pidfile disappeared.
- **GitHub-reported state** comes from `gh api --paginate …/actions/runners`
  (`per_page=100`) so orgs with more than 30 runners are not truncated.
- **Starting an unconfigured runner slot** downloads the official runner
  package for this machine's OS/arch (via the same
  `orgs/.../actions/runners/downloads` endpoint GitHub's own "Add new
  runner" page uses), verifies its checksum, extracts it, runs
  `bin/installdependencies.sh` on Linux (apt/yum hosts; skipped on Alpine,
  where the Docker image installs those libs via apk), requests a fresh
  org registration token, and runs `config.sh` — the same flow you'd do by
  hand, done for you. No manual pre-setup step.
- **Stopping** sends `SIGTERM` to the actual process (by pidfile, or by the
  `/proc` fallback if the pidfile is missing) and waits for a graceful exit.
- Before any **Stop**, the dashboard checks GitHub's `busy` flag for that
  runner and asks for confirmation if it's mid-job (or if that status can't
  be verified at all — it fails closed, not open).
- **Add Runner** allocates the next free slot and lets you give it its own
  GitHub-registered name. **Rename** stops a runner, deregisters it from
  GitHub, and re-registers it fresh under the new name — same busy-flag
  confirmation as Stop, since it interrupts any in-progress job. **Delete**
  does the same deregistration, then renames the slot out of the pool and
  removes the directory (binaries, config, logs) — always confirmed
  separately from the busy check, since there's no undo.
- Each running runner's **CPU%/RAM/uptime** is shown live (`ps`-based,
  cross-platform, read-only — nothing is capped or throttled), with a
  rolling one-hour pool-wide history chart backed by a small local SQLite
  file (`storage/db/history.sqlite`). This is best-effort: if the `pdo_sqlite`
  PHP extension isn't installed, the rest of the dashboard works exactly
  the same, you just don't get the chart.
- A **filter box** narrows the table by runner ID or registered name, and
  each runner's log can be **downloaded in full**, not just tailed — or
  narrowed to a time range first, using whatever timestamps the runner's
  own console output includes.
- Two **System CPU/RAM** stat cards show whole-machine usage (all
  processes, not just runners) — useful for telling "my runners are the
  load" apart from "something else on this box is." Reuses the same `ps`
  scan the per-runner stats already do, plus `nproc`/`sysctl` for core
  count and total RAM; degrades to `—` rather than guessing if those
  aren't available. These are pure local reads with no GitHub API cost
  and ride along on the 5s `action=status` snapshot so they do not
  queue a second request on the single-threaded PHP built-in server.
- If a runner's local process state and its GitHub-reported state
  **disagree for several polls in a row**, the row is flagged — a one-off
  mismatch during a status transition is normal and ignored, a persistent
  one usually means the process died without GitHub finding out yet (or
  vice versa). The streak is counted server-side (`mismatch_flagged` on
  `action=status`), so every tab sees the same flag.
- An optional **auto-restart** setting (Settings page) brings a runner
  back up if its process dies without you having stopped it yourself. It
  backs off after a few failed attempts in a row — crash-looping is
  flagged with a toast notification and a row badge, not retried forever —
  and resets once the runner has stayed healthy for a couple of minutes.
  Crash tracking lives server-side, so the decision is consistent across
  every browser tab and survives a page reload — but the actual restart
  is still a CSRF-protected `POST action=start`, triggered by the client
  when it sees the flag. `action=status` (a GET) only ever decides and
  reports; it never spawns a process itself, deliberately — a GET has no
  CSRF check by design, so it must stay side-effect-free.
- An optional **crash-loop webhook** (Settings, `RUNNERDECK_CRASH_WEBHOOK_URL`)
  POSTs a notification the moment a crash-loop is flagged, so you find out
  even with no dashboard tab open. Slack and Discord incoming webhook URLs
  are detected automatically and get their native payload shape; anything
  else gets a plain JSON payload, e.g. for a webhook-to-email/push relay.
- Every crash is recorded, not just flagged ones — a small SQLite table
  (`src/CrashHistory.php`, the same database as CPU/RAM history) keeps a
  7-day count per runner. It's independent of `crash_flagged`: a runner
  that's crashed 5 times but keeps getting auto-restarted still shows
  that count in the table, even though it's not currently flagged — the
  point is spotting a flaky runner before it becomes a real problem.
  Clicking the badge lists the timestamps. The Local column can be sorted
  by that 7-day count. An optional Settings threshold fires the same
  webhook when the count first reaches N, not only on a crash-loop.
- Runners can be **selected in bulk** (checkboxes, with a "select all" for
  the current filter) and started, stopped, or deleted together — the busy
  check for Stop/Delete is done once for the whole selection, not per runner.
- An optional **update check** (off by default — enable it in Settings)
  compares this install's `VERSION` file against the latest GitHub Release
  and shows a small badge in the header if a newer one exists. It's the
  only thing in RunnerDeck that calls out to a repo other than the one
  you're managing runners for, which is why it's opt-in rather than on
  by default.
- The dashboard **polls** `action=status` every 5s only while the tab is
  visible (whole-machine load is in that snapshot). A hidden tab stops
  the timer and fetches once when you come back — so a background tab is
  not a second load on the same machine that is running the jobs.
  Client-side auto-restart (POST `action=start` when
  `should_auto_restart` is set) therefore also only runs from a visible
  dashboard tab.
- **Recent errors** on the dashboard are the JSON lines already written to
  `storage/runnerdeck.log` (start/provision/`gh` failures, secrets
  redacted). The file is still there for SSH; the panel is so you do not
  have to.
- The UI is just a client of its own API — see **[API.md](../API.md)** for
  every `api.php` action, the two standalone log endpoints, and how to
  get a CSRF token from a script instead of a browser session.
