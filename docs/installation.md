# Installation

> **Prerequisite:** a (sub)domain for the panel, e.g. `logs.example.com`,
> with an A/AAAA record pointing to the server. The panel always runs on its
> own domain behind HTTPS — that is the supported setup.

## One-line install (panel)

```bash
curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/install.sh | bash
```

The installer is an interactive wizard. It walks through 7 steps, prints what
it found at each one, and fails loudly with a hint when something is off:

1. **System requirements** — Linux, curl/openssl/tar, target directory
   (`/opt/logwatch2`); **refuses to touch an existing installation** unless `--force`.
2. **Domain & DNS** — asks for the panel domain, resolves it and compares it
   with the server's public IP (mismatch → warning, e.g. when a CDN/proxy sits
   in front). Then asks: bundled automatic HTTPS (Caddy, recommended) or
   *behind my own reverse proxy*. Also asks for the admin username.
3. **Docker** — detects Docker + compose plugin, offers installation via
   `get.docker.com`.
4. **Ports** — Caddy mode: 80+443 must be free · proxy mode: 8080 must be free.
5. **Download** — fetches the release tarball and verifies its SHA-256.
6. **Configuration** — generates `APP_KEY`/`DB_PASSWORD`, sets
   `APP_URL=https://<domain>`, writes `.env` (mode 600). In Caddy mode it also
   sets `COMPOSE_FILE=docker-compose.yml:docker-compose.tls.yml`, so every
   plain `docker compose …` command automatically includes the TLS overlay.
7. **Start & initialize** — `docker compose up -d`, waits for app health,
   runs migrations, creates the admin (password **printed once**), then waits
   for the Let's Encrypt certificate and prints a summary with the panel URL
   and the agent install command.

Flags: `--domain logs.example.com` · `--admin-user NAME` · `--behind-proxy` ·
`--dir PATH` · `--version vX.Y.Z` · `--non-interactive` · `--force` ·
`--no-start`. Environment overrides: `LW2_DOMAIN`, `LW2_DIR`, `LW2_ADMIN_USER`.

Unattended example (CI / cloud-init — Docker is auto-installed when missing):

```bash
curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/install.sh \
  | bash -s -- --non-interactive --domain logs.example.com
```

## Agent install (per monitored server)

In the panel: *Servers → Add server* → copy the generated command:

```bash
curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/scripts/install-agent.sh \
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
git clone https://github.com/Fabio-Kumahost/logwatch2.git /opt/logwatch2
cd /opt/logwatch2
cp .env.example .env   # set APP_KEY (openssl rand -base64 32) and DB_PASSWORD
docker compose up -d --build
docker compose exec app php bin/console migrate
docker compose exec app php bin/console create-admin --username admin
```

## Accessing the panel ("I opened http://MY-IP and nothing happens")

The panel is only served via **https://your-domain** (Caddy mode) — there is
deliberately nothing listening publicly on the bare IP. If the domain doesn't
load: check the A record, ports 80/443 in the provider firewall, and
`docker compose logs caddy`.

In `--behind-proxy` mode the stack binds to **`127.0.0.1:8080` only**; your
reverse proxy terminates TLS (example: `examples/nginx-reverse-proxy.conf`).
For a quick look without any exposure:
`ssh -L 8080:127.0.0.1:8080 user@your-vps` → `http://localhost:8080`.
A direct `PANEL_BIND=0.0.0.0` exposure without TLS exists for labs but is
unsupported for real use — logins would travel in plaintext.

## TLS modes recap

- **Caddy (default):** automatic Let's Encrypt for `PANEL_DOMAIN`; the
  installer writes `COMPOSE_FILE` into `.env`, so plain `docker compose`
  commands keep including the overlay.
- **Your own proxy (`--behind-proxy`):** point it at `http://127.0.0.1:8080`,
  set `APP_URL=https://…` (the installer already did) so notification links
  are correct.

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
