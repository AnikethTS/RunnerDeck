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
| Source checkout or zip | `./runnerdeck <command>` | not until you run `./runnerdeck install`, which links it into `~/.local/bin` |
| Docker | `docker compose exec runnerdeck php app/bin/runnerdeck.php <command>` | no |

To install the latest release in one step (Linux and macOS; needs `curl`, `unzip` and `php`):

```bash
curl -fsSL https://github.com/AnikethTS/RunnerDeck/releases/latest/download/install.sh | sh
```

That file is published with the latest release. It checks the zip's SHA-256, unpacks it into `~/.local/share/runnerdeck` and runs
`./runnerdeck install`. Run it again to upgrade; your data and `.env` are kept.
Set `RUNNERDECK_DIR` to unpack somewhere else. It always installs the latest release.

Check it works:

```bash
runnerdeck --version       # also: -v, or `runnerdeck version`
runnerdeck --help          # also: -h, or `runnerdeck help`
```

## Commands

### Runners

| Command | What it does |
|---|---|
| `runnerdeck list` (`ls`) | The runners on this machine: id, state, pid, uptime, agent name. Extra labels are appended as `[gpu,large]`. It does not measure slot disk. `--watch` redraws every 5 seconds (`--interval N` to change). `--label L` can be repeated; a runner is shown only when its extra labels include every one. The shared pool label does not count. `--json` lists the same runners, and an empty match is an empty list. |
| `runnerdeck status` | Like `list`, including `--label`, plus a GITHUB column: `idle`, `busy`, `offline`, `unregistered`, or `?` when GitHub cannot be reached. |
| `runnerdeck start <runner...>` or `--all` | Start runners. A runner that is already running is left alone. |
| `runnerdeck stop <runner...>` or `--all` | Stop runners. Refuses if a runner is running a job. `--dry-run` prints `would stop` or `would refuse` and changes nothing. |
| `runnerdeck restart <runner...>` or `--all` | Stop, then start. Same job check as `stop`. `--dry-run` reports the action and does not restart. |
| `runnerdeck rolling-restart <runner...>` or `--all` | Restart one runner at a time, waiting for each to be idle first. `--timeout S` bounds each wait. |
| `runnerdeck drain <runner...>` or `--all` | Wait until the runners are idle, then stop them and clear their work folders. `--dry-run` reports that and does not drain. |
| `runnerdeck add [--name N] [--ephemeral] [--label L]` | Add a runner and start it. `--label` can repeat or be comma-separated (up to 12). |
| `runnerdeck delete <runner...>` (`rm`) | Stop, deregister from GitHub and remove the runner's files. Same job check. `--dry-run` reports that and does not delete. |
| `runnerdeck rename <runner> <name>` | Re-register a runner under a new name. Refuses while it has a job. |
| `runnerdeck force-idle <runner>` | Clear a stuck "busy" state on GitHub for a runner that is stopped on this machine. |
| `runnerdeck resize <count>` | Make the pool `<count>` runners in total, including `runner-base` (1 to 30). Starts the missing ones and stops any slot above that count. |
| `runnerdeck maintenance on\|off\|status` | `on` drains and stops every runner and refuses starts until `off`. |
| `runnerdeck logs <runner> [-n N] [-f]` | Print the last N lines (default 50, up to 2000). `-f` follows; Ctrl+C stops. `--json` prints `{ok, runner, lines}` and does not combine with `-f`. |

Runners are named by id, as `runnerdeck list` shows them (`runner-base`,
`runner-1`, ...). Naming one that does not exist stops the command before
anything runs.

### Setup

| Command | What it does |
|---|---|
| `runnerdeck setup [--scope S] [--org O \| --repo OWNER/REPO]` | Choose what RunnerDeck manages: an organization or one repository. It asks for anything you leave out, then reports whether GitHub is signed in. |
| `runnerdeck config list [--all]` | The settings you have saved. `--all` lists every name you can set. |
| `runnerdeck config get <name>` | One saved value. |
| `runnerdeck config set <name> <value>` | Change a setting. Values are checked by the same rules as the dashboard's Settings screen. On/off settings take `on` or `off`. |
| `runnerdeck config unset <name>` | Clear a setting back to its default. |
| `runnerdeck auth status` | Whether `gh` is signed in. |
| `runnerdeck auth login` | Run `gh auth login` for you. |
| `runnerdeck auth totp` | Set up the dashboard's TOTP login (needed to bind off localhost). A terminal shows a QR code to scan, plus the key. |
| `runnerdeck doctor` | Check PHP, `gh`, scope access, directories and, on Linux, whether a new runner's OS libraries can be installed without a sudo password. Same as `php app/bin/doctor.php`. |

Setting names are the `RUNNERDECK_*` variables from
[Configuration](configuration.md) in lowercase with hyphens:
`RUNNERDECK_DRAIN_TIMEOUT` is `drain-timeout`. The scope, org and repo change
only through `setup`, because they depend on each other. The GitHub App
private key and the TOTP secret are not available through `config`; use the
dashboard and `auth totp`. A real environment variable or `.env` still
overrides a saved value, as it does for the dashboard.

```bash
runnerdeck setup --repo acme/widgets
runnerdeck config set pool-cap 8
runnerdeck config set auto-restart on
runnerdeck config get drain-timeout
```

### Program

