# Security Concept

## Threat model (what we defend against)

| Threat | Mitigation |
|---|---|
| Stolen agent token | Per-server tokens, stored as SHA-256 hash, one-click rotation, scope limited to ingest+heartbeat for *that* server only |
| Panel credential stuffing | Argon2id hashes, login rate limit + temporary lockout, audit log of logins |
| DB dump leak | No plaintext tokens; AI keys & webhook URLs sealed with libsodium (`APP_KEY` lives only in `.env`); passwords Argon2id |
| Malicious/compromised agent flooding | Per-token rate limit (nginx + app), max batch/body size, level classification server-side (agent input is untrusted) |
| Log injection into UI (XSS via log content) | Twig autoescaping everywhere; raw logs rendered as text nodes, never HTML; strict CSP (`default-src 'self'`) |
| SQL injection | PDO prepared statements only; repository layer is the single DB access path; CI greps for string-interpolated SQL |
| Sensitive data sent to AI vendor | Masking pipeline before every AI request (see [privacy.md](privacy.md)); local-model option |
| CSRF on panel forms | SameSite=Lax session cookie + per-session CSRF token on all mutating routes |
| Secrets in repo | `.env` gitignored; `.env.example` has placeholders; CI secret scanning (gitleaks) |
| Installer abuse | No blind overwrite (refuses existing install dir without `--force`), root warning, port-in-use check, secrets from `openssl rand`, every failure path prints a clear message and exits non-zero |

## Authentication & authorization

**Users:** session-based. Cookies `HttpOnly; Secure (when HTTPS); SameSite=Lax`.
Session ids regenerate on login. Idle timeout `SESSION_LIFETIME`.
Optional **TOTP 2FA** (RFC 6238, dependency-free implementation): enrollment
requires a confirmed code before activation (no lockout-by-typo), disabling
requires a valid current code (stolen-session protection), secrets stored
sealed with `APP_KEY`. Login responds `totp_required` without leaking whether
the password was correct beyond the first factor.

**Roles:** `admin` (everything) vs `user` (read logs/errors, change error status;
no settings, users, channels, server CRUD, token rotation). Enforced by the
`Rbac` middleware per route group — deny by default, allow-list per role.

**Agents:** 256-bit random token (`base64url`, prefix `lw2_` for secret-scanner
friendliness), generated server-side, displayed once. Verification:
constant-time compare of `sha256(presented)` against `token_hash`.

## Transport security

The compose stack ships nginx on plain HTTP **bound to localhost by default**;
production guidance (in [installation.md](installation.md)) is one of:

1. Reverse proxy you already run (Caddy/Traefik/nginx) terminating TLS — recommended.
2. The provided `docker-compose.tls.yml` overlay that adds Caddy with automatic
   Let's Encrypt for a public hostname.

Agents **refuse plain `http://` panel URLs** unless `allow_insecure: true` is set
explicitly in the agent config (intended for lab use only). Certificate pinning
via `tls_ca_file` is supported for self-signed setups.

## Application hardening

- Security headers: `Content-Security-Policy: default-src 'self'`,
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`.
- Input validation at every boundary (`Support\Validator`): types, lengths,
  enum membership, timestamp sanity (rejects entries >24h in the future).
- Errors return generic messages; stack traces only in `APP_ENV=development`.
- Audit log for every privileged action.
- Dependency hygiene: Dependabot + `composer audit` + `govulncheck` in CI.

## Container hardening

- App/worker containers run as non-root (`www-data` / dedicated uid), read-only
  root filesystem with explicit tmpfs for `/tmp` and `var/`.
- DB is on the internal compose network only — no published port by default.
- Images pinned by digest in releases; `latest` only for development.
- No Docker socket mounted anywhere.

## Agent (host) hardening

The shipped systemd unit applies least privilege:

```
User=logwatch  (created by installer; member of adm/systemd-journal for log read access)
ProtectSystem=strict · ProtectHome=read-only · NoNewPrivileges=yes
PrivateTmp=yes · ReadOnlyPaths=/var/log · ReadWritePaths=/var/lib/logwatch-agent
CapabilityBoundingSet= · RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
MemoryMax=128M · CPUQuota=20%
```

The agent never executes log content, never follows symlinks outside configured
globs, and rate-limits itself when the panel responds 429.
