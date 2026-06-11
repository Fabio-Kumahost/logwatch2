# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) —
versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.1.0] — 2026-06-11

First release. Full panel + agent + AI pipeline, installable via one-liner.

### Core
- PHP 8.3 / Slim 4 panel: ingest API, dashboard, log stream with filters and
  full-text search, error-group triage (ack/resolve/ignore), settings UI
- Go agent: file tailing with rotation detection, batching, retry with backoff,
  disk spool for panel outages, heartbeats, hardened systemd unit
- PostgreSQL schema with migrations, DB-backed job queue (SKIP LOCKED workers)
- AI analysis with provider abstraction (OpenAI, Anthropic, OpenAI-compatible/
  Ollama), fingerprint-based cache, daily budget cap, structured results
  (explanation, causes, impact, severity, fix steps, safe commands)
- Sensitive-data masking before every AI request (stable placeholders,
  partial-IP mode, custom patterns, masking preview, audit hash)
- Discord & Gotify notifications: 8 triggers, per-rule cooldowns, hourly caps,
  dedupe, delivery history
- Users & roles (admin/user), audit log, per-server hashed agent tokens with
  rotation, login lockout, CSRF protection, strict CSP (no CDNs, no inline JS)
- One-line installer with checksum verification, port checks, random secrets,
  non-interactive mode; agent installer with systemd setup
- Docker Compose stack (non-root, read-only containers), optional Caddy TLS
  overlay, CI (lint, tests, govulncheck, gitleaks) and release automation

### Special features
- 🛡️ **Security Radar**: SSH/auth brute-force detection from the log stream
  (threshold per sliding window), `auth_attack` notifications — zero AI cost
- 📈 **Anomaly detection**: per-server error-rate baseline (7 days, hourly);
  alerts at mean + 3σ with a configurable floor
- 🔐 **TOTP 2FA**: RFC 6238, dependency-free, confirmed enrollment, sealed secrets
- 📰 **Weekly digest**: 7-day ops summary through any notification channel
- 📊 **Prometheus `/metrics`** endpoint (token-gated, optional)
- Agent: **journald source** (`journalctl -f` subprocess, priority→level mapping),
  **Docker json-file format** unwrapping, **multiline aggregation** for stack traces
