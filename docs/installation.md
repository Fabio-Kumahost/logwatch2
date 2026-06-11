# Installation

## One-line install (panel)

```bash
curl -fsSL https://raw.githubusercontent.com/USERNAME/logwatch2/main/install.sh | bash
```

What it does, in order — every step prints what it found and fails loudly:

1. **Pre-flight:** Linux + systemd check, `curl`/`openssl` present, warns when
   running as root (recommends a sudo-capable user), checks the target
   directory (`/opt/logwatch2` default) — **refuses to touch an existing
   installation** unless `--force`.
2. **Docker:** detects Docker ≥ 24 + compose plugin; offers to install via
   `get.docker.com` (asks first; `--non-interactive` requires preinstalled Docker
   and skips the offer).
3. **Ports:** checks `PANEL_PORT` (default 8080) is free; suggests alternatives.
4. **Download:** fetches the latest release tarball (or `--version vX.Y.Z`)
   and verifies its SHA-256 against the release checksum file.
5. **Secrets:** generates `APP_KEY` and `DB_PASSWORD` via `openssl rand`,
   writes `.env` (mode 600).
6. **Start:** `docker compose up -d`, waits for the health endpoint.
7. **Initialize:** runs migrations; creates the admin user with a random
   password — **printed once**, never stored in plaintext.
8. **Summary:** panel URL, admin credentials, agent install command, and where
   the data lives.

Flags: `--dir PATH` · `--port N` · `--version vX.Y.Z` · `--non-interactive` ·
`--force` · `--no-start` (prepare only). Environment overrides:
`LW2_DIR`, `LW2_PORT`, `LW2_ADMIN_USER`.

## Agent install (per monitored server)

In the panel: *Servers → Add server* → copy the generated command:

```bash
curl -fsSL https://raw.githubusercontent.com/USERNAME/logwatch2/main/scripts/install-agent.sh \
  | sudo bash -s -- --panel-url https://logs.example.com --token lw2_xxxxxxxx
```

The agent installer downloads the right binary (amd64/arm64) from GitHub
releases, verifies its checksum, creates the `logwatch` system user (added to
`adm` for log read access), writes `/etc/logwatch2/agent.yaml`, installs the
hardened systemd unit, and starts the service. Idempotent: re-running upgrades
the binary and keeps the config.

```bash
systemctl status logwatch-agent     # health
journalctl -u logwatch-agent -f     # agent's own log
```

## Manual installation (panel)

```bash
git clone https://github.com/USERNAME/logwatch2.git /opt/logwatch2
cd /opt/logwatch2
cp .env.example .env   # set APP_KEY (openssl rand -base64 32) and DB_PASSWORD
docker compose up -d --build
docker compose exec app php bin/console migrate
docker compose exec app php bin/console create-admin --username admin
```

## TLS

The stack listens on `127.0.0.1:${PANEL_PORT}` by default. For production:

- **Existing reverse proxy:** point Caddy/Traefik/nginx at
  `http://127.0.0.1:8080` and terminate TLS there (example snippets in
  `examples/`). Set `APP_URL=https://…` so links in notifications are correct.
- **Bundled Caddy:** `docker compose -f docker-compose.yml -f docker-compose.tls.yml up -d`
  with `PANEL_DOMAIN=logs.example.com` in `.env` — automatic Let's Encrypt.

Agents require HTTPS unless `allow_insecure: true` is set (lab use only).

## Upgrades

```bash
cd /opt/logwatch2
docker compose pull && docker compose up -d
docker compose exec app php bin/console migrate   # migrations are forward-only & idempotent
```

Pre-1.0: read the changelog before upgrading across minor versions.

## Uninstall

```bash
cd /opt/logwatch2 && docker compose down -v   # -v deletes the database volume!
sudo rm -rf /opt/logwatch2
# per agent host:
sudo systemctl disable --now logwatch-agent
sudo rm -rf /etc/logwatch2 /var/lib/logwatch-agent /usr/local/bin/logwatch-agent
```
