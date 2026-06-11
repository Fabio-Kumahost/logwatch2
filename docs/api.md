# REST API

Base path: `/api/v1`. All responses are JSON:
`{ "data": … }` on success, `{ "error": { "code", "message", "details?" } }` on failure.
Pagination: `?page=1&per_page=50` → `meta: { page, per_page, total }`.

Two auth realms:

| Realm | Used by | Mechanism |
|---|---|---|
| **Agent API** | logwatch-agent | `Authorization: Bearer <agent-token>` (per server, stored hashed) |
| **Panel API** | Web UI / users | Session cookie (HttpOnly, SameSite=Lax) + CSRF token for mutations |

## Agent endpoints

### `POST /api/v1/ingest/logs`
Batch ingest. Body (≤ `INGEST_MAX_BATCH` entries, ≤ `INGEST_MAX_BODY_KB`):

```json
{
  "agent_version": "0.1.0",
  "entries": [
    {
      "ts": "2026-06-11T07:32:01Z",
      "source_file": "/var/log/nginx/error.log",
      "service": "nginx",
      "level": "error",            // optional; panel re-classifies if absent
      "message": "connect() failed (111: Connection refused) while connecting to upstream",
      "raw": "2026/06/11 07:32:01 [error] 123#0: *45 connect() failed ..."
    }
  ]
}
```

Responses: `202 {"data":{"accepted":N,"rejected":M}}` · `401` bad token ·
`413` body too large · `422` schema violation (per-entry errors in `details`) ·
`429` rate limited (`Retry-After` header — the agent honors it).

### `POST /api/v1/agent/heartbeat`
`{"agent_version":"0.1.0","hostname":"web-01","uptime_s":86400,"watched_files":7}`
→ `200 {"data":{"server_status":"online"}}`. Sent every 60 s; missing for
`AGENT_OFFLINE_AFTER` seconds ⇒ server marked offline (worker job) ⇒ notification trigger.

## Panel endpoints (session auth; 🔒 = admin role required)

### Auth
- `POST /auth/login` `{username,password,totp_code?}` — rate limited, lockout after
  N failures; returns `401 totp_required` when 2FA is enrolled and no code was sent
- `POST /auth/logout`
- `GET  /auth/me` → current user, role, `totp_enabled`, CSRF token
- `POST /auth/totp/setup` → secret + `otpauth://` URI (pending until confirmed)
- `POST /auth/totp/confirm` `{code}` — proves the authenticator works, then enables
- `POST /auth/totp/disable` `{code}` — requires a valid current code

### Servers
- `GET    /servers` — list incl. status, last_seen, open error counts
- `POST   /servers` 🔒 `{name, tags?}` → **token returned exactly once**
- `GET    /servers/{uuid}`
- `PATCH  /servers/{uuid}` 🔒 — rename, tags
- `DELETE /servers/{uuid}` 🔒 — cascades log data
- `POST   /servers/{uuid}/token/rotate` 🔒 → new token (old one invalid immediately)

### Logs & errors
- `GET /logs?server=&level=&service=&from=&to=&q=&page=` — raw stream, full-text `q`
- `GET /errors?status=&level=&server=&page=` — error groups (the main triage list)
- `GET /errors/{id}` — group detail: occurrences, affected servers, AI analysis
- `PATCH /errors/{id}` `{status: acknowledged|resolved|ignored}`
- `POST  /errors/{id}/analyze` — force (re-)analysis; `409` if AI disabled,
  `429` if daily AI budget exhausted

### Notifications
- `GET/POST/PATCH/DELETE /notify/channels` 🔒 — Discord/Gotify configs
- `POST /notify/channels/{id}/test` 🔒 — sends a test message
- `GET/POST/PATCH/DELETE /notify/rules` 🔒
- `GET /notify/log` — dispatch history

### Settings & users
- `GET/PUT /settings/ai` 🔒 — provider, model, base_url, key (write-only: the key
  is never returned, only `key_set: true`)
- `POST /settings/mask-preview` 🔒 `{sample}` — returns the masked version of a
  sample line (exactly what an AI provider would receive)
- `GET/POST/PATCH/DELETE /users` 🔒 — manage accounts and roles
- `GET /stats/dashboard` — counters the dashboard polls every 15 s: servers by
  status, errors last 24 h, recent error groups, anomalies, AI budget used

### Operational endpoints (outside `/api/v1`)
- `GET /healthz` — liveness for compose healthchecks and the installer
- `GET /metrics` — Prometheus exposition; enabled by setting `METRICS_TOKEN`,
  scraped with `Authorization: Bearer <token>`. Gauges: `lw2_servers{status}`,
  `lw2_error_groups_open`, `lw2_log_entries_24h`, `lw2_jobs_pending`,
  `lw2_ai_requests_today`

## Error codes

`unauthorized` 401 · `forbidden` 403 (role) · `not_found` 404 ·
`validation_failed` 422 · `rate_limited` 429 · `payload_too_large` 413 ·
`conflict` 409 · `server_error` 500 (no internals leaked; details go to the app log).

## Versioning

Breaking API changes bump the path version (`/api/v2`). Agents send
`agent_version`; the panel answers `426 Upgrade Required` if an agent falls
below the minimum supported version (constant in config, used after 1.0).
