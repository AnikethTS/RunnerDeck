# Docker

An alternative to the [WSL2 path](getting-started.md#platform-support) on
Windows, or just a self-contained way to run RunnerDeck without installing
PHP directly:

```bash
docker compose up --build
```

This builds from the included `Dockerfile` (PHP 8.3 on Alpine, with `gh`
installed and checksum-verified) and starts the container per
`docker-compose.yml`:

- **`./data`** is mounted to `/data` inside the container and holds
  everything RunnerDeck would otherwise put in `<repo>/../runners` and
  `storage/` — the runner pool, `settings.json`, the history database, and
  `runnerdeck.log`.
  Delete it to reset RunnerDeck to a blank state.
- **`~/.config/gh`** is mounted read-only so the container reuses `gh` auth
  already set up on the host — run `gh auth login` on the host first. Drop
  that volume line and run `docker compose exec runnerdeck gh auth login`
  once instead if you'd rather authenticate inside the container.
- Edit the `environment:` block in `docker-compose.yml` for your org/repo
  scope — same variables as [Configuration](configuration.md).
- The container binds `0.0.0.0` internally so Docker's port mapping can
  reach it (a container bound to `127.0.0.1` is unreachable through `-p`
  mapping) — every other install still binds `127.0.0.1` exactly as
  before. `docker-compose.yml`'s port mapping is pinned to
  `127.0.0.1:8090:8090` on the host side so the container stays
  loopback-only end to end, matching [Security & safety](security-and-safety.md);
  don't widen that mapping unless you specifically intend to expose this
  beyond your own machine.

**Runner jobs that need Docker of their own** (a workflow with `docker
build`/`docker run` steps) won't work out of the box: the runner processes
this container spawns run inside that same container, and this image
doesn't set up Docker-in-Docker or mount the host's Docker socket. Add
either yourself if you need it — this is the same tradeoff every
self-hosted-runner-in-Docker setup has to make, not something specific to
RunnerDeck.

**Podman** works too — `podman compose up --build` (or `podman-compose`)
consumes the same `Dockerfile`/`docker-compose.yml` as-is. The volume
mounts carry a `:z` SELinux relabel option for this, needed on
SELinux-enforcing distros (e.g. Fedora) for a rootless Podman container to
actually be able to read/write them; Docker just ignores it where SELinux
isn't in play.
