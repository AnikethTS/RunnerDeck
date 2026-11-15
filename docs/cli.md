# Command line

`runnerdeck` manages the runner pool from a terminal, with no dashboard
running. It calls the same code as the dashboard's API, so a runner you add
here shows up in the dashboard and the other way round. It needs the same
things the dashboard does: PHP, and `gh` signed in for anything that talks to
GitHub (`stop`, `delete`, `update`).

## Getting the command

| You installed | The command | On your PATH |
|---|---|---|
| Debian/Ubuntu package | `runnerdeck` | yes, `/usr/bin/runnerdeck` |
| Homebrew | `runnerdeck` | yes, in Homebrew's `bin` |
| Windows Setup wizard | `runnerdeck` | yes, when the "Add the runnerdeck command to my PATH" task is ticked (it is by default). Open a new terminal afterwards. Uninstalling removes it. |
| Windows zip or single exe | `RunnerDeck.exe cli <command>` | no |
| Source checkout | `./runnerdeck <command>` | no. To put it on your PATH: `sudo ln -s "$PWD/runnerdeck" /usr/local/bin/runnerdeck` |
| Docker | `docker compose exec runnerdeck php app/bin/runnerdeck.php <command>` | no |

Check it works:

```bash
runnerdeck --version       # also: -v, or `runnerdeck version`
runnerdeck --help          # also: -h, or `runnerdeck help`
```

## Commands

| Command | What it does |
|---|---|
| `runnerdeck` or `runnerdeck run [--open] [PORT]` | Start the dashboard on `127.0.0.1` (default port 8090). `--open` opens your browser. On Windows: `runnerdeck run --port 9000`. |
| `runnerdeck list` (`ls`, `status`) | Show the runners on this machine: id, state, pid, uptime, agent name. |
| `runnerdeck start <runner...>` or `--all` | Start runners. A runner that is already running is left alone. |
| `runnerdeck stop <runner...>` or `--all` | Stop runners. Asks GitHub first and refuses if a runner is running a job. |
| `runnerdeck restart <runner...>` | Stop, then start. Same job check as `stop`. |
| `runnerdeck add [--name N] [--ephemeral] [--label L]` | Add a runner and start it. `--label` can repeat or be comma-separated (up to 12). |
| `runnerdeck delete <runner...>` (`rm`) | Stop, deregister from GitHub and remove the runner's files. Same job check. |
| `runnerdeck logs <runner> [-n N] [-f]` | Print the last N lines (default 50, up to 2000). `-f` keeps following; press Ctrl+C to stop. |
| `runnerdeck update [--check] [--yes]` (`upgrade`) | Look for a newer release and upgrade. See [Updating](#updating). |
| `runnerdeck doctor` | Check PHP, `gh`, scope access and directories. Same as `php app/bin/doctor.php`. |

Runners are named by id, as `runnerdeck list` shows them (`runner-base`,
`runner-1`, ...). Naming one that does not exist stops the command before
anything runs.

Flags:

| Flag | Meaning |
|---|---|
| `--json` | Machine-readable output for `list`, `start`, `stop`, `restart`, `add`, `delete` and `update --check`. Errors are JSON too, with `"ok": false`. |
| `--force` | Skip the GitHub "is a job running?" check on `stop`, `restart` and `delete`. This can interrupt a job. |
| `--yes`, `-y` | Do not ask before `update` installs. |
| `--all` | Every runner (`start`, `stop`). |

## Exit codes

`0` means success. `1` means the command failed or was refused: an unknown
runner, a busy runner, a blocked start (maintenance mode, low disk), a failed
update check, or an unknown command or option.

## The job check

`stop`, `restart` and `delete` ask GitHub whether each runner is busy.
If GitHub cannot be reached, the command refuses too, since it cannot tell.
Both cases print `Pass force=1 to override`; on the command line that flag is
`--force`.

## Updating

```bash
runnerdeck update --check    # report only; --json for scripts
runnerdeck update            # asks, then upgrades
runnerdeck update --yes      # no prompt
```

`update` reads the latest release from GitHub (through `gh`), works out how
this copy was installed, and upgrades that way:

| Install | What `update` does |
|---|---|
| Homebrew | `brew upgrade anikethts/runnerdeck/runnerdeck` |
| Debian package | Downloads `RunnerDeck-<version>-linux-all.deb` and `SHA256SUMS-linux-all` with `gh release download`, checks the SHA-256, then runs `sudo apt-get install -y <file>`. |
| Windows Setup wizard | Downloads the Setup wizard and `SHA256SUMS-windows-x64`, checks the SHA-256, then opens the installer. Close RunnerDeck first. |
| Source checkout | `git pull --ff-only` in the checkout |
| Docker, or unknown | Prints what to do. It never changes a container or an install it cannot identify. |

A download whose checksum does not match is deleted and never installed.
Restart the dashboard afterwards to run the new version. If the install type
is guessed wrong, set `RUNNERDECK_INSTALL_METHOD` to `brew`, `deb`,
`windows`, `git`, `docker` or `unknown`.

`update` works whether or not `RUNNERDECK_CHECK_UPDATES` is set; that
variable only controls the dashboard header's automatic check.

## Scripting

```bash
# stop every idle runner, fail if any is busy
runnerdeck stop --all || echo "something is running a job"

# runner ids that are running
runnerdeck list --json | jq -r '.runners[] | select(.local_running) | .id'

# is there an update?
runnerdeck update --check --json | jq -r .update_available
```

## Where things live

On a packaged install the command stores settings, history and runners in your
user data directory (`~/.local/share/runnerdeck`, `~/Library/Application
Support/RunnerDeck`, or `%LOCALAPPDATA%\RunnerDeck`), the same place the
dashboard uses. `RUNNERDECK_DATA_DIR` moves it. From a source checkout it
uses the checkout's `storage/` directory, like `./run.sh`.
