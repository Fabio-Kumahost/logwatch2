# Database Design (PostgreSQL 16)

The authoritative DDL lives in [`backend/migrations/001_initial.sql`](../backend/migrations/001_initial.sql).
This document explains the model.

## Entity overview

```
users ─────────┐                       settings (key/value, encrypted secrets)
               │ audit_log             jobs (DB-backed queue)
servers ───┬───┴────────────┐
           │                │
           ▼                ▼
     log_entries ──▶ error_groups ──▶ ai_analyses (cache, 1:1 by fingerprint)
                          │
                          ▼
   notification_channels ─┴─ notification_rules ──▶ notifications_log
```

## Tables

### `users`
Panel accounts. `role` is an enum `admin | user` (extensible to a join table
later without breaking the API, which already returns `role` as a string).
Passwords: **Argon2id** via `password_hash()`. `is_active` allows disabling
without deletion. Login attempts are tracked in `login_attempts` for lockout.

### `servers`
One row per monitored machine. The agent token is generated once, shown once,
and stored only as `token_hash = sha256(token)` — a DB leak does not leak
usable tokens. `status` (`online|offline|warning|critical`) is derived:
heartbeat recency decides online/offline; the highest open error-group severity
in the last 24h decides warning/critical. `last_seen_at` is updated by both
heartbeats and ingests.

### `log_entries`
The raw stream. Columns: `ts` (from the log line when parseable, else receive
time), `received_at`, `source_file`, `service`, `level` (enum `debug … critical`),
`message` (normalized single line), `raw` (original), `fingerprint`,
`error_group_id` (nullable — only warning+ entries are grouped), `meta` JSONB.

- **Indexes:** `(server_id, ts DESC)`, `(level, ts DESC)`, `(error_group_id)`,
  GIN on `to_tsvector('simple', message)` for the search box.
- **Retention:** worker job deletes rows older than `RETENTION_DAYS_LOGS`.
- **Scale:** optional migration converts to daily range partitions; the cleanup
  job then drops partitions instead of DELETEs.

### `error_groups`
Deduplicated errors. `fingerprint` is `sha256(service || source_file_class ||
normalized_message)` where normalization strips timestamps, numbers, IPs, UUIDs,
hex strings and quoted values — so `connection to 10.0.0.5 failed` and
`connection to 10.0.0.9 failed` share a group. Tracks `first_seen`, `last_seen`,
`occurrence_count`, `server_ids` (int[] — same root cause across machines is one
group), `level`, `status` (`open|acknowledged|resolved|ignored`), `recurring`
flag (set when count within window exceeds threshold).

### `ai_analyses`
**The AI cache.** Unique on `fingerprint`. Stores provider, model, and the
structured result: `summary`, `explanation`, `probable_causes` JSONB,
`impact`, `severity` (1–5), `urgency`, `solution_steps` JSONB, `commands` JSONB,
`related_checks` JSONB, plus `tokens_used` and `created_at`. A group whose
fingerprint already has a row is **never re-sent to the AI** unless a user
clicks *Re-analyze* or the analysis is older than the configurable
`ai.reanalyze_after_days` and the error recurs.

### `notification_channels` / `notification_rules` / `notifications_log`
Channels hold type (`discord|gotify`) and config JSONB — webhook URLs and Gotify
tokens are encrypted at rest (libsodium secretbox with `APP_KEY`). Rules bind a
trigger (`critical_error | server_offline | new_error | recurring_error`) to a
channel with filters (server ids, services, min level) and `cooldown_seconds`.
`notifications_log` records every dispatch and is the data source for rate
limiting (cooldown per rule+group, hourly cap per channel) and the UI history.

### `settings`
Key/value JSONB with `is_encrypted` flag. AI provider keys entered in the UI are
sealed with `APP_KEY` before storage; values from environment variables override
DB values at runtime (12-factor friendly).

### `jobs`
Minimal queue: `id, type, payload JSONB, run_at, attempts, locked_at, locked_by,
failed_at, error`. Workers claim with
`SELECT … WHERE run_at <= now() AND locked_at IS NULL ORDER BY id
FOR UPDATE SKIP LOCKED LIMIT 1`. Failed jobs retry with exponential backoff up
to 5 attempts, then park with `failed_at` set (visible in the admin UI).

### `audit_log`
Who did what: logins, settings changes, token rotations, user management,
status changes on error groups. Append-only.

## Conventions

- All timestamps `timestamptz`, stored UTC, rendered in the user's locale.
- All FKs `ON DELETE CASCADE` from `servers` (deleting a server removes its data)
  except `audit_log` (kept, server referenced by name snapshot).
- IDs: `bigint generated always as identity`; no UUID PKs (cheap indexes), but
  `servers.public_id` UUID exists for URLs so internal IDs aren't enumerable.
