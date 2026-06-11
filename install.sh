#!/usr/bin/env bash
#
# Logwatch2 panel installer — interactive wizard.
#   curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/install.sh | bash
#
# The panel always runs on its own (sub)domain behind HTTPS. The wizard asks
# for the domain, verifies DNS, and either provisions automatic TLS via the
# bundled Caddy (default) or prepares the stack for your existing proxy.
#
# Flags (all optional in interactive mode):
#   --domain logs.example.com   panel domain (required with --non-interactive)
#   --admin-user NAME           admin account name (default: admin)
#   --behind-proxy              no bundled Caddy; binds 127.0.0.1:8080 for your own proxy
#   --dir PATH                  install directory (default: /opt/logwatch2)
#   --version vX.Y.Z            release to install (default: latest)
#   --non-interactive           no prompts; fail instead of asking
#   --force                     allow installing over an existing directory
#   --no-start                  prepare everything but don't start the stack
#
set -euo pipefail

REPO="Fabio-Kumahost/logwatch2"
INSTALL_DIR="${LW2_DIR:-/opt/logwatch2}"
ADMIN_USER="${LW2_ADMIN_USER:-admin}"
DOMAIN="${LW2_DOMAIN:-}"
VERSION="latest"
BEHIND_PROXY=0
NON_INTERACTIVE=0
FORCE=0
NO_START=0
STEPS=7
STEP_NO=0

# ---------- ui ----------
c_red=$'\033[0;31m'; c_grn=$'\033[0;32m'; c_yel=$'\033[0;33m'
c_cyn=$'\033[0;36m'; c_bld=$'\033[1m'; c_off=$'\033[0m'

banner() {
  printf '\n%s════════════════════════════════════════════════%s\n' "$c_cyn" "$c_off"
  printf '   📡 %sLogwatch2%s — self-hosted log monitoring\n' "$c_bld" "$c_off"
  printf '%s════════════════════════════════════════════════%s\n\n' "$c_cyn" "$c_off"
}
step() { STEP_NO=$((STEP_NO + 1)); printf '\n%s[%d/%d]%s %s%s%s\n' "$c_cyn" "$STEP_NO" "$STEPS" "$c_off" "$c_bld" "$1" "$c_off"; }
ok()   { printf '   %s✓%s %s\n' "$c_grn" "$c_off" "$1"; }
warn() { printf '   %s!%s %s\n' "$c_yel" "$c_off" "$1"; }
die()  { printf '   %s✗ %s%s\n' "$c_red" "$*" "$c_off" >&2; exit 1; }

confirm() { # confirm "question" — default no
  [ "$NON_INTERACTIVE" -eq 1 ] && { warn "non-interactive: continuing"; return 0; }
  local r
  read -r -p "   $1 [y/N] " r </dev/tty || true
  [[ "$r" =~ ^[Yy]$ ]]
}

# ---------- args ----------
while [ $# -gt 0 ]; do
  case "$1" in
    --domain)          DOMAIN="$2"; shift 2 ;;
    --admin-user)      ADMIN_USER="$2"; shift 2 ;;
    --behind-proxy)    BEHIND_PROXY=1; shift ;;
    --dir)             INSTALL_DIR="$2"; shift 2 ;;
    --version)         VERSION="$2"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE=1; shift ;;
    --force)           FORCE=1; shift ;;
    --no-start)        NO_START=1; shift ;;
    *) die "unknown flag: $1 (see the header of this script for usage)" ;;
  esac
done

banner

# ---------- [1] system requirements ----------
step "Checking system requirements"
[ "$(uname -s)" = "Linux" ] || die "the panel installer supports Linux only"
for cmd in curl openssl tar; do
  command -v "$cmd" >/dev/null 2>&1 || die "required command missing: $cmd"
done
ok "Linux, curl, openssl, tar"

if [ "$(id -u)" -eq 0 ]; then
  warn "running as root — works fine; a regular user in the 'docker' group also suffices"
fi

