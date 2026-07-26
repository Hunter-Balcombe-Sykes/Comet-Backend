# scripts/dast/ — DAST security scanning

Sends hostile HTTP requests at a running target and inspects the responses —
the *dynamic × code* cell of the assurance suite (sibling to
`scripts/audit/` static review and `scripts/launch-check/` runtime checks).

Full design: `docs/superpowers/plans/2026-07-17-dast-security-implementation.md`
(source of truth) and `docs/superpowers/specs/2026-07-17-dast-security-design.md`
(background/rationale).

## Two lanes

- **active** — ZAP fuzzing an isolated, runner-owned local Supabase stack
  (port-offset, torn down via `trap` on every exit). Two seeded identities +
  an unauth pass drive authenticated scans and a cross-identity IDOR check.
  Manual only — never in cron or CI.
- **edge** — Nuclei (curated tags + custom assert templates) + wcvs
  (cache-deception) + a weekly OWASP ZAP *passive* baseline scan, all
  non-destructive, against `EDGE_TARGET`. Weekly cron; not in CI.

Neither lane runs in `ci.yml` — DAST is slow and needs a running
target/stack. This is a self-contained shell tool: zero `config/partna.php`,
app-code, or Supabase migration changes.

## Usage

```bash
cp scripts/dast/.env.example scripts/dast/.env   # fill in secrets, never commit
scripts/dast/run.sh --only edge                  # non-destructive, safe against dev/prod
scripts/dast/run.sh --only active                # local-only, mutates the runner's own throwaway DB
scripts/dast/run.sh --only edge --update-baseline # after reviewing new findings, accept them
```

## Baselines — triage, don't pre-seed

`baseline/*` starts empty and is populated **only** by reviewed triage
(`--update-baseline`, run by a human after reading the findings) — never
pre-seeded, which would bury real bugs. See Phase 10 of the implementation
plan for the first-run triage process.

## Self-test (acceptance gate)

`tests/dast-selftest.sh` proves the scanner isn't silently broken: plants a
deliberately-vulnerable canary, asserts both lanes flag it AND fail the
build, then asserts a clean target passes and a baselined finding is
suppressed. See Phase 8 of the implementation plan.

## Limitation — local ≠ prod authz fidelity

The active lane's local stack does not reproduce prod's `app_backend`
restricted role + RLS via Supavisor. A green active lane means "no
injection/authz class found against app logic," not "prod RLS proven."
Stays a post-launch human-pentest gap — see `REPORT.md` on each run.
