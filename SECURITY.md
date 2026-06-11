# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| latest minor (0.x) | ✅ |
| older releases | ❌ — please upgrade |

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, use one of:

1. **GitHub private vulnerability reporting** (preferred):
   *Security* tab → *Report a vulnerability*.
2. E-mail the maintainers (address in the repository profile) with subject
   `[SECURITY] logwatch2`.

Please include: affected version, reproduction steps, impact assessment, and —
if you have one — a suggested fix. You will receive an acknowledgement within
**72 hours** and a status update at least every **7 days**.

We ask for **coordinated disclosure**: give us up to 90 days to ship a fix
before publishing details. Credit is given in the release notes unless you
prefer to stay anonymous.

## Scope notes for self-hosters

Logwatch2 processes log data, which routinely contains sensitive material.
Operators should read [`docs/security.md`](docs/security.md) and
[`docs/privacy.md`](docs/privacy.md). Highlights:

- Run the panel **behind TLS** (reverse proxy or the bundled instructions).
- Agent tokens are stored **hashed**; rotate them via the panel if leaked.
- AI requests send only **masked** log excerpts; use a local model if your
  policy forbids any external transfer.
- The installer generates random secrets and refuses to overwrite existing
  installations; review it before piping to bash, as you should with any script.
