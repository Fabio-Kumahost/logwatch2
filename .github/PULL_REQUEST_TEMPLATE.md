## What & why

<!-- Short description of the change and its motivation. Link issues: Fixes #123 -->

## Type

- [ ] Bug fix
- [ ] Feature
- [ ] Docs / CI / chore
- [ ] Breaking change (pre-1.0: note it in CHANGELOG.md)

## Checklist

- [ ] Tests added/updated for behavior changes
- [ ] `composer test && composer stan && composer cs` pass (backend changes)
- [ ] `go vet ./... && go test ./...` pass (agent changes)
- [ ] `shellcheck` passes (script changes)
- [ ] Relevant `docs/` file updated
- [ ] `CHANGELOG.md` entry added under **Unreleased**
- [ ] No secrets, tokens or real hostnames in code, tests or fixtures
