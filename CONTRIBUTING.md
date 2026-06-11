# Contributing to Logwatch2

Thanks for considering a contribution! This document keeps the process predictable.

## Development setup

```bash
git clone https://github.com/USERNAME/logwatch2.git
cd logwatch2
cp .env.example .env            # fill in dev values, defaults work for local use
docker compose up -d --build    # panel on http://localhost:8080
docker compose exec app php bin/console migrate
docker compose exec app php bin/console create-admin --username admin

# Agent (requires Go >= 1.22)
cd agent
go build ./cmd/logwatch-agent
./logwatch-agent --config config.example.yaml
```

## Project conventions

- **Backend (PHP 8.3):** PSR-12 code style, PSR-4 autoloading under `App\`
  (one class per file), prepared statements only (no string-built SQL),
  services injected via PHP-DI, PHPStan level 5 clean.
- **Agent (Go):** standard `gofmt`, no cgo, keep external dependencies minimal
  (currently only `gopkg.in/yaml.v3`).
- **Files stay under ~500 lines.** Split modules instead of growing them.
- **Validate input at system boundaries** — every API handler validates before use.
- **No secrets in the repo.** `.env` is gitignored; use `.env.example` for new keys.

## Tests & checks

Run before opening a PR (CI runs the same):

```bash
docker compose exec app composer test      # PHPUnit
docker compose exec app composer stan      # PHPStan (level 6)
cd agent && go vet ./... && go test ./...
shellcheck install.sh scripts/*.sh
```

## Pull requests

1. Fork, create a feature branch (`feat/...`, `fix/...`).
2. Keep PRs focused — one logical change per PR.
3. Add or update tests for behavior changes.
4. Update the relevant file in `docs/` when behavior or API changes.
5. Add a line to `CHANGELOG.md` under **Unreleased**.
6. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages
   (`feat:`, `fix:`, `docs:`, `refactor:`, `ci:` …) — release notes are generated from them.

## Reporting bugs / requesting features

Use the issue templates. For security issues, **do not open a public issue** —
see [SECURITY.md](SECURITY.md).

## Versioning

Logwatch2 follows [Semantic Versioning](https://semver.org/). Until 1.0,
minor versions may contain breaking changes (noted in the changelog).
