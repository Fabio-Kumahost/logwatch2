# System Architecture

## Components

```
                        Monitored servers (N)                        Central host
┌──────────────────────────────────────────────┐   ┌─────────────────────────────────────────┐
│  logwatch-agent (Go, static binary, systemd) │   │ docker compose stack                    │
│  ┌─────────┐  ┌─────────┐  ┌──────────────┐  │   │ ┌───────┐  ┌─────────┐  ┌────────────┐ │
│  │ Tailers │─▶│ Batcher │─▶│ Shipper      │──┼──▶│ │ nginx │─▶│ php-fpm │  │ PostgreSQL │ │
│  │ (files) │  │ (queue) │  │ HTTPS+token  │  │   │ │ :443  │  │ (app)   │──│    16      │ │
│  └─────────┘  └─────────┘  │ retry/backoff│  │   │ └───────┘  └─────────┘  └────────────┘ │
│        heartbeat every 60s └──────────────┘  │   │      ┌──────────┐ ▲          ▲          │
└──────────────────────────────────────────────┘   │      │ worker   │─┘──────────┘          │
                                                   │      │ (php cli)│  jobs: AI analysis,   │
            AI provider (external or local)        │      └────┬─────┘  notifications,       │
            OpenAI / Anthropic / Ollama / …  ◀─────┼───────────┘        offline checks,      │
            receives MASKED excerpts only          │                    retention cleanup    │
                                                   └─────────────────────────────────────────┘
```

Four runtime pieces:

1. **Agent** (`agent/`) — one per monitored server. Tails configured log files,
   detects rotation, parses level/service heuristically, batches entries and POSTs
   them to the panel. Stateless except for a small offset file; survives panel
   downtime via exponential-backoff retry and a bounded in-memory + on-disk spool.
2. **Panel app** (`backend/`) — Slim 4 (PHP 8.3) behind nginx/php-fpm. Serves the
   REST API and the server-rendered web UI (Twig + Tailwind + Alpine.js).
   Ingest path: validate token → validate batch → classify level → fingerprint →
   upsert error group → store entry → enqueue follow-up jobs.
3. **Worker** — same PHP image, `php bin/worker.php`. Polls the `jobs` table
   (DB-backed queue, no extra broker needed at this scale) and executes:
   `ai.analyze`, `notify.dispatch`, `servers.offline_check`, `retention.cleanup`.
4. **PostgreSQL 16** — single source of truth. See [database.md](database.md).

## Data flow (happy path)

```
log line → agent tailer → batch → POST /api/v1/ingest/logs (Bearer agent-token)
  → IngestController: auth, validate, classify level (regex rules)
  → fingerprint = sha256(service + source + normalized(message))
  → error_groups upsert (count++, last_seen)         ── new group & level ≥ warning?
  → log_entries insert                                        │
  → job ai.analyze(fingerprint)  ◀────────────────────────────┘
       worker: mask sensitive data → provider request → validate JSON →
       store ai_analyses (cache by fingerprint) → job notify.dispatch
       worker: evaluate notification rules → rate-limit check → Discord/Gotify
```

## Why these technologies

| Decision | Choice | Rationale |
|---|---|---|
| Backend | **PHP 8.3 + Slim 4** | Requested; Slim is small, explicit, PSR-15 middleware fits auth/RBAC cleanly; no framework magic to audit |
| DB | **PostgreSQL 16** | Better JSONB, partitioning and full-text search than MySQL for log workloads; MySQL kept possible via PDO but not the tested default |
| Agent | **Go 1.22** | Single static binary (amd64/arm64), tiny RSS (<20 MB), no runtime deps on monitored hosts, trivial systemd deployment |
| Frontend | **Twig + Tailwind + Alpine.js** | Server-rendered = small attack surface, no SPA build chain for contributors; Alpine covers the interactivity a dashboard needs; auto-refresh via polling `/api/v1/stats/dashboard` |
| Queue | **DB table `jobs`** | One less service; SKIP LOCKED polling is fine for the volumes involved; can swap for Redis later behind the same `Queue` interface |
| AI | **Provider interface** | `openai`, `anthropic`, `openai_compatible` (covers Ollama, LM Studio, vLLM, Groq, …) |
| Deploy | **Docker Compose** | Reproducible one-line install; images published to GHCR by CI |

## Scaling notes (documented limits)

- Ingest is append-mostly; `log_entries` is range-partitioned by day past ~5M rows
  (migration provided, off by default).
- The worker is horizontally scalable (`docker compose up --scale worker=3`) —
  job claiming uses `FOR UPDATE SKIP LOCKED`.
- Agents back-pressure themselves: on HTTP 429/5xx they back off and spool locally,
  so a panel restart never loses log lines.
- For >50 servers or >1k lines/s, put nginx rate limits per token (config included)
  and move PostgreSQL to a dedicated host. Beyond that, this project is the wrong
  tool — use Loki/Elastic and keep Logwatch2 for the AI triage layer (roadmap 1.x).

## Module map (backend)

```
backend/src/
├── Controller/      HTTP handlers (thin; validation + service calls only)
├── Middleware/      AgentAuth, SessionAuth, Rbac, RateLimit, Csrf
├── Service/
│   ├── Ingest/      LevelClassifier, Fingerprinter
│   ├── AI/          AiAnalyzer, ProviderInterface, OpenAIProvider,
│   │                AnthropicProvider, OpenAICompatibleProvider
│   ├── Privacy/     Masker (regex pipeline, stable placeholders)
│   ├── Notify/      Notifier, DiscordChannel, GotifyChannel, RateLimiter
│   └── Queue/       Queue (enqueue/claim/complete)
├── Repository/      PDO repositories (prepared statements only)
└── Support/         Crypto (libsodium), Config, Validator
```
