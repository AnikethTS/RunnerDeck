# How it works

- **Local process state** comes from reading each runner directory directly
  — its `.runner` config, its `runner.pid` file, and (as a fallback, since
  pidfiles can go stale or get lost) a live scan of `/proc` matching on the
  actual `Runner.Listener` executable path. Each slot's **agent version** is
  read locally from `.runner` (`agentVersion`) or, if that field is missing,
  `bin/Runner.Listener.deps.json` — no extra GitHub call. A runner is never silently
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
  `/proc` fallback if the pidfile is missing) and waits up to 20s for it
  to exit, then `SIGKILL` if it is still alive. Stop fails if the process
  is still running after that.
- **Drain** waits until GitHub reports `busy: false` (timeout
  `RUNNERDECK_DRAIN_TIMEOUT`, default 10 minutes) and then stops. The
  dashboard polls status while waiting; `POST drain_stop` /
  `drain_stop_all` / `bulk_drain_stop` wait on the server; `php
  bin/drain-stop.php` is the reboot-friendly CLI (optional runner ids,
  `--timeout=`, `--force`). Timeout is also a Settings field. While the
  dashboard waits, the row button shows remaining seconds and busy/idle
  from that same poll (`Busy · 12s`). **Rolling restart** does that
  drain-stop then start, one configured slot at a time, so this host is
  not all-offline at once. **Upgrade agents** is the same sequence plus
  replacing the local GitHub Actions runner package with GitHub's current
  download for this OS/arch.   **Maintenance** drain-stops every slot and
  refuses start / auto-restart / scale-to-queue until you resume.
  **Safe reboot** drain-stops the pool and sets `reboot_ready` so you
  can reboot the OS yourself — RunnerDeck does not call `reboot`.
  Starting a runner clears that flag. **Host pressure** also refuses
  new starts when the pool filesystem or Linux MemAvailable is below
  the Settings minimum (default 1 GiB disk, 256 MiB RAM). Running jobs
  keep going. `public/health.php` stays an unauthenticated liveness
  probe (`ok: true`) and adds `ready` plus `pressure` (and
  `reboot_ready`) so a monitor can see the box should not take more
  work. The pressure webhook is not sent from that probe.
- Before any **Stop**, the dashboard checks GitHub's `busy` flag for that
  runner and asks for confirmation if it's mid-job (or if that status can't
  be verified at all — it fails closed, not open).
- **Add Runner** allocates the next free slot and lets you give it its own
  GitHub-registered name. Optional **ephemeral** passes `--ephemeral` to
  `config.sh`: the slot takes one job, exits, and RunnerDeck
  re-registers it (not a crash). Optional **extra labels** on Add runner
  are per-slot (with the pool label and Settings extras). **Rename** stops a runner, deregisters it from
  GitHub, and re-registers it fresh under the new name — same busy-flag
  confirmation as Stop, since it interrupts any in-progress job. **Delete**
  does the same deregistration, then renames the slot out of the pool and
  removes the directory (binaries, config, logs) — always confirmed
  separately from the busy check, since there's no undo.
- Each running runner's **CPU%/RAM/uptime** is shown live (`ps`-based,
  cross-platform, read-only — nothing is capped or throttled), with a
  rolling one-hour pool-wide history chart backed by a small local SQLite
  file (`storage/db/history.sqlite`). The charts label the axis (CPU 0–100%,
  RAM in MB), the latest sample, and min–max for the hour. This is
  best-effort: if the `pdo_sqlite` PHP extension isn't installed, the rest
  of the dashboard works exactly the same, you just don't get the chart.
- A **filter box** narrows the table by runner ID, registered name,
  agent version, or GitHub label. A
  **state** dropdown (idle / busy / crashed / mismatch) stacks on that
  name filter from the same snapshot. Each runner's log can be
  **downloaded in full**, not just tailed — or
  narrowed to a time range first, using whatever timestamps the runner's
  own console output includes. The dashboard **Recent log** panel can
  download `storage/runnerdeck.log` the same way.
- Two **System CPU/RAM** stat cards show whole-machine usage (all
  processes, not just runners) — useful for telling "my runners are the
  load" apart from "something else on this box is." Reuses the same `ps`
  scan the per-runner stats already do, plus `nproc`/`sysctl` for core
  count and total RAM; degrades to `—` rather than guessing if those
  aren't available. These are pure local reads with no GitHub API cost
  and ride along on the 5s `action=status` snapshot so they do not
  queue a second request on the single-threaded PHP built-in server.
- A **Pool disk** card sums `_work` / `_diag` / `runner.log` across slots.
  An optional Settings threshold fires the crash webhook once when a slot
  or that pool total first reaches N GiB.
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
  every browser tab and survives a page reload. `action=status` (a GET)
  only ever decides and reports; it never spawns a process itself. A
  sibling loop (`bin/watch-auto-restart.php`, started from `run.sh`)
  calls the same start path when auto-restart is on, so a crash is
  restarted with no dashboard tab open. A visible tab may still POST
  `start` as well; starting an already-running slot is a no-op. **Pause AR**
  on a row holds auto-restart for that slot only (crash still flags) so
  you can debug a flaky runner without turning the pool setting off.