if [ -e "$INSTALL_DIR" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
  if [ "$FORCE" -eq 1 ]; then
    warn "existing directory $INSTALL_DIR — continuing (--force); existing .env will be kept"
  else
    die "$INSTALL_DIR exists and is not empty.
   Upgrade instead:  cd $INSTALL_DIR && docker compose pull && docker compose up -d
   Or re-run with --force if you know what you're doing."
  fi
fi
ok "install directory: $INSTALL_DIR"

# ---------- [2] domain & DNS ----------
step "Panel domain (the panel always runs on its own subdomain)"
domain_valid() { [[ "$1" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$ ]]; }

while ! domain_valid "${DOMAIN:-}"; do
  if [ -n "${DOMAIN:-}" ]; then
    [ "$NON_INTERACTIVE" -eq 1 ] && die "invalid domain: $DOMAIN"
    warn "'$DOMAIN' does not look like a valid domain"
  elif [ "$NON_INTERACTIVE" -eq 1 ]; then
    die "--domain is required in non-interactive mode"
  fi
  printf '   The domain must have an A/AAAA record pointing to this server,\n'
  printf '   e.g. %slogs.example.com%s\n' "$c_bld" "$c_off"
  read -r -p "   Panel domain: " DOMAIN </dev/tty
done
ok "domain: $DOMAIN"

SERVER_IP="$(curl -4fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR==1{print $1}' || true)"
if [ -z "$DNS_IP" ]; then
  warn "DNS: '$DOMAIN' does not resolve yet — HTTPS certificates will fail until the record exists"
  confirm "continue anyway?" || die "aborted — create the DNS record first, then re-run"
elif [ -n "$SERVER_IP" ] && [ "$DNS_IP" != "$SERVER_IP" ]; then
  warn "DNS: $DOMAIN → $DNS_IP, but this server's public IP is $SERVER_IP"
  warn "(fine when a proxy/CDN sits in front — otherwise fix the A record)"
  confirm "continue anyway?" || die "aborted"
else
  ok "DNS: $DOMAIN → ${DNS_IP:-unknown}"
fi

if [ "$BEHIND_PROXY" -eq 0 ] && [ "$NON_INTERACTIVE" -eq 0 ]; then
  printf '   TLS setup:  %s[1]%s bundled automatic HTTPS via Caddy (recommended)\n' "$c_bld" "$c_off"
  printf '               %s[2]%s I already run a reverse proxy on this host\n' "$c_bld" "$c_off"
  read -r -p "   Choose [1]: " tls_choice </dev/tty || true
  [ "${tls_choice:-1}" = "2" ] && BEHIND_PROXY=1
fi

if [ "$NON_INTERACTIVE" -eq 0 ]; then
  read -r -p "   Admin username [$ADMIN_USER]: " admin_in </dev/tty || true
  ADMIN_USER="${admin_in:-$ADMIN_USER}"
fi
[[ "$ADMIN_USER" =~ ^[a-zA-Z0-9_.-]{3,64}$ ]] || die "invalid admin username: $ADMIN_USER"

# ---------- [3] docker ----------
step "Checking Docker"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  ok "$(docker --version | cut -d, -f1) with compose plugin"
else
  warn "Docker with the compose plugin was not found"
  if confirm "install Docker now via get.docker.com?"; then
    curl -fsSL https://get.docker.com | sh || die "Docker installation failed"
    ok "Docker installed"
  else
    die "install Docker manually (https://docs.docker.com/engine/install/) and re-run"
  fi
fi
docker info >/dev/null 2>&1 || die "cannot talk to the Docker daemon — is it running? are you in the 'docker' group?"

# ---------- [4] ports ----------
step "Checking ports"
port_in_use() {
  if command -v ss >/dev/null 2>&1; then ss -lnt "( sport = :$1 )" 2>/dev/null | grep -q LISTEN
  else (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && exec 3>&-; fi
}
if [ "$BEHIND_PROXY" -eq 0 ]; then
  for p in 80 443; do
    port_in_use "$p" && die "port $p is in use — Caddy needs 80+443.
   Already running a webserver? Re-run with --behind-proxy and proxy to 127.0.0.1:8080."
  done
  ok "ports 80 and 443 are free (make sure your provider firewall allows them)"
else
  port_in_use 8080 && die "port 8080 is in use — free it or change PANEL_PORT in .env afterwards"
  ok "port 8080 (localhost) is free for your reverse proxy"
fi

# ---------- [5] download ----------
step "Downloading Logwatch2"
mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"

if [ "$VERSION" = "latest" ]; then
  # Capture first, parse second — grep -m1 on a live pipe makes curl emit a
  # spurious "(23) Failure writing output" when it closes the pipe early.
  api_json="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" 2>/dev/null || true)"
  VERSION="$(printf '%s\n' "$api_json" | grep -m1 '"tag_name"' | cut -d'"' -f4 || true)"
  [ -n "$VERSION" ] || die "could not determine the latest release — specify --version vX.Y.Z"
fi
TARBALL_URL="https://github.com/$REPO/releases/download/$VERSION/logwatch2-$VERSION.tar.gz"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
curl -fsSL -o "$TMP/release.tar.gz" "$TARBALL_URL" || die "download failed: $TARBALL_URL"
curl -fsSL -o "$TMP/release.sha256" "$TARBALL_URL.sha256" || die "checksum download failed"
( cd "$TMP" && printf '%s  release.tar.gz\n' "$(cut -d' ' -f1 release.sha256)" | sha256sum -c - >/dev/null ) \
  || die "checksum verification FAILED — refusing to install"
tar -xzf "$TMP/release.tar.gz" -C "$INSTALL_DIR" --strip-components=1

# Sanity: everything compose will reference must exist NOW — failing here
# beats failing mid-start with a half-installed stack.
for f in docker-compose.yml docker-compose.tls.yml .env.example backend/Dockerfile; do
  [ -e "$INSTALL_DIR/$f" ] || die "release tarball is missing '$f' — please report this at
   https://github.com/$REPO/issues (you can install a specific version with --version)"
done
ok "release $VERSION verified and unpacked"

# ---------- [6] configuration ----------
step "Writing configuration"
if [ -f .env ]; then
  warn "existing .env kept unchanged (secrets preserved)"
else
  APP_KEY="$(openssl rand -base64 32)"
  DB_PASSWORD="$(openssl rand -hex 24)"
  sed -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
      -e "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" \
      .env.example > .env
  {
    printf '\n# --- written by install.sh ---\n'
    printf 'PANEL_DOMAIN=%s\n' "$DOMAIN"
    if [ "$BEHIND_PROXY" -eq 0 ]; then
      # compose picks this up automatically: plain `docker compose` commands
      # always include the TLS overlay from now on.
      printf 'COMPOSE_FILE=docker-compose.yml:docker-compose.tls.yml\n'
    fi
  } >> .env
  chmod 600 .env
  ok "generated .env with random secrets (mode 600)"
fi

if [ "$NO_START" -eq 1 ]; then
  printf '\n'
  ok "--no-start: everything is prepared in $INSTALL_DIR"
  ok "start later with: cd $INSTALL_DIR && docker compose up -d"
  exit 0
fi

# ---------- [7] start & initialize ----------
step "Starting the stack (first start pulls images — may take a minute)"
docker compose up -d --quiet-pull || die "docker compose up failed — inspect: docker compose logs"

printf '   waiting for the application '
APP_OK=0
for _ in $(seq 1 60); do
  if docker compose exec -T web wget -qO- http://127.0.0.1/healthz >/dev/null 2>&1; then APP_OK=1; break; fi
  printf '.'
  sleep 2
done
printf '\n'
[ "$APP_OK" -eq 1 ] || die "panel did not become healthy within 120s — see: docker compose logs web app db"
ok "application is healthy"

docker compose exec -T app php bin/console migrate >/dev/null || die "database migration failed"
ok "database migrated"

set +e
ADMIN_OUTPUT="$(docker compose exec -T app php bin/console create-admin --username "$ADMIN_USER" 2>&1)"
ADMIN_RC=$?
set -e
if [ "$ADMIN_RC" -ne 0 ]; then
  case "$ADMIN_OUTPUT" in
    *"already exists"*) warn "admin user '$ADMIN_USER' already exists — kept" ;;
    *) die "admin creation failed: $ADMIN_OUTPUT" ;;
  esac
