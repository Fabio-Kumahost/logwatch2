# Privacy & Data Masking

Logs are among the most sensitive data a server produces. Two principles:

1. **Raw logs never leave your infrastructure** — except the masked excerpt
   inside an AI request, and only if AI is enabled.
2. **Masking is a hard gate**, not best-effort: it runs on every AI request,
   including local models (uniform behavior, no "it's local so it's fine" drift).

## Masking pipeline (`Service\Privacy\Masker`)

Ordered regex passes — order matters (longest/most specific first):

| # | Pattern | Replacement |
|---|---|---|
| 1 | `password=…`, `passwd:`, `pwd=` values (key/value & JSON forms) | `[PASSWORD]` |
| 2 | Bearer/Basic auth headers, `api_key=`, `token=`, `secret=` values | `[TOKEN]` |
| 3 | Long opaque secrets: 32+ char hex, 20+ char base64-ish, AWS `AKIA…`, `ghp_…`, `sk-…`, JWTs (`eyJ…`) | `[TOKEN]` |
| 4 | Credentials in URLs `scheme://user:pass@host` | `scheme://[CREDENTIALS]@host` |
| 5 | E-mail addresses | `[EMAIL_n]` |
| 6 | IPv4 / IPv6 | `[IP_n]` (or `10.x.x.x` partial mode, see below) |
| 7 | MAC addresses | `[MAC_n]` |
| 8 | Home paths `/home/username/` | `/home/[USER_n]/` |
| 9 | Custom operator patterns (settings UI, admin-only) | `[CUSTOM_n]` |

**Stable placeholders:** within one AI request, identical values map to the
same numbered placeholder (`[IP_1]` everywhere it appears). The AI can then
reason "connection from `[IP_1]` failed repeatedly" coherently. The
placeholder→value map exists **only in memory during the request** and is
discarded — it is never stored and never sent.

**Partial IP mode** (off by default): keep the first octet (`203.x.x.x`) so the
AI can distinguish internal from external traffic. Operators opt in
consciously via settings.

## What is sent to an AI provider

- Masked representative log line + up to 5 masked context lines
- Service name, log file *basename* (not full path — paths can contain usernames), OS family
- Occurrence count, number of affected servers (numbers only, no hostnames)

**Never sent:** hostnames, server names, full paths, panel URL, user data,
unmasked anything.

## Verification & transparency

- Settings page has a **masking preview**: paste a sample line, see exactly
  what would leave the system.
- Every `ai_analyses` row stores `masked_input_hash` so operators can audit
  that the gate ran (the masked text itself is not stored by default; a debug
  setting can retain it for 24 h while tuning custom patterns).
- Unit tests pin every pattern with real-world fixtures (`MaskerTest.php`);
  new patterns require a fixture proving they mask *and* one proving they don't
  over-mask normal text.

## Retention & GDPR notes (for operators)

- Raw entries auto-purge after `RETENTION_DAYS_LOGS` (default 30); resolved
  groups after `RETENTION_DAYS_RESOLVED` (default 90). Both are settings.
- Deleting a server cascades all its log data immediately.
- Logs may contain personal data (IPs are personal data under GDPR) — the panel
  is a *processor* tool; operators remain responsible for lawful basis and
  retention policy. The defaults are chosen to support data minimization.
- Zero-egress option: run an OpenAI-compatible local model (Ollama) — masking
  still applies, nothing leaves the host at all.
- The audit log stores panel-user actions (not log content) for accountability.
