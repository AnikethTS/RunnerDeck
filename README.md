<div align="center">

<img src=".github/logo.png" alt="RunnerDeck logo" width="96" />

# RunnerDeck

**One dashboard for every self-hosted GitHub Actions runner on your machine.**

[![CI](https://github.com/AnikethTS/RunnerDeck/actions/workflows/ci.yml/badge.svg)](https://github.com/AnikethTS/RunnerDeck/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/AnikethTS/RunnerDeck)](https://github.com/AnikethTS/RunnerDeck/releases/latest)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/AnikethTS/RunnerDeck/badge)](https://scorecard.dev/viewer/?uri=github.com/AnikethTS/RunnerDeck)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](app/composer.json)
[![JavaScript](https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?logo=javascript&logoColor=black)](app/public/assets/js)
[![SQLite](https://img.shields.io/badge/SQLite-optional-003B57?logo=sqlite&logoColor=white)](docs/how-it-works.md)
[![Docker](https://img.shields.io/badge/Docker-optional-2496ED?logo=docker&logoColor=white)](docs/docker.md)

[![Linux](https://img.shields.io/badge/Linux-native-FCC624?logo=linux&logoColor=black)](docs/getting-started.md#platform-support)
[![Windows](https://img.shields.io/badge/Windows-native-0078D6)](docs/getting-started.md#platform-support)
[![macOS](https://img.shields.io/badge/macOS-native-000000?logo=apple&logoColor=white)](docs/getting-started.md#platform-support)

![Dashboard in use](.github/screenshots/demo.gif)

</div>

See which runners are online, busy, or quietly dead. Tail their logs live.
Start, stop, or restart them — one at a time or all together — from a
button instead of a pidfile and an SSH session.

No framework, no build step, no dependencies to run it. Just PHP and a
little vanilla JS.

## Runs natively on

| | Platform | Runners it manages |
|---|---|---|
| <img src="https://cdn.simpleicons.org/linux/FCC624" alt="" width="20" /> | **Linux** | Linux runners (x64, arm64, arm) |
| <img src="https://cdn.simpleicons.org/apple/999999" alt="" width="20" /> | **macOS** | macOS runners (Intel and Apple silicon) |
| | **Windows** | Windows runners (x64, arm64) via `.\run.ps1` |

Windows is native too, no WSL2 needed, so `runs-on: windows` jobs work. The
built-in server handles one request at a time there, so a slow request can
briefly hold up the next click; see
[Platform support](docs/getting-started.md#platform-support). WSL2 is only
needed to manage Linux runners from a Windows PC (`.\run.ps1 -Wsl`).

## Quick start

```bash
git clone https://github.com/AnikethTS/RunnerDeck.git && cd RunnerDeck
./run.sh                # binds 127.0.0.1:8090, no .env required
```

Open `http://127.0.0.1:8090/`, pick org or repo scope, click **Start All**.
That's the whole setup. Machine not ready yet? Run `php app/bin/doctor.php` to
check — or see [Getting started](docs/getting-started.md) for the full
walkthrough, prerequisites, and platform notes (Linux, macOS and Windows,
all native).

**On Windows without installing PHP:** download `RunnerDeck-<version>-windows-x64-setup.exe`
from the [latest release](https://github.com/AnikethTS/RunnerDeck/releases/latest)
and run it. It is a normal installer wizard: it installs for your account only (no
administrator rights), installs what RunnerDeck needs during setup (Microsoft's Visual C++
runtime, the Edge WebView2 runtime that draws the window, and the GitHub CLI, only when missing, plus an optional GitHub sign-in on the last page),
adds a Start menu folder with an Uninstall entry, can add a desktop shortcut and start
RunnerDeck when you sign in, and uninstalls from **Settings → Apps**, the Start menu or the tray menu. RunnerDeck then
opens in its own window (no browser tab) and keeps running as a small icon in the system tray
(Open dashboard, Show server log, Uninstall, Quit); closing the window hides it to the tray. PHP is bundled. If a step was skipped, it asks again the first time it starts. Prefer no installer? `RunnerDeck-<version>-windows-x64.exe` is a single file
that runs in a console window, and a `.zip` with the same files side by side is also
attached. The downloads are not code-signed yet, so SmartScreen may ask you to choose
**More info → Run anyway**. Your settings and runners are stored in `%LOCALAPPDATA%\RunnerDeck`.

**On Debian or Ubuntu:** download `RunnerDeck-<version>-linux-all.deb` from the
[latest release](https://github.com/AnikethTS/RunnerDeck/releases/latest) and run
`sudo apt install ./RunnerDeck-<version>-linux-all.deb`. Then start it with `runnerdeck --open`.
Your settings and runners are stored in `~/.local/share/runnerdeck`.

## Screenshots

| Dashboard | Live log |
|---|---|
| ![Dashboard overview (dark mode)](.github/screenshots/dashboard.png?v=dark) | ![Live log search highlighting matches (dark mode)](.github/screenshots/live-log.png?v=dark) |

| Add runner | Settings |
|---|---|
| ![Add runner dialog (dark mode)](.github/screenshots/add-runner.png?v=dark) | ![Settings page (dark mode)](.github/screenshots/settings.png?v=dark) |

## Why this exists

Self-hosted runners are cheap to run many of on one machine, but tedious to
operate: is `runner-7` actually alive, or did its process die three hours ago
while GitHub still shows it "busy"? Which of these logs has the failure? Did
that last "Start All" click actually work, or did it just pile up a second
copy on top of the first? RunnerDeck exists to make those questions fast
to answer, and to make starting/stopping a fleet of runners a button instead
of a script incantation.

## What it does

- Checks each runner two ways — what GitHub reports, and what's actually
  running on this machine — and flags the two when they disagree.
- Adds, starts, stops, renames, and deletes runners for you, checksums and
  all. No more downloading the runner package and running `config.sh` by hand.
- Shows live CPU/RAM per runner and for the whole machine, plus a rolling
  history chart.
- Can auto-restart a runner that crashes, with crash-loop protection so it
  doesn't retry forever.
- Has a full JSON API behind the UI, documented in [API.md](API.md), if
  you'd rather script it.

Curious exactly how any of that works under the hood? See
[How it works](docs/how-it-works.md) for the full behavior reference.

## Docs

- [Getting started](docs/getting-started.md) — prerequisites, platform
  support, first-time setup, Add to Home Screen
- [How it works](docs/how-it-works.md) — the full behavior reference
- [Docker](docs/docker.md) — running RunnerDeck in a container (or Podman)
- [Hosting](docs/hosting.md) — php-fpm, TLS, IP allowlist / mTLS
- [Configuration](docs/configuration.md) — every environment variable
- [Security & safety](docs/security-and-safety.md) — hosting remotely,
  what this trusts, what it doesn't
- [Development](docs/development.md) — running tests, CI, directory layout
- [API.md](API.md) — the JSON API this UI is itself a client of
- [CONTRIBUTING.md](CONTRIBUTING.md) — how to send a PR
- [SECURITY.md](SECURITY.md) — how to report a vulnerability

## Contributors

<a href="https://github.com/AnikethTS/RunnerDeck/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=AnikethTS/RunnerDeck" alt="RunnerDeck contributors" />
</a>

## License

MIT — see [LICENSE](LICENSE).

Linux, Windows and macOS are trademarks of their respective owners. This project is not affiliated with or endorsed by them.
