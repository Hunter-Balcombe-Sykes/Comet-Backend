# Cutover Phase 1 — Production database re-baseline (paste-in execute prompt)

Operationalises Phase 1 of `docs/deploy/production-cutover.md` — wipe the stale prod Supabase schema
and re-baseline it clean. This is **the one irreversible step** in the whole cutover: once it runs,
there is no "undo" — everything after it (Phase 2 env vars, Phase 3 go-live) is copy-config by
comparison.

**How to use:** open a fresh Claude Code session in this repo on **model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

## GATE — do not start until every box is true

- [ ] The collapse branch is **merged to `development`** and `supabase/migrations/` contains exactly
      one file, `20260726000000_baseline_pilot.sql` (the other 228+ incrementals live in
      `supabase/migrations-archive/`). Confirm with `git log --oneline -1 -- supabase/migrations/` on
      `development` and `ls supabase/migrations/`.
- [ ] The product is **pilot-ready** and **all P0/P1 audit findings are resolved** — this DB re-baseline
      ships the entire dev schema at once; there is no staged rollback if a P0 surfaces post-cutover.
- [ ] **Josh has the prod DB password ready** for the `ALTER ROLE app_backend WITH LOGIN PASSWORD` step
      (Step 6 below) — pick/confirm the value before starting so the session doesn't stall mid-run
      waiting on a secret.
- [ ] **IF the seed sub-step (Step 10) will run in this same session:** Josh and Tobias have already
      signed up on **prod** (not dev) with their real addresses and enrolled TOTP — this needs the prod
      Auth config live first (Send Email Hook + TOTP enabled), so it may have happened days earlier.
      **If not yet done, that's fine** — defer Step 10 to its own later session and say so in the report.

**STOP and do not proceed if any box above is unchecked.** Report which one and wait for Josh.

---

```
=== PROMPT START ===

Execute Phase 1 of docs/deploy/production-cutover.md — the production database re-baseline. Read
docs/deploy/production-cutover.md Phase 1 in full first, plus docs/deploy/prod-cutover-change-checklist.md
§A ("Finish the prod DB") and §B ("Supabase dashboard — Auth hooks + config") — the checklist is the
tick-list distillation of the same steps and has a few corrections/more-precise detail than the Phase-1
prose (flagged below where it matters). Do not invent steps beyond what these two files describe.

## Cutover context (read first)

- **Prod Supabase ref: `edplucmvkcnokyygxqsb`. Dev ref: `glncumufgaqcmqhzwrxm` — the OPPOSITE of prod.**
  These two refs get transposed constantly; before every command that names a ref, say out loud which
  project it targets and confirm it matches the step's intent.
- This phase **wipes and re-baselines the prod database**. It is irreversible — there is no rollback
  once the old schema is dropped and the new baseline applied. Pre-beta = no customer data, so prod
  starts genuinely clean; that is exactly why this is the one moment cutover allows itself to be
  destructive.
- The prod project is already `ACTIVE_HEALTHY` (verified 2026-07-21) — it just holds the stale
  pre-standalone schema (~`20260512145025`). No Supabase-side restore/unpause is needed; only a wipe +
  re-baseline.
- **Josh drives every irreversible/dashboard/SQL-editor action.** Your job: prepare the exact command or
  SQL for each prod-mutating step, run whatever read-only verification is possible before and after, and
  get Josh's explicit confirmation before he executes. You do not run prod-mutating statements yourself
  even if credentials are available in this session — surface the exact text and wait for a yes.

## Steps

Work through these in order. For every step marked prod-mutating: **prepare the exact
command/SQL → run read-only verification → CONFIRM with Josh → Josh executes → verify the result.**
Do not batch confirmations — each mutating step gets its own explicit go-ahead.

**1. Confirm no restore is needed.**
Read-only: check the `edplucmvkcnokyygxqsb` project status (Supabase dashboard or MCP `get_project`).
Expect `ACTIVE_HEALTHY` (verified 2026-07-21 — re-verify now, it may have drifted). If it is paused,
STOP and tell Josh; unpausing is a dashboard action he takes before anything else here. If healthy,
this step is done — no action needed.

**2. Optional archive dump — re-verify the row census first.**
The 2026-07-22 census found the old prod schemas hold **0 rows in every PII-shaped table**
(`core.professionals`, `core.customers`, `core.waitlist_signups`, `core.gdpr_requests`,
`site.enquiries`, every `brand.*`/`commerce.*`/`analytics.*`, `notifications.*`) — the only non-zero
tables were `billing.plans` = 5 and `site.themes` = 3, both reference/seed rows the wipe drops anyway.
Re-run the same per-schema `count(*)` census against `edplucmvkcnokyygxqsb` now (read-only) and report
the numbers. If it's still all-zero-or-seed-only, the archive dump is optional insurance, not a
blocker — ask Josh if he still wants one (`pg_dump` schema+data of the non-managed schemas to the R2
backup bucket) before continuing. If the census turns up anything PII-shaped and non-zero, treat the
dump as mandatory and STOP until it's done.

**3. Purge stale Supabase Auth users — re-verify the count first.**
The runbook found `SELECT count(*) FROM auth.users` = 0 as of 2026-07-22. Re-run that exact query
(read-only) against prod now. If still 0, this step is a no-op — say so and move on; nothing to purge,
nothing to confirm with Josh. If non-zero, STOP and show Josh the count and a sample before deleting
anything (Dashboard or admin API) — do not delete without his explicit per-batch go-ahead.

**4. Wipe to a clean slate — first prod-mutating, irreversible step.**
Prepare this exact block (verbatim from `production-cutover.md`), to be run as the `postgres` admin
connection, **NOT** `app_backend`:

```sql
-- billing/brand/commerce are the PRE-STANDALONE stack (Shopify/Stripe, removed from the app).
-- They still exist on prod — verified live 2026-07-26: billing 18 / brand 33 / commerce 63 objects
-- — and dev does NOT have them. Omitting them leaves 114 orphan objects that the parity
-- fingerprint CANNOT see (it compares only the 7 app schemas), so prod would "match" dev while
-- carrying a dead schema set. Drop them here or they survive the wipe.
DROP SCHEMA IF EXISTS core, site, notifications, analytics, audit, moderation,
                      billing, brand, commerce CASCADE;
