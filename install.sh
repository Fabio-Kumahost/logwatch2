#!/usr/bin/env bash
#
# Logwatch2 panel installer
#   curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/install.sh | bash
#
# Flags:
#   --dir PATH          install directory          (default /opt/logwatch2)
#   --port N            panel port on localhost    (default 8080)
#   --version vX.Y.Z    release to install         (default: latest)
#   --non-interactive   no prompts; fail instead of asking
#   --force             allow installing over an existing directory
#   --no-start          prepare everything but don't start the stack
#
set -euo pipefail

REPO="Fabio-Kumahost/logwatch2"
INSTALL_DIR="${LW2_DIR:-/opt/logwatch2}"
PANEL_PORT="${LW2_PORT:-8080}"
ADMIN_USER="${LW2_ADMIN_USER:-admin}"
VERSION="latest"
NON_INTERACTIVE=0
FORCE=0
NO_START=0

# ---------- ui helpers ----------
c_red=$'\033[0;31m'; c_grn=$'\033[0;32m'; c_yel=$'\033[0;33m'; c_off=$'\033[0m'
info()  { printf '%s[*]%s %s\n' "$c_grn" "$c_off" "$*"; }
warn()  { printf '%s[!]%s %s\n' "$c_yel" "$c_off" "$*"; }
die()   { printf '%s[x]%s %s\n' "$c_red" "$c_off" "$*" >&2; exit 1; }

ask_yes() { # ask_yes "question" — true if yes; non-interactive ⇒ no
  [ "$NON_INTERACTIVE" -eq 1 ] && return 1
  read -r -p "$1 [y/N] " reply </dev/tty || return 1
  [[ "$reply" =~ ^[Yy]$ ]]
}

# ---------- parse args ----------
while [ $# -gt 0 ]; do
  case "$1" in
    --dir)             INSTALL_DIR="$2"; shift 2 ;;
    --port)            PANEL_PORT="$2"; shift 2 ;;
    --version)         VERSION="$2"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE=1; shift ;;
    --force)           FORCE=1; shift ;;
    --no-start)        NO_START=1; shift ;;
    *) die "unknown flag: $1 (see header of this script for usage)" ;;
  esac
done

# ---------- pre-flight ----------
info "Logwatch2 installer starting"

[ "$(uname -s)" = "Linux" ] || die "the panel installer supports Linux only"

if [ "$(id -u)" -eq 0 ]; then
  warn "running as root — the stack itself runs fine, but consider a regular"
  warn "user in the 'docker' group for day-to-day operation."
  if ! ask_yes "continue as root?"; then
    [ "$NON_INTERACTIVE" -eq 1 ] || die "aborted"
    warn "non-interactive mode: continuing as root"
  fi
fi

for cmd in curl openssl tar; do
  command -v "$cmd" >/dev/null 2>&1 || die "required command missing: $cmd"
done

