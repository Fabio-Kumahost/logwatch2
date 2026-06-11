#!/usr/bin/env bash
#
# Logwatch2 agent installer (run on each monitored server, as root)
#   curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/scripts/install-agent.sh \
#     | sudo bash -s -- --panel-url https://logs.example.com --token lw2_xxxx
#
# Idempotent: re-running upgrades the binary and keeps existing config.
#
set -euo pipefail

REPO="Fabio-Kumahost/logwatch2"
PANEL_URL="" TOKEN="" VERSION="latest"

info() { printf '\033[0;32m[*]\033[0m %s\n' "$*"; }
die()  { printf '\033[0;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --panel-url) PANEL_URL="$2"; shift 2 ;;
    --token)     TOKEN="$2"; shift 2 ;;
    --version)   VERSION="$2"; shift 2 ;;
    *) die "unknown flag: $1" ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "run as root (sudo) — needed for systemd and /etc"
command -v systemctl >/dev/null 2>&1 || die "systemd is required"
[ -n "$PANEL_URL" ] || die "--panel-url is required"
case "$PANEL_URL" in
  https://*) : ;;
  http://*)  printf '\033[0;33m[!]\033[0m panel URL is plain http — only acceptable in an isolated lab\n' ;;
  *) die "--panel-url must start with https://" ;;
esac

NEW_INSTALL=0
if [ ! -f /etc/logwatch2/agent.yaml ]; then
  NEW_INSTALL=1
  case "$TOKEN" in lw2_*) : ;; *) die "--token is required for first install (get it from the panel: Servers → Add server)";; esac
fi

case "$(uname -m)" in
  x86_64)  ARCH=amd64 ;;
  aarch64) ARCH=arm64 ;;
  *) die "unsupported architecture: $(uname -m)" ;;
esac

if [ "$VERSION" = "latest" ]; then
  VERSION="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" \
    | grep -m1 '"tag_name"' | cut -d'"' -f4)" || die "could not resolve latest release"
fi

info "downloading logwatch-agent $VERSION ($ARCH)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
BASE="https://github.com/$REPO/releases/download/$VERSION"
curl -fsSL -o "$TMP/agent"        "$BASE/logwatch-agent_linux_$ARCH"        || die "binary download failed"
curl -fsSL -o "$TMP/agent.sha256" "$BASE/logwatch-agent_linux_$ARCH.sha256" || die "checksum download failed"
( cd "$TMP" && printf '%s  agent\n' "$(cut -d' ' -f1 agent.sha256)" | sha256sum -c - ) \
  || die "checksum verification FAILED"

install -m 0755 "$TMP/agent" /usr/local/bin/logwatch-agent

# Dedicated unprivileged user; adm/systemd-journal grant read access to logs.
if ! id logwatch >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin logwatch
  usermod -aG adm logwatch 2>/dev/null || true
  usermod -aG systemd-journal logwatch 2>/dev/null || true
  info "created system user 'logwatch'"
fi

install -d -m 0750 -o logwatch -g logwatch /var/lib/logwatch-agent
install -d -m 0750 -o root -g logwatch /etc/logwatch2

if [ "$NEW_INSTALL" -eq 1 ]; then
  printf '%s\n' "$TOKEN" > /etc/logwatch2/agent.token
  chown root:logwatch /etc/logwatch2/agent.token
  chmod 0640 /etc/logwatch2/agent.token

  cat > /etc/logwatch2/agent.yaml <<EOF
panel:
  url: $PANEL_URL
  token_file: /etc/logwatch2/agent.token

state_file: /var/lib/logwatch-agent/state.json

sources:
  - path: /var/log/syslog
    service: syslog
  - path: /var/log/auth.log
    service: sshd
# Add nginx/apache/docker/gameserver logs here — see
# https://github.com/$REPO/blob/main/agent/config.example.yaml
EOF
  chown root:logwatch /etc/logwatch2/agent.yaml
  chmod 0640 /etc/logwatch2/agent.yaml
  info "wrote /etc/logwatch2/agent.yaml (edit it to add more log files)"
else
  info "existing config kept — binary upgraded"
fi

/usr/local/bin/logwatch-agent --check --config /etc/logwatch2/agent.yaml || die "config validation failed"

curl -fsSL -o /etc/systemd/system/logwatch-agent.service \
  "https://raw.githubusercontent.com/$REPO/$VERSION/agent/systemd/logwatch-agent.service" \
  || die "could not fetch systemd unit"
systemctl daemon-reload
systemctl enable --now logwatch-agent

sleep 2
if systemctl is-active --quiet logwatch-agent; then
  info "logwatch-agent $VERSION is running — the server should appear online in the panel within a minute"
  info "follow its log: journalctl -u logwatch-agent -f"
else
  die "service failed to start — inspect: journalctl -u logwatch-agent -n 50"
fi