-- public: drop AND recreate — never leave it dropped (default grants + some extension
-- objects live here; check `\dx` / objects in public first and recreate what the platform needs).
DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public;
GRANT USAGE ON SCHEMA public TO postgres, anon, authenticated, service_role;
-- Stale ledger rows do NOT go away with the schemas:
TRUNCATE supabase_migrations.schema_migrations;
```

Before presenting this for confirmation, read-only check `\dx` and the object list in `public` on prod
so Josh knows what a drop-and-recreate of `public` will lose (extensions, any leftover objects) —
report it, don't assume it's empty. Get Josh's explicit confirmation on this exact block, then he runs
it. After: verify read-only — `SELECT count(*) FROM supabase_migrations.schema_migrations;` → expect 0,
and confirm all NINE dropped schemas are gone (the six app schemas **plus** `billing`/`brand`/`commerce`):

```sql
SELECT nspname FROM pg_namespace
WHERE nspname IN ('core','site','notifications','analytics','audit','moderation',
                  'billing','brand','commerce');   -- expect ZERO rows
```

**5. Apply the collapsed baseline via `psql` — NOT `db push`, NOT `db reset`, NOT `--single-transaction`.**
This is the sanctioned prod mechanism (`CLAUDE.md` → "Push to Supabase / Fresh prod DB";
`supabase/migrations/CONVENTIONS.md` §1) — the simple-protocol `psql -f`, in filename order (here,
one file). Dry-review first: read `supabase/migrations/20260726000000_baseline_pilot.sql` in full
(5,610 lines), or apply it to a scratch DB via `scripts/db/fresh-reset.sh` and eyeball the result,
before Josh runs it against prod. The command:

```bash
psql "$PROD_DB_URL" -f supabase/migrations/20260726000000_baseline_pilot.sql
```

Then record the applied version in the ledger, using the same shape `scripts/db/fresh-reset.sh` uses
(so a future incremental `db push` sees it as already applied), version = the baseline's 14-digit
filename prefix:

```sql
INSERT INTO supabase_migrations.schema_migrations (version, name)
VALUES ('20260726000000', 'baseline_pilot')
ON CONFLICT (version) DO NOTHING;
```

Confirm both statements with Josh before he runs them (the `psql -f` apply and the ledger INSERT are
two separate confirmations — the apply is the load-bearing one). After: verify read-only — spot-check
a known baseline table exists (e.g. `core.users`, `site.sites`), and
`SELECT * FROM supabase_migrations.schema_migrations;` shows exactly one row, `20260726000000`.

**6. Bootstrap the login role.**
The baseline creates `app_backend` as `NOLOGIN` (fail-closed) — the app cannot connect until this
runs. Prepare, confirm, Josh runs in the SQL editor:

```sql
ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret>';
```

Then verify read-only:

```sql
SELECT rolcanlogin, rolbypassrls FROM pg_roles WHERE rolname = 'app_backend';
```

Required: `t / t`. Explain to Josh why both matter: `rolcanlogin` is the obvious one (the app
literally cannot open a connection without it); `rolbypassrls` is the load-bearing one that's easy to
forget — several FORCE-RLS tables (e.g. `core.partna_staff`) have no `app_backend`-facing policy at
all, so without BYPASSRLS the app is default-denied at runtime on those tables even though the
connection itself succeeds. A `db dump`/baseline can never emit this — it's cluster-level role state
the baseline's stitched bootstrap sets explicitly; if the assert comes back `t / f` or `f / t`, STOP,
do not paper over it with a new RLS policy, and report it as a baseline-stitch defect.

**7. Verify grants.**
Diff the `app_backend` grant matrix against dev. Note: the original Task-8 query text
(`role_table_grants` + `pg_default_acl` reads) lived in the now-deleted collapse implementation plan,
so reconstruct the equivalent read-only checks against `information_schema.role_table_grants` (filtered
to `grantee = 'app_backend'`) and `pg_default_acl`, run against **both** dev and prod, and diff the
rows. The specific expectations to check for (per `prod-cutover-change-checklist.md` §A — this is more
precise than Phase-1's shorthand "audit = SELECT/INSERT only"):
  - `audit` schema: SELECT/INSERT on its tables, **plus** UPDATE on `audit.data_export_audit` (export
    lifecycle + GDPR keep-row retention), **plus** EXECUTE on the 3 SECURITY DEFINER prune functions
    (`audit.prune_handle_change_log`, `audit.prune_user_deletion_audit`, `audit.prune_data_export_audit`)
    — without these the scheduled `audit:prune-pii-snapshots` job and related prune/cleanup jobs are
    permission-denied on prod.
  - `moderation` schema: SELECT/INSERT on `decisions`; SELECT/INSERT/UPDATE on `action_log`.
  - All functions carry a pinned `search_path` (spot-check a few via `pg_proc.proconfig`).
Report the diff. An empty/expected diff means proceed; any unexpected delta is a STOP (see below).

**8. Register both Supabase Auth hooks on the prod project.**
A fresh/re-baselined prod Supabase project has **no hooks configured** — without them, auth emails
silently fall back to Supabase's built-in sender (`*.supabase.co`, wrong branding, cold reputation →
spam) and the MFA path has no verification hook. This is **the highest-risk email trap of the whole
cutover** because the funnel keeps "working" in tests while OTPs quietly land in spam. Prepare both for
Josh to register in the prod Supabase Dashboard → Authentication → Hooks, confirm the secrets are
already set (matching) in the prod Laravel env before he saves:
  - **Send Email Hook** → `https://api.partna.au/api/internal/email-hooks/supabase`, secret = prod
    `SUPABASE_EMAIL_HOOK_SECRET` (format `v1,whsec_<base64>`).
  - **MFA Verification Hook** → `https://api.partna.au/api/webhooks/supabase/auth/mfa-verification`,
    secret = prod `SUPABASE_AUTH_HOOK_SECRET`.
