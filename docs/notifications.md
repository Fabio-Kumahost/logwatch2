# Notifications (Discord & Gotify)

## Model

```
trigger event ──▶ matching notification_rules ──▶ rate limiter ──▶ channel adapter
(critical_error,    (filters: servers, services,    (cooldowns,       (Discord webhook /
 server_offline,     min level; per-rule)            caps, dedupe)     Gotify push)
 new_error,
 recurring_error)
```

Everything runs through the worker (`notify.dispatch` jobs) — a slow webhook
never blocks ingest.

## Triggers

| Trigger | Fired when | Default payload |
|---|---|---|
| `critical_error` | entry classified critical, or AI severity ≥ 4 | AI summary, server, service, count, link |
| `server_offline` | no heartbeat for `AGENT_OFFLINE_AFTER` s (worker check every 60 s) | server, last seen |
| `server_recovered` | heartbeats resume after an offline period | server, downtime |
| `new_error` | first occurrence of a fingerprint ever | AI summary (or first line if analysis pending), server, service |
| `recurring_error` | group crosses recurrence threshold (`RECURRING_PER_HOUR`/`_DAY`) | AI summary, count in window, affected servers |
| `auth_attack` | 🛡️ Security Radar: ≥ `RADAR_AUTH_THRESHOLD` failed auth attempts within `RADAR_WINDOW_MINUTES` | server, failure count, distinct sources, hardening hints |
| `anomaly` | 📈 error rate exceeds the server's 7-day baseline (mean + 3σ, floor `ANOMALY_MIN_ERRORS`) | server, current rate vs baseline |
| `digest` | 📰 weekly, via the `digest.weekly` job | 7-day stats: volume, new/open errors, anomalies, noisiest services |

Each notification includes the **one-sentence AI summary** when available;
if analysis is still queued, the message says so and a follow-up edit is *not*
sent (keeps channels quiet) — the panel link always shows the latest state.

## Rate limiting (anti-spam)

Three independent layers, all backed by `notifications_log`:

1. **Per rule+group cooldown** — default 900 s: the same error group through the
   same rule fires at most once per cooldown window; suppressed events increment
   a counter that appears in the *next* message ("+37 occurrences since last alert").
2. **Per channel hourly cap** — default 20/h; when hit, a single "muted: cap
   reached, see panel" message is sent once.
3. **Dedupe** — identical (rule, group, template-hash) within 60 s collapse to one.

## Discord adapter

POST to the webhook URL with an embed: title = severity emoji + summary, color
by severity (grey/blue/yellow/orange/red), fields for server, service, level,
count, first/last seen, and a link button to the error detail page. Webhook
URLs are encrypted at rest and masked in the UI (`…/T0kEn` → `…/•••`).
Discord 429 responses are honored via `retry_after`.

## Gotify adapter

POST `{title, message (markdown), priority}` to `{server_url}/message` with
`X-Gotify-Key: <app token>`. Severity→priority mapping: 1→2, 2→4, 3→6, 4→8,
5→10. Markdown body mirrors the Discord embed content.

## Configuration UX

- Channels page (admin): add Discord/Gotify, **Test** button sends a sample.
- Rules page: trigger + channel + filters (servers multi-select, services,
  min level) + cooldown. Multiple rules per channel allowed.
- History page: every sent/suppressed notification with reason — answers
  "why did/didn't I get pinged?".

## Extensibility

`ChannelInterface { send(Notification $n): DeliveryResult; }` — adding Slack,
Telegram, ntfy or e-mail is one adapter class + a config form definition.
Adapters are registered in `config/channels.php`; PRs welcome (see roadmap).