| Command | What it does |
|---|---|
| `runnerdeck` or `runnerdeck run [--open] [PORT]` | Start the dashboard on `127.0.0.1` (default port 8090). `--open` opens your browser. On Windows: `runnerdeck run --port 9000`. |
| `runnerdeck update [--check] [--yes]` (`upgrade`) | Look for a newer release and upgrade. See [Updating](#updating). |
| `runnerdeck install [--dir D] [--force] [--no-completion]` | From a source checkout, link `runnerdeck` into `~/.local/bin` (or `D`) and set up tab completion for your shell (see [Shell completion](#shell-completion)). It tells you if that folder is not on your PATH, with the command for your shell (`fish_add_path` for fish, `export PATH=...` otherwise). |
| `runnerdeck uninstall [--dir D] [--yes]` | Ask, then remove that link. It only removes a link that points at this checkout. `--yes` skips the question. |
| `runnerdeck completion bash\|zsh\|fish\|powershell` | Print a completion script; see [Shell completion](#shell-completion). |
| `runnerdeck version`, `--version`, `-v` | Print the version. On a terminal it shows the RunnerDeck logo and welcome message, like `run.sh`; in a pipe, with `--json`, `-q` or `RUNNERDECK_NO_BANNER=1` it prints just `RunnerDeck 2.2.0`. |

Flags:

| Flag | Meaning |
|---|---|
| `--json` | Machine-readable output for the runner commands, `config`, `maintenance` and `update --check`. Errors are JSON too, with `"ok": false`. |
| `--force` | Skip the GitHub "is a job running?" check. This can interrupt a job. |
| `--timeout S` | How long `drain`, `rolling-restart` and `maintenance on` wait for a job to finish. Defaults to the `drain-timeout` setting. |
| `--yes`, `-y` | Do not ask before `update` installs or `uninstall` removes the link. |
| `--quiet`, `-q` | Print only errors. |
| `--no-color` | No colour. `NO_COLOR=1` does the same. `--color` forces it on, for example into `less -R`. |
| `--all` | Every runner. |

Colour is on only when the output is a terminal.

## Exit codes

`0` means success. `1` means the command failed or was refused: an unknown
runner, a busy runner, a blocked start (maintenance mode, low disk), a failed
update check, a setting that did not validate, or an unknown command or option.

## The job check

`stop`, `restart`, `delete`, `rename`, `drain`, `rolling-restart` and `maintenance on` ask GitHub whether each runner is busy.
If GitHub cannot be reached, the command refuses too, since it cannot tell.
Both cases say `Pass --force to override`. `drain`, `rolling-restart`,
`rename` and `maintenance on` wait for the job to finish instead (up to
`--timeout`) and then give up with the same message.

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
| `install.sh` (`~/.local/share/runnerdeck`) | Downloads `runnerdeck-v<version>.zip` and its `.sha256`, checks the SHA-256, and copies the release over that folder. `.env`, `storage`, and `runners` stay. Then it runs `runnerdeck install --force` so the command link follows the new folder. |
| Docker, or unknown | Prints what to do. It never changes a container or an install it cannot identify. |

A download whose checksum does not match is deleted and never installed.
On Windows, `RUNNERDECK_UPDATE_SILENT=1` runs the Setup wizard without
questions, and `RUNNERDECK_UPDATE_DIR` chooses its folder. A folder path
that contains quotes or shell characters is ignored, and the wizard uses
its own default.
Restart the dashboard afterwards to run the new version. If the install type
is guessed wrong, set `RUNNERDECK_INSTALL_METHOD` to `brew`, `deb`,
`windows`, `git`, `zip`, `docker` or `unknown`. |

`update` works whether or not `RUNNERDECK_CHECK_UPDATES` is set; that
variable only controls the dashboard header's automatic check.

Any other command in a terminal also says so when a newer release is
already known: the version you have, the new version, `runnerdeck update`,
and a link to that release. GitHub is asked at most once a day, and a
failed check prints nothing. `--json`, `--quiet`, and shell completion
stay silent. Set `RUNNERDECK_NO_UPDATE_NOTICE=1` to turn the line off.

## Shell completion

Completion fills in commands, runner ids (from your pool) and setting names.
`runnerdeck install` sets it up for you: fish gets
`~/.config/fish/completions/runnerdeck.fish`, zsh gets `~/.zfunc/_runnerdeck`
(add `fpath=(~/.zfunc $fpath)` to `~/.zshrc` before `compinit` if it is not
there already) and bash gets one line appended to `~/.bashrc`. Pass
`--no-completion` to skip that. To do it by hand:

```bash
# bash: add to ~/.bashrc
source <(runnerdeck completion bash)

# zsh: add to ~/.zshrc
source <(runnerdeck completion zsh)

# fish
runnerdeck completion fish > ~/.config/fish/completions/runnerdeck.fish

# PowerShell: add to $PROFILE
runnerdeck completion powershell | Out-String | Invoke-Expression
```

The Debian package and Homebrew also install a manual page: `man runnerdeck`.

## Scripting

```bash
# stop every idle runner, fail if any is busy
runnerdeck stop --all || echo "something is running a job"

# let jobs finish, then stop everything for a reboot
runnerdeck maintenance on --timeout 1800 -q && sudo reboot

# restart the pool without dropping a job
runnerdeck rolling-restart --all

# runner ids that are running
runnerdeck list --json | jq -r '.runners[] | select(.local_running) | .id'

# is there an update?
runnerdeck update --check --json | jq -r .update_available
```

## Where things live

On a packaged install the command stores settings, history and runners in your
user data directory (`~/.local/share/runnerdeck`, `~/.runnerdeck` on macOS, or
`%LOCALAPPDATA%\RunnerDeck`), the same place the dashboard uses.
`RUNNERDECK_DATA_DIR` moves the whole directory. `runnerdeck config set pool-dir /absolute/path`
remembers just the runner pool for the next launch, with no shell rc file.
A pool path with a space fails `runnerdeck doctor`: GitHub starts each step
with that path unquoted. From a source checkout it
uses the checkout's `storage/` directory, like `./run.sh`.