The secret on each side (Supabase Dashboard ↔ prod Laravel env) **must match exactly**, or the
signature middleware 401s/503s and the path fails closed. Real-send verification happens in Phase 4 —
this step is registration + secret-match confirmation only.

**9. Auth project-config parity.**
This is a transcription job against the full checklist already written out in
`docs/deploy/production-cutover.md` Phase 1 → "Auth project-config parity" — don't re-derive it, walk
that list value-by-value against the prod project (`edplucmvkcnokyygxqsb` → Authentication) and report
each as set/not-set. The two that block the most if missed: **Site URL** = `https://app.partna.au`;
**redirect URL allowlist = TIGHT, `https://app.partna.au/auth/callback` only** (do NOT copy dev's
`localhost:3000` or any `*.vercel.app` preview entries — prod must be *narrower* than dev here, an
open-redirect surface otherwise). Also confirm: **Email OTP length = 6 (MUST)**, **TOTP/MFA Enabled
(MUST** — without it every staff endpoint 401s `mfa_required`, permanently), **SMS/Phone MFA
Disabled**.

**10. Seed the bootstrap rows — the LAST step of Phase 1.**
Do not duplicate seed detail here — delegate entirely to
`docs/deploy/PROMPT-execute-prod-seed-bootstrap.md`. Its **Tasks 0–4 are DB-only** (precondition
checks, staff row insert, feature-availability row insert, negative-assertion checks) and belong in
this Phase-1 session; its **Task 5 end-to-end verify calls `api.partna.au`**, which doesn't resolve to
prod until Phase 3 go-live, so it always defers to Phase 4 regardless of when Tasks 0–4 run. Before
running Tasks 0–4: confirm the GATE condition above — Josh and Tobias must already have signed up **on
prod** and enrolled TOTP (their prod `auth.users` UUIDs are the only parameters of that prompt's
Task 2). If that hasn't happened yet, stop here, report Steps 1–9 complete and Step 10 deferred, and
do not attempt the seed prompt in this session.