- An optional **crash-loop webhook** (Settings, `RUNNERDECK_CRASH_WEBHOOK_URL`)
  POSTs a notification the moment a crash-loop is flagged, so you find out
  even with no dashboard tab open. Slack and Discord incoming webhook URLs
  are detected automatically and get their native payload shape; anything
  else gets a plain JSON payload, e.g. for a webhook-to-email/push relay.
  The same URL also fires once when a local/GitHub **mismatch** is first
  flagged (three disagreeing polls), when **host pressure** starts
  refusing starts, and when a queued job first exceeds the optional
  queue-wait threshold. GitHub **busy** with no local process for three
  polls flags the row; **Force idle** removes the GitHub registration
  and local `.runner` files so the next Start re-registers.
- Every crash is recorded, not just flagged ones — a small SQLite table
  (`src/CrashHistory.php`, the same database as CPU/RAM history) keeps a
  7-day count per runner. It's independent of `crash_flagged`: a runner
  that's crashed 5 times but keeps getting auto-restarted still shows
  that count in the table, even though it's not currently flagged — the
  point is spotting a flaky runner before it becomes a real problem.
  Clicking the badge lists the timestamps. The Local column can be sorted
  by that 7-day count. An optional Settings threshold fires the same
  webhook when the count first reaches N, not only on a crash-loop.
- Each slot shows **best-effort disk** for `_work`, `_diag`, and
  `runner.log` (sortable). **Clear work** on a stopped runner deletes
  `_work` only. `runner.log` rotates to `runner.log.1` at 5 MB on start
  or stop (not while the process is running). Tails and downloads include
  the rotated file so a cap does not hide the previous chunk.
- Runners can be **selected in bulk** (checkboxes, with a "select all" for
  the current filter) and started, stopped, or deleted together — the busy
  check for Stop/Delete is done once for the whole selection, not per runner.
- An optional **update check** (off by default — enable it in Settings)
  compares this install's `VERSION` file against the latest GitHub Release
  and shows a small badge in the header if a newer one exists. It's the
  only thing in RunnerDeck that calls out to a repo other than the one
  you're managing runners for, which is why it's opt-in rather than on
  by default.
- The dashboard **polls** `action=status&view=1` every 5s only while the tab is
  visible (whole-machine load is in that snapshot). **Queued jobs** for
  this pool's label are a separate `action=queue` poll (~20s) with a
  ~25s disk cache — GitHub has no org-wide waiting-jobs API, so org
  scope only looks at a few recently pushed repos. The panel is a
  hint (start idle slots / add a runner); it never auto-starts unless
  **scale to queue** is on. **Pool cap** blocks starts above N running
  slots. **Idle stop** (minutes, opt-in) stops GitHub-idle slots
  when the queue is empty. The same payload's `live` jobs paint the
  current job on a busy row; `recent` paints the last completed jobs.
  GitHub names this host registered that have no local slot show in
  **GitHub runners not on this host** (Remove from GitHub). Row
  HTML, stats, and the recent-log panel come from PHP; JS swaps that
  markup in and handles filter/sort, bulk actions, and live logs. A
  hidden tab stops the timer and fetches once when you come back — so
  a background tab is not a second load on the same machine that is
  running the jobs.
  An optional TOTP **idle timeout** (Settings → Login) therefore treats a
  hidden tab as idle. Auto-restart without a visible tab is the `watch-auto-restart.php`
  process from `run.sh`, not the dashboard poll. `./run.sh` is `php -S`
  (a handful of workers; a live log holds one). php-fpm is documented in
  [Hosting](hosting.md). A TLS hostname or non-localhost Host shows
  warning banners (missing TLS, TOTP-only 443, `php -S`, TRUST_PROXY).
  Settings → **This host** lists Host, bind, HTTPS, server, TOTP, and
  TRUST_PROXY for the current request even on localhost.
- **Recent log** on the dashboard is `storage/runnerdeck.log` (start/stop/
  delete/rename plus provision/`gh` failures, secrets redacted). The panel
  auto-opens only when there is an error-level line. Filter by level and
  **Clear** (CSRF) wipes the current plus rotated files, then writes one
  `log.cleared` info line. Download is still there; SSH is optional.
- The GitHub column shows online/busy plus the `os` field already on
  GitHub's runner list (`Linux` / `macOS` / `Windows`) — same poll, no
  extra `gh` call.
- The UI is just a client of its own API — see **[API.md](../API.md)** for
  every `api.php` action, the two standalone log endpoints, and how to
  get a CSRF token from a script instead of a browser session.