# No blind overwrites: an existing install dir stops us unless --force.
if [ -e "$INSTALL_DIR" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
  if [ "$FORCE" -eq 1 ]; then
    [ -f "$INSTALL_DIR/.env" ] && warn "keeping existing .env (secrets preserved)"
  else
    die "$INSTALL_DIR exists and is not empty — upgrade with: cd $INSTALL_DIR && docker compose pull && docker compose up -d
    or re-run with --force if you know what you're doing"
  fi
fi

# ---------- docker ----------
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  info "found $(docker --version | cut -d, -f1) with compose plugin"
else
  warn "Docker with the compose plugin is required and was not found."
  if ask_yes "install Docker now via get.docker.com?"; then
    curl -fsSL https://get.docker.com | sh || die "Docker installation failed"
    info "Docker installed"
  else
    die "install Docker manually (https://docs.docker.com/engine/install/) and re-run"
  fi
fi
docker info >/dev/null 2>&1 || die "cannot talk to the Docker daemon — is it running? are you in the 'docker' group?"

# ---------- port check ----------
port_in_use() {
  if command -v ss >/dev/null 2>&1; then ss -lnt "( sport = :$1 )" 2>/dev/null | grep -q LISTEN
  else (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && exec 3>&-; fi
}
if port_in_use "$PANEL_PORT"; then
  die "port $PANEL_PORT is already in use — choose another with --port N"
fi
info "port $PANEL_PORT is free"

# ---------- download ----------
mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"

if [ "$VERSION" = "latest" ]; then
  VERSION="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" \
    | grep -m1 '"tag_name"' | cut -d'"' -f4 || true)"
  [ -n "$VERSION" ] || die "could not determine the latest release — specify --version vX.Y.Z"
fi
info "installing release $VERSION into $INSTALL_DIR"

TARBALL_URL="https://github.com/$REPO/releases/download/$VERSION/logwatch2-$VERSION.tar.gz"
SUMS_URL="$TARBALL_URL.sha256"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

curl -fsSL -o "$TMP/release.tar.gz" "$TARBALL_URL" || die "download failed: $TARBALL_URL"
curl -fsSL -o "$TMP/release.sha256" "$SUMS_URL"   || die "checksum download failed: $SUMS_URL"
( cd "$TMP" && printf '%s  release.tar.gz\n' "$(cut -d' ' -f1 release.sha256)" | sha256sum -c - ) \
  || die "checksum verification FAILED — refusing to install"
info "checksum verified"

tar -xzf "$TMP/release.tar.gz" -C "$INSTALL_DIR" --strip-components=1

# ---------- .env with random secrets ----------
if [ -f .env ]; then
  warn "existing .env kept unchanged"
else
  APP_KEY="$(openssl rand -base64 32)"
  DB_PASSWORD="$(openssl rand -hex 24)"
  sed -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
      -e "s|^PANEL_PORT=.*|PANEL_PORT=$PANEL_PORT|" \
      .env.example > .env
  chmod 600 .env
  info "generated .env with random secrets (mode 600)"
fi

[ "$NO_START" -eq 1 ] && { info "--no-start: stack prepared in $INSTALL_DIR — start with: docker compose up -d"; exit 0; }

# ---------- start & initialize ----------
info "starting the stack (first start pulls images, may take a minute)"
docker compose up -d --quiet-pull || die "docker compose up failed — see: docker compose logs"

info "waiting for the panel to become healthy"
for i in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:$PANEL_PORT/healthz" >/dev/null 2>&1; then break; fi
  [ "$i" -eq 60 ] && die "panel did not become healthy within 120s — see: docker compose logs web app"
  sleep 2
done
info "panel is up"

docker compose exec -T app php bin/console migrate || die "database migration failed"

ADMIN_OUTPUT="$(docker compose exec -T app php bin/console create-admin --username "$ADMIN_USER" 2>&1)" \
  || { case "$ADMIN_OUTPUT" in *"already exists"*) warn "admin user exists — skipped";; *) die "admin creation failed: $ADMIN_OUTPUT";; esac; }

# ---------- summary ----------
printf '\n%s──────────────────────────────────────────────%s\n' "$c_grn" "$c_off"
info "Logwatch2 $VERSION installed successfully"
printf '\n  Panel URL : http://127.0.0.1:%s  (put TLS in front for production!)\n' "$PANEL_PORT"
printf '%s\n' "$ADMIN_OUTPUT" | sed 's/^/  /'
printf '\n  Add a server: log in → Servers → Add server → run the shown command, e.g.\n'
printf '  curl -fsSL https://raw.githubusercontent.com/%s/main/scripts/install-agent.sh \\\n' "$REPO"
printf '    | sudo bash -s -- --panel-url https://your-panel --token lw2_…\n'
printf '\n  Manage     : cd %s && docker compose {ps|logs|down}\n' "$INSTALL_DIR"
printf '  Docs       : https://github.com/%s/tree/main/docs\n\n' "$REPO"