## PROD-SAFETY RULES (non-negotiable)

- Prod Supabase ref is `edplucmvkcnokyygxqsb` — the OPPOSITE of dev `glncumufgaqcmqhzwrxm`. Say which
  ref every command targets before running it.
- **Josh drives every irreversible/dashboard/SQL-editor action.** You prepare the exact command/SQL,
  run read-only verification, and get explicit confirmation before each prod-mutating step. Never
  execute a prod-mutating statement autonomously, even mid-flow, even if it seems obviously next.
- **Never** `supabase db push` / `db reset` / any Laravel migration against prod. The baseline apply is
  `psql -f`, simple protocol, filename order — nothing else.
- The wipe (Step 4) runs as the `postgres` admin connection, **not** `app_backend`.
- Git stays read-only: this is a documentation/verification session, not a code change. **Never**
  `git stash` / `git checkout <file>` / `git restore` / `git reset` (shared stash across live
  worktrees) — forbid `git stash` explicitly if you spawn any subagent.
- Dry-review the baseline file (or run it through `fresh-reset.sh` on a scratch DB) before it ever
  touches prod.

## Stop and ask Josh if

- The row census (Step 2) shows real, non-seed PII — do the archive dump before continuing.
- `auth.users` (Step 3) comes back non-zero — do not delete rows without his per-batch confirmation.
- The grant-matrix diff (Step 7) against dev is non-empty in a way not already accounted for above.
- `postgres` has lost `BYPASSRLS`, or the `app_backend` assert (Step 6) doesn't come back `t / t` —
  this blocks the Step 10 staff seed entirely (chicken-and-egg: the only INSERT policy on
  `core.partna_staff` requires an already-existing admin).
- The baseline (Step 5) doesn't apply cleanly — do not attempt to patch it live against prod; stop and
  bring the failure back for review against a scratch DB first.

## When done — report

- Ledger state: empty after the wipe, then exactly one row (`20260726000000`) after the baseline apply
  — paste the verification query output.
- `SELECT rolcanlogin, rolbypassrls FROM pg_roles WHERE rolname = 'app_backend';` result (must be
  `t / t`).
- Grant-matrix diff result vs dev (audit/moderation specifics + pinned `search_path`) — matches or
  lists the delta.
- Both Auth hooks (Send Email, MFA Verification) registered and secrets confirmed matching.
- Auth project-config parity checklist — each item set/not-set.
- Seed (Step 10) status: ran with row counts (no UUIDs) and Task 5 marked deferred to Phase 4, OR
  explicitly deferred with the reason (staff not yet signed up on prod).
- An explicit **go / no-go for Phase 2** — do not soften a genuine blocker to call this done.

=== PROMPT END ===
```
