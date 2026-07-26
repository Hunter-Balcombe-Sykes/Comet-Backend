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

## One-time setup

```bash
cp scripts/dast/.env.example scripts/dast/.env   # fill in secrets, never commit
```

## Running each lane

**Edge lane** — non-destructive, safe to run against dev or prod anytime. Also runs automatically every Sunday via `.github/workflows/dast-edge.yml` once `DAST_EDGE_TARGET`/`DAST_EDGE_SITEPAGE_TARGET`/`DAST_EDGE_RATE_LIMIT` are set as GitHub repo secrets.

```bash
scripts/dast/run.sh --only edge
```

**Active lane** — manual only. Needs Docker running, mutates the runner's own throwaway local Supabase stack, takes several minutes (isolated bring-up + a curated ZAP scan against ~250 routes across two identities plus an unauth pass). Run this before a release, or after any change to auth/authorization/policy code — that's exactly the class of bug the cross-identity IDOR pass is built to catch.

```bash
scripts/dast/run.sh --only active
```

**After reviewing a run's findings**, accept the ones you're keeping into the triaged baseline (never automatic — always a human decision after reading `new-findings.txt`/`REPORT.md`):

```bash
scripts/dast/run.sh --only edge --update-baseline
scripts/dast/run.sh --only active --update-baseline
```

**Self-test (the tool's own acceptance gate)** — run this after changing anything under `scripts/dast/` itself, not as a routine security check. It proves the scanner isn't silently broken: plants known-vulnerable canaries in both lanes, asserts they're flagged AND fail the build, then asserts a clean target passes and a baselined finding is suppressed.

```bash
scripts/dast/tests/dast-selftest.sh
```

## What updates automatically vs what you maintain by hand

The **route surface is fully automatic** — `seed-endpoints.sh` re-derives the OpenAPI seed from `php artisan route:list --json` on every active-lane run, so new/removed/changed API endpoints are picked up with zero action needed. Same for the two seeded identities (freshly created each run) and the baseline diff itself (a finding at a new key just shows as "new"; one that stops occurring just stops appearing — no config change needed either way).

**Four things don't auto-update and need a human to keep them current:**

1. **The active lane's exclusion list** (`active/zap-context.yaml`'s `excludePaths`) — a hardcoded set of route patterns whose handlers reach past the local box (vendor API calls, real email/notification sends, Cloudflare KV writes). If you add a new route that does one of those things, it won't be auto-excluded — the active lane could trigger a real external side effect from a "local" scan until someone adds it here. Grep for `SyncSubdomainToKvJob::dispatch`, `Mail::`/`Notification::send`, and new entries under `routes/api/platforms.php` when reviewing this.
2. **The 5 custom Nuclei templates** (`edge/templates/*.yaml`) — each asserts against a specific hardcoded path (e.g. `/api/customers/{id}`, `/api/public/unsubscribe/...`). If those specific routes get renamed or restructured, the template should be reviewed so it's still testing something real.
3. **`active/seed-identities.php`** — hardcodes the exact fields needed to build a full identity (User → Site → SiteMedia → Customer → Enquiry). A schema change (new required column, renamed relation) will break this script until it's updated to match.
4. **The curated active-scan rule set** (5 rule IDs in `zap-active.sh`: SQLi, XSS reflected/persistent, path traversal, command injection) — static by design (never "run everything"); only touch it if you deliberately want to broaden or narrow what vulnerability classes get tested.

## Baselines — triage, don't pre-seed

`baseline/*` starts empty and is populated **only** by reviewed triage
(`--update-baseline`, run by a human after reading the findings) — never
pre-seeded, which would bury real bugs. See Phase 10 of the implementation
plan for the first-run triage process.

## Limitation — local ≠ prod authz fidelity

The active lane's local stack does not reproduce prod's `app_backend`
restricted role + RLS via Supavisor. A green active lane means "no
injection/authz class found against app logic," not "prod RLS proven."
Stays a post-launch human-pentest gap — see `REPORT.md` on each run.
