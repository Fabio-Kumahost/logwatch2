# Logwatch2

**Self-hosted server log monitoring with AI-powered error analysis.**

Logwatch2 collects logs from your Linux servers via a lightweight Go agent, ships them
to a central web panel, classifies and groups errors, and uses an LLM (OpenAI, Claude,
local models, or any OpenAI-compatible API) to explain *what happened, why, how urgent
it is, and how to fix it* — with sensitive data masked before anything leaves your box.

> ⚠️ Project status: **pre-1.0** — APIs and schema may change between minor versions.

---

## Features

- 🖥 **Central dashboard** — all servers at a glance: online / offline / warning / critical
- 📡 **Lightweight Go agent** — tails files **and the systemd journal**, unwraps Docker
  json-file logs, joins multiline stack traces; systemd service, auto-reconnect,
  disk spool so no line is lost while the panel is down
- 🤖 **AI analysis** — plain-language explanation, probable causes, impact, severity,
  concrete fix steps and example Linux commands per error (OpenAI, Claude, Ollama, …)
- 🧠 **Smart caching & grouping** — identical errors are fingerprinted and grouped;
  each unique error is analyzed once, not on every occurrence
- 🛡️ **Security Radar** — detects SSH/auth brute-force attacks from the log stream
  (threshold per window, zero AI cost) and alerts immediately
- 📈 **Anomaly detection** — flags error-rate spikes against each server's own
  7-day statistical baseline (mean + 3σ), no configuration needed
- 🔒 **Privacy by design** — IPs, e-mails, tokens and passwords are masked *before*
  any AI request (with masking preview in the UI); local models for zero egress
- 🔔 **Notifications** — Discord & Gotify with rate limiting, cooldowns, dedupe,
  8 triggers (incl. auth attack, anomaly, weekly digest) and AI summaries
- 🔐 **2FA (TOTP)** — RFC 6238 two-factor login, compatible with any authenticator app
- 📊 **Prometheus metrics** — optional `/metrics` endpoint for your existing monitoring
- 👥 **Users & roles** — admin / user roles, audit log, per-server agent tokens (hashed,
  rotatable)
- 🐳 **Docker-first** — one-line installer, `docker compose up`, hardened containers,
  sane secure defaults

## Quick start

You need a (sub)domain pointing at your server — the panel always runs behind
HTTPS on its own domain (automatic Let's Encrypt via bundled Caddy).

```bash
curl -fsSL https://raw.githubusercontent.com/Fabio-Kumahost/logwatch2/main/install.sh | bash
```

The interactive wizard asks for your domain (with DNS verification) and admin
username, checks Docker and ports, generates secrets, starts the stack, runs
migrations and prints your admin login plus the agent install command.
Unattended mode:

```bash
curl -fsSL .../install.sh | bash -s -- --non-interactive --domain logs.example.com
```

Manual installation, agent setup and TLS are covered in [`docs/installation.md`](docs/installation.md).

## Architecture (short version)

```
┌────────────┐  HTTPS + Bearer token   ┌──────────────────────────────────┐
│ Go agent   │ ───────────────────────▶│  Panel (PHP 8.3 / Slim 4)        │
│ per server │  POST /api/v1/ingest    │  nginx ── php-fpm ── worker      │
└────────────┘                         │        │                          │
   tails files, batches,               │  PostgreSQL 16 (logs, groups,    │
   retries with backoff                │  analyses, users, settings)      │
                                       └───────────┬──────────────────────┘
                                                   │ masked excerpt only
                                       ┌───────────▼──────────┐
                                       │ AI provider (OpenAI, │
                                       │ Claude, Ollama, …)   │
                                       └──────────────────────┘
```

Full details: [`docs/architecture.md`](docs/architecture.md)

## Documentation

| Document | Contents |
|---|---|
| [docs/architecture.md](docs/architecture.md) | System architecture & technology choices |
| [docs/database.md](docs/database.md) | Schema, indexing, retention |
| [docs/api.md](docs/api.md) | REST API (agent + panel) |
| [docs/security.md](docs/security.md) | Threat model, auth, secrets, hardening |
| [docs/ai-analysis.md](docs/ai-analysis.md) | AI pipeline, providers, caching, prompts |
| [docs/privacy.md](docs/privacy.md) | Masking of sensitive data, GDPR notes |
| [docs/notifications.md](docs/notifications.md) | Discord / Gotify, rules, rate limiting |
| [docs/installation.md](docs/installation.md) | Installer, manual setup, TLS, upgrades |
| [docs/roadmap.md](docs/roadmap.md) | MVP → 1.0 roadmap |

## Repository layout

```
backend/    PHP panel (Slim 4): API, web UI, AI pipeline, workers
agent/      Go agent: tailing, batching, shipping, systemd unit
docker/     nginx config and container support files
docs/       documentation (see table above)
scripts/    install-agent.sh and maintenance scripts
examples/   example configs
install.sh  one-line panel installer
```

## Requirements

- **Panel:** Linux host with Docker ≥ 24 and the Docker Compose plugin, 1 GB RAM minimum
- **Agent:** any Linux with systemd (amd64/arm64), no runtime dependencies (static binary)
- **AI (optional):** API key for OpenAI/Anthropic, or a local OpenAI-compatible endpoint
  (e.g. Ollama) — the panel works without AI, you just lose the explanations

## Contributing & security

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).
Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE)
