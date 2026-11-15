# Docker

An alternative to the [native install](getting-started.md#platform-support) on
Linux, macOS and Windows (note a container runs Linux runners, not Windows ones), or just a self-contained way to run RunnerDeck without installing
PHP directly:

```bash
docker compose run --rm -it runnerdeck php app/bin/setup-totp.php
docker compose up
```

The image binds `0.0.0.0` inside the container so port mapping works.
`run.sh` will not start without TOTP on that bind — enroll first (codes
land in `../runnerdeck-data`). Mapping `-p 8090:8090` without TOTP no longer
serves an open dashboard.

Tagged releases publish `ghcr.io/anikethts/runnerdeck:<tag>` and `:latest`.
Compose uses that image. Building the `Dockerfile` on this machine is
opt-in: comment in `build:` (that compiles `gh` and the PHP extensions
and is what filled a small disk).

The image is PHP 8.5.12 on Debian bookworm, with `gh` 2.103.3 built from
the checksummed source and `golang.org/x/net` v0.62.0. GitHub's runner is
a glibc program, so the image is not Alpine. It runs `./run.sh` (`php -S`,
four workers) inside the container — same one-operator limits as a local
install. `restart` is off: the first start without TOTP prints
`docker compose run --rm -it runnerdeck php app/bin/setup-totp.php` and
stays stopped.

- **`../runnerdeck-data`** (override with `RUNNERDECK_DATA_DIR`) is mounted
  on `/data` and holds the runner pool, `settings.json`, the history
  database, and `runnerdeck.log`. It sits next to the checkout, not inside
  it, so Node does not walk up to this repo's `package.json` and treat
  `hashFiles` as an ES module. Delete that directory to reset RunnerDeck.
  The entrypoint `chown`s `/data` to `RUNNERDECK_DATA_UID` /
  `RUNNERDECK_DATA_GID` (default `1000`), the user who will also run the
  dashboard on the host. Set those to `id -u` and `id -g` when 1000 is
  not you.
- **GitHub CLI auth** — pick **one**:
  1. Run `gh auth login` on the host, then keep the default
     `~/.config/gh:/root/.config/gh:ro` volume so the container reuses that
     token read-only.
  2. Comment out that volume line and run
     `docker compose exec runnerdeck gh auth login` once inside the
     container. That command *writes* `~/.config/gh`, so it cannot work
     with the read-only mount still attached.
- The image runs as root and sets `RUNNER_ALLOW_RUNASROOT=1` because
  GitHub's `config.sh` otherwise refuses UID 0. It installs the shared
  libraries the .NET runner needs (`libicu72`, `libkrb5-3`, …).
  `installdependencies.sh` is apt-based and runs on this image.
- Edit the `environment:` block in `docker-compose.yml` for your org/repo
  scope — same variables as [Configuration](configuration.md).
- The container binds `0.0.0.0` internally so Docker's port mapping can
  reach it (a container bound to `127.0.0.1` is unreachable through `-p`
  mapping) — every other install still binds `127.0.0.1` exactly as
  before. `docker-compose.yml`'s port mapping is pinned to
  `127.0.0.1:8090:8090` on the host side so the container stays
  loopback-only end to end, matching [Security & safety](security-and-safety.md).
  Do not map `0.0.0.0:8090:8090` without TLS in front; TOTP is already
  required to start the process.

**Runner jobs that need Docker of their own** (a workflow with `docker
build`/`docker run` steps) won't work out of the box: the runner processes
this container spawns run inside that same container, and this image
doesn't set up Docker-in-Docker or mount the host's Docker socket. Add
either yourself if you need it — this is the same tradeoff every
self-hosted-runner-in-Docker setup has to make, not something specific to
RunnerDeck.

**Podman** works too — `podman compose up` (or `podman-compose`)
consumes the same `Dockerfile`/`docker-compose.yml` as-is. The volume
mounts carry a `:z` SELinux relabel option for this, needed on
SELinux-enforcing distros (e.g. Fedora) for a rootless Podman container to
actually be able to read/write them; Docker just ignores it where SELinux
isn't in play.