fi

TLS_OK=0
if [ "$BEHIND_PROXY" -eq 0 ]; then
  printf '   waiting for the HTTPS certificate (Let'\''s Encrypt) '
  for _ in $(seq 1 24); do
    if curl -fsS --max-time 5 "https://$DOMAIN/healthz" >/dev/null 2>&1; then TLS_OK=1; break; fi
    printf '.'
    sleep 5
  done
  printf '\n'
  if [ "$TLS_OK" -eq 1 ]; then
    ok "https://$DOMAIN is live"
  else
    warn "certificate not confirmed yet — usually DNS or firewall (80/443)."
    warn "check: docker compose logs caddy   (the panel keeps retrying automatically)"
  fi
fi

# ---------- summary ----------
printf '\n%s════════════ installation complete ════════════%s\n\n' "$c_grn" "$c_off"
printf '   Panel    : %shttps://%s%s\n' "$c_bld" "$DOMAIN" "$c_off"
if [ "$BEHIND_PROXY" -eq 1 ]; then
  printf '   Proxy    : point your reverse proxy at %shttp://127.0.0.1:8080%s\n' "$c_bld" "$c_off"
  printf '              (example config: examples/nginx-reverse-proxy.conf)\n'
fi
printf '%s\n' "${ADMIN_OUTPUT:-}" | sed 's/^/   /'
printf '\n   Add a server : log in → Settings → Add server, then on each machine:\n'
printf '   curl -fsSL https://raw.githubusercontent.com/%s/main/scripts/install-agent.sh \\\n' "$REPO"
printf '     | sudo bash -s -- --panel-url https://%s --token lw2_…\n' "$DOMAIN"
printf '\n   Manage   : cd %s && docker compose ps|logs|restart\n' "$INSTALL_DIR"
printf '   Upgrade  : cd %s && docker compose pull && docker compose up -d\n' "$INSTALL_DIR"
printf '   Docs     : https://github.com/%s/tree/main/docs\n\n' "$REPO"
