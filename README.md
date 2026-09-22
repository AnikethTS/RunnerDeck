<div align="center">

<img src=".github/logo.png" alt="RunnerDeck logo" width="96" />

# RunnerDeck

**One dashboard for every self-hosted GitHub Actions runner on your machine.**

[![CI](https://github.com/AnikethTS/RunnerDeck/actions/workflows/ci.yml/badge.svg)](https://github.com/AnikethTS/RunnerDeck/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/AnikethTS/RunnerDeck)](https://github.com/AnikethTS/RunnerDeck/releases/latest)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/AnikethTS/RunnerDeck/badge)](https://scorecard.dev/viewer/?uri=github.com/AnikethTS/RunnerDeck)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![JavaScript](https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?logo=javascript&logoColor=black)](public/assets/js)
[![SQLite](https://img.shields.io/badge/SQLite-optional-003B57?logo=sqlite&logoColor=white)](docs/how-it-works.md)
[![Docker](https://img.shields.io/badge/Docker-optional-2496ED?logo=docker&logoColor=white)](docs/docker.md)

![Dashboard in use](.github/screenshots/demo.gif)

</div>

See which runners are online, busy, or quietly dead. Tail their logs live.
Start, stop, or restart them — one at a time or all together — from a
button instead of a pidfile and an SSH session.

No framework, no build step, no dependencies to run it. Just PHP and a
little vanilla JS.

## Quick start

```bash
git clone https://github.com/AnikethTS/RunnerDeck.git && cd RunnerDeck
./run.sh                # binds 127.0.0.1:8090, no .env required
```

Open `http://127.0.0.1:8090/`, pick org or repo scope, click **Start All**.
That's the whole setup. Machine not ready yet? Run `php bin/doctor.php` to
check — or see [Getting started](docs/getting-started.md) for the full
walkthrough, prerequisites, and platform notes (Linux/macOS native,
Windows via WSL2).

## Screenshots

| Dashboard | Live log |
|---|---|
| ![Dashboard overview](.github/screenshots/dashboard.png) | ![Live log search highlighting matches](.github/screenshots/live-log.png) |

| Add runner | Settings |
|---|---|
| ![Add runner dialog](.github/screenshots/add-runner.png) | ![Settings page](.github/screenshots/settings.png) |

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
