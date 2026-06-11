# AI Analysis Concept

## Goals

For every *unique* error: explain it in plain language, name probable causes,
assess impact and urgency, give concrete fix steps with example Linux commands —
while keeping cost bounded and sensitive data out of provider hands.

## Pipeline

```
new error_group (level ≥ warning)
   └─ job ai.analyze(fingerprint)
        1. cache check: ai_analyses[fingerprint] exists & fresh? → done (no API call)
        2. budget check: AI_DAILY_BUDGET_REQUESTS not exhausted? else park job
        3. context build: representative raw line + up to 5 surrounding lines,
           service, source file, OS info, occurrence count, affected server count
        4. MASK everything (privacy.md) — hard gate, runs even for local models
        5. provider request (JSON-schema-constrained prompt, temperature 0.2)
        6. validate response against schema; one retry with "fix your JSON" on failure
        7. store in ai_analyses; update group severity if AI raised it
        8. enqueue notify.dispatch if rules match
```

## Provider abstraction

```php
interface ProviderInterface {
    /** @return AnalysisResult  @throws ProviderException */
    public function analyze(MaskedContext $ctx): AnalysisResult;
}
```

| Provider | Config | Notes |
|---|---|---|
| `openai` | api_key, model (default `gpt-4o-mini`) | Chat Completions, `response_format: json_object` |
| `anthropic` | api_key, model (default `claude-haiku-4-5`) | Messages API, prefilled `{` + stop sequence for clean JSON |
| `openai_compatible` | base_url, api_key?, model | Ollama, vLLM, LM Studio, Groq, Mistral, … — anything speaking the OpenAI wire format |

Keys are entered in the panel (write-only field) or via env; stored sealed with
`APP_KEY`. Switching providers does not invalidate the cache (results are
provider-tagged but reused regardless).

## Output schema (validated before storage)

```json
{
  "summary":        "one sentence, used in notifications",
  "explanation":    "what happened, in plain language",
  "probable_causes":["ranked, most likely first"],
  "impact":         "what this can break / who is affected",
  "severity":       3,                  // 1 info … 5 critical
  "urgency":        "low|medium|high|immediate",
  "solution_steps": ["ordered, concrete steps"],
  "commands":       [{"description":"…","command":"journalctl -u nginx -n 50"}],
  "related_checks": ["other logs/services worth checking"]
}
```

The system prompt pins the role ("experienced Linux sysadmin"), demands JSON
only, forbids destructive commands (`rm -rf`, `dd`, `mkfs`, fork bombs are
filtered again server-side before display — defense in depth), and instructs
the model to mark placeholders like `[IP_1]` as "masked value" rather than
guessing. Full template: `backend/src/Service/AI/prompt.txt`.

## Caching & cost control

- **Cache key = fingerprint** (see below). Hit ⇒ zero API calls. This is the
  main cost lever: a crash loop emitting the same error 10 000× costs one request.
- **Daily budget**: `AI_DAILY_BUDGET_REQUESTS` hard cap; when exhausted, jobs
  park until midnight UTC and the dashboard shows a banner.
- **Re-analysis** only on user request or when `reanalyze_after_days` (default 30)
  passed *and* the error re-occurred.
- `max_tokens` capped (default 1024); token usage stored per analysis and
  summed on the settings page.

## Fingerprinting & grouping

Normalization before hashing strips volatility:

```
2026-06-11T07:32:01 → <TS>      10.4.2.17 → <IP>        0x7f3a… → <HEX>
a1b2c3-uuid…        → <UUID>    /proc/4711 → /proc/<N>   "quoted" → <STR>
digits runs         → <N>
fingerprint = sha256(service + "\0" + source_class + "\0" + normalized)
```

`source_class` is the path with rotation suffixes removed (`error.log.1` →
`error.log`). Grouping is **cross-server**: the same root cause on 12 machines
is one group with 12 server badges — and one AI request.

## Pattern & recurrence detection

A worker job (every 5 min) flags groups as `recurring` when occurrences exceed
thresholds (default: ≥10 in 1 h or ≥50 in 24 h) — this feeds the
`recurring_error` notification trigger and a "Patterns" dashboard widget
(top recurring groups, new groups today, error-rate trend per server).
Severity disagreement (regex says *warning*, AI says 5) surfaces as
"AI raised severity" so harmless-looking noise gets attention — and vice versa:
known-benign patterns can be marked `ignored`, which mutes grouping *and* AI cost.
