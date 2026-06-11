# Roadmap: MVP → 1.0

Principle: every minor release is installable, upgradeable and useful on its own.
Pre-1.0 minors may break; the changelog always says so.

## 0.1 — MVP: "logs arrive and you can see them"
- Go agent: file tailing, rotation handling, batching, retry/backoff, heartbeat,
  systemd unit, YAML config
- Panel: ingest API with token auth, server list with online/offline,
  log stream view with filters (server, level, service, time, search)
- Regex-based level classification, fingerprinting + error groups
- Single admin user (created by installer), sessions, CSRF
- Docker Compose stack, migrations, `install.sh`, `install-agent.sh`
- CI: lint + tests for PHP/Go, shellcheck

## 0.2 — AI analysis
- Provider abstraction: OpenAI, Anthropic, OpenAI-compatible (Ollama etc.)
- Masking pipeline + masking preview UI + fixture test suite
- Analysis cache by fingerprint, daily budget cap, re-analyze action
- Error detail page: explanation, causes, impact, severity, steps, commands

## 0.3 — Notifications
- Discord + Gotify adapters, channel test button
- Rule engine: 4 triggers, filters, cooldowns; rate limiting + dedupe
- Notification history page ("why (didn't) I get pinged")
- Server offline/recovery detection job

## 0.4 — Multi-user & settings
- User CRUD, admin/user roles, RBAC middleware, audit log
- Settings UI: AI provider (write-only keys), retention, thresholds
- Agent token rotation; per-token rate limits

## 0.5 — Triage quality
- Error group statuses (ack/resolve/ignore) with mute semantics
- Recurrence detection + patterns dashboard widget
- Full-text search (tsvector), saved filters, CSV export

## 0.6 — Agent hardening & sources
- journald reader (in addition to files), Docker container log discovery
- Multiline aggregation (stack traces), glob watches, disk spool for outages
- Agent self-metrics in heartbeat (lag, dropped lines) shown in panel

## 0.7 — Operability
- Health/readiness endpoints, Prometheus `/metrics` (optional)
- Backup/restore documentation, `console backup` helper
- i18n scaffolding (en/de), dark mode

## 0.8 — Scale & performance
- Optional daily partitioning for `log_entries` + partition-drop retention
- Worker horizontal scaling docs, ingest load tests in CI (k6)
- Index review with realistic data volumes

## 0.9 — Security & release hardening
- External-style security review against docs/security.md checklist
- Rate-limit tuning, fuzzing the ingest parser, dependency audit gates in CI
- Release automation polish: signed checksums, SBOM, image digest pinning
- Beta period with migration tests 0.1→0.9

## 1.0 — Stable
- Frozen v1 API contract (agents and panel may be upgraded independently)
- Guaranteed upgrade path + tested rollback notes
- Complete docs site, demo compose file with sample data generator

## Post-1.0 ideas (not committed)
- Slack / Telegram / ntfy / e-mail adapters
- Loki/Elastic as optional storage backend (keep Logwatch2 as AI triage layer)
- Anomaly detection on error-rate time series
- Agent for Windows/macOS hosts
