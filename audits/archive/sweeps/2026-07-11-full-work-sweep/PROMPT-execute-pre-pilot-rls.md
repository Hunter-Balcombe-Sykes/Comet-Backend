# Pre-pilot — execute prompt: RLS / PRIVILEGE / CONSTRAINT posture

Curated pre-pilot slice of `audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-3-P3.md` (Batch 11, the
DB/schema items). Pulled out 2026-07-22 (Josh) as **the only P3 work worth doing before the pilot
prod-cutover** — everything else in TRIAGE-3-P3 (config hygiene, dead code, Resource extraction,
docblocks, the doc-only convention notes, the optional index) is post-pilot polish and stays parked.

**Why these, why now.** Every item here is calibrated in its source as **"no live exposure today"** —
the tables are `postgres`-owned (a superuser bypasses RLS regardless) and the app connects as
`app_backend`, which carries `BYPASSRLS`, so FORCE RLS / new RLS / the grant-revoke change **nothing at
runtime today**. That is exactly why they were re-tiered P3. The reason to do them **before pilot** is
not urgency — it is that schema/RLS/privilege posture is cleanest to set on the **fresh, empty
prod-cutover DB**: FORCE/VALIDATE scan 0 rows, a new CHECK can't collide with existing data, and the
security posture is correct from row 1 instead of retrofitted after real users have data. This is a
"do it while the DB is empty" opportunity, not a blocker.

**Scope (in):** `SCHEMA-7`, `SCHEMA-9`, `SCHEMA-10`, `SCHEMA-11`, `SCHEMA-12`, `SCHEMA-13`
(+ optional tail `SCHEMA-6`, `DINT-3`). **Out (defer):** `SCHEMA-8` (item_views dedup — needs an app
writer change; the finding says the Redis dedup is fine), `SCHEMA-103/104` (optional index, "accept
as-is"), `MIG-5/105/SCHEMA-105/106` (doc-only CONVENTIONS note), `MIG-104` (no-op close-out), `DINT-104`,
and all of TRIAGE-3 batches 1–10.

**Key difference from the gate-a B19 slice:** B19 was "extend the guard / document, apply no migration."
**This slice WRITES NEW `supabase/migrations/` files** — the RLS/grant/constraint DDL must be in the
migration set so the prod cutover picks it up. That means each new file **would** reach the live dev DB.
Do **not** `supabase db push` (dev carries migration drift — unsafe). Author + rehearse locally, then
apply to dev **surgically, one migration at a time, after sign-off** (see cadence).

**How to use:**
1. Open a **fresh Claude Code session in this repo** on model **Opus**.
2. Paste everything from `=== PROMPT START ===` to the end as your first message.

---

```
=== PROMPT START ===

Execute the PRE-PILOT RLS/PRIVILEGE/CONSTRAINT slice of
audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-3-P3.md (Batch 11 items SCHEMA-7/9/10/11/12/13, optional
SCHEMA-6/DINT-3). Follow scripts/audit/fix-flow.md with the overrides below.

## First: set up an ISOLATED worktree on a NEW branch
- `git fetch origin`
- `git worktree add ../backend-wt/pre-pilot-rls -b audit-fix/pre-pilot-rls-2026-07-22 origin/development`
  then `cd ../backend-wt/pre-pilot-rls`. (NOT `.claude/worktrees/` — it poisons the Composer classmap.)
- `composer install`; copy a working `.env` into this worktree (each worktree needs its own).
- `git rev-parse --abbrev-ref HEAD` MUST print `audit-fix/pre-pilot-rls-2026-07-22`.

## Orient (read before planning — finding lines are an index, the evidence is the source entry)
- In `TRIAGE-3-P3.md`, read the `# Schema / RLS / search_path Audit — 2026-07-11` section: the full
  entries for `#SCHEMA-6`, `#SCHEMA-7`, `#SCHEMA-9`, `#SCHEMA-10`, `#SCHEMA-11`, `#SCHEMA-12`, `#SCHEMA-13`
  (each carries Where / What to do / Technical), and `#DINT-3` further down. Read every one in full.
- Read the two precedent migrations you are mirroring:
  - `supabase/migrations/20260711160000_analytics_force_rls_parity.sql` — the FORCE-RLS parity shape
    AND the controlling calibration comment (postgres-owner / BYPASSRLS ⇒ "practical delta near-nil,
    forward-looking defence-in-depth"). Units 1 & 3 follow this file's structure and header voice.
  - `supabase/migrations/20260710140000_rls_policies_new_tables.sql` — the ENABLE + FORCE + owner/staff/
    app_backend policy shape for a new table. Unit 2 mirrors this.
- Read `scripts/guard-no-unsafe-migrations.php` end to end — every NEW migration file you author must pass
  it (`php scripts/guard-no-unsafe-migrations.php`). ENABLE/FORCE RLS, CREATE POLICY, REVOKE are not
  CONCURRENTLY operations, so they pass cleanly; a CHECK (optional Unit 4) must use the NOT VALID +
  separate-txn VALIDATE two-step (guard Check 8).
- Read `supabase/migrations/CONVENTIONS.md` (RLS/policy + lock-timeout conventions) and confirm the
  next-free migration timestamp = one greater than the highest existing `supabase/migrations/*.sql`
  filename. `Date.now()` is unavailable — read the max filename and increment.

## Standing decisions (override the runbook where they conflict)
- **VERIFY EVERY PREMISE against current schema.** Before authoring, confirm each table still exists with
  the RLS/grant posture the finding claims — query the LIVE dev DB (Supabase MCP `execute_sql` or
  `cloud tinker development`) for `relrowsecurity`/`relforcerowsecurity` on the target tables and the
  current grants on `core.user_segment_members`. A `no_change_needed` with quoted evidence (e.g. the
  table already got FORCE RLS in a later sweep) is a valid, valuable outcome — tick it with the proof.
- **These land as NEW forward migrations, and a new migration reaches the LIVE dev DB.** Do NOT
  `supabase db push` (dev has drift — unsafe). Flow per unit: author the `.sql` → rehearse with a LOCAL
  fresh apply → present for sign-off → after sign-off apply to dev **surgically, one file** (Supabase MCP
  `apply_migration`, or `psql -f` against the dev URL, then record the row in
  `supabase_migrations.schema_migrations`). Never apply to prod in this run.
- **Runtime effect is expected to be ZERO** (postgres-owner + `app_backend` BYPASSRLS). If your plan finds
  a path where any of these changes could break an app read/write TODAY, STOP and surface it — that would
  mean the finding's calibration is wrong and the item is not actually P3.
- **SQLite does NOT enforce RLS** — `composer test` cannot verify a policy. The real verification is the
  LOCAL Postgres rehearsal (`scripts/db/fresh-reset.sh` against a throwaway DB; stop the sibling Comet
  stack first — shared ports 54321–54327), then `psql` assertions that RLS is enabled + forced and the
  policies exist (`SELECT relrowsecurity, relforcerowsecurity FROM pg_class …`; `\d+` / `pg_policies`).
  A green SQLite suite proves only that the app still reads/writes the tables, not that RLS is correct.
- **SQLite string-literal trap:** an unknown quoted identifier is a string literal, not an error —
  "the query ran" proves nothing. Verify columns/policies against `supabase/migrations/` DDL + the
  Postgres rehearsal, never against a passing SQLite run.
- **NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset`** — the stash stack is shared
  across worktrees and other sessions may be live. Read-only git only; `git show <ref>:<path>` for old
  content. Forbid `git stash` explicitly in every subagent prompt you spawn.
- **Pin `model: sonnet`** on every implement/review subagent spawn (Opus fan-out exhausts the budget).
- **Commit discipline:** verify `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before
  EVERY commit. Surgical commits, no `php artisan pint` sweep. Commit the migration + the ticked
  `TRIAGE-3-P3.md` boxes together: `fix(audit): <unit> — <ids>`. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>

## Cadence — BLOCKER gate applies (all of this is migration/DB/RLS work)
Full `plan (Opus) → implement (Sonnet) → independent review (separate Sonnet)` per unit, and **present the
plan for sign-off before implementing** each unit (every unit authors a migration). Tick `[ ]`→`[x]` in
`TRIAGE-3-P3.md` only after: the guard passes, the LOCAL Postgres rehearsal confirms the posture, review
says PASS, and (once signed off) the dev apply succeeded. Run `composer test` per unit as a
did-not-break-the-app check — NEVER while a subagent is running tests.

## Units (smallest/safest first; present each before implementing)

### Unit 1 — FORCE RLS parity (SCHEMA-9, SCHEMA-10, SCHEMA-11) · S · one migration
Mirror `20260711160000_analytics_force_rls_parity.sql`. In one new migration:
`ALTER TABLE core.user_segments FORCE ROW LEVEL SECURITY;`
`ALTER TABLE core.user_segment_members FORCE ROW LEVEL SECURITY;`   (SCHEMA-9)
`ALTER TABLE core.feature_availability FORCE ROW LEVEL SECURITY;`    (SCHEMA-10)
`ALTER TABLE core.early_access_signups FORCE ROW LEVEL SECURITY;`    (SCHEMA-11 — holds PII, do last/most-careful)
Precondition to verify first: each table has RLS ENABLED but not FORCED, and already carries the policies
FORCE will now bind (FORCE with zero policies = deny-all for non-owners — confirm policies exist, or add
the missing owner/app_backend policy in the same file). Header comment should carry the same
"defence-in-depth, near-nil practical delta" framing as the precedent file.

### Unit 2 — new RLS on app-managed tables (SCHEMA-12, SCHEMA-13) · M · one migration + coverage
The only real design work. Both tables currently have **no RLS at all** (deliberately, to match each
other). Mirror `20260710140000_rls_policies_new_tables.sql`: ENABLE + FORCE ROW LEVEL SECURITY + policies
on `site.content_selection` (SCHEMA-12) and `site.workplaces` (SCHEMA-13, holds name/address/phone/email —
most sensitive). Policies: owner-read (row's `user_id` = the JWT subject), staff-read (via the
`core.partna_staff` predicate the sibling policies use), `app_backend`-all. **Match the exact owner/staff
predicate the existing site.* policies use** — read a current `site.*` table's policies first; do not
invent a new predicate shape. Verify the app's read path for both tables still works under the
Postgres rehearsal (app_backend is BYPASSRLS so it should — prove it). Add a regression assertion in the
Postgres-rehearsal path (RLS enabled+forced + named policies present); do NOT rely on SQLite to test this.

### Unit 3 — privilege hardening (SCHEMA-7) · S · one tiny migration
`REVOKE UPDATE, DELETE ON core.user_segment_members FROM app_backend;` (leave SELECT, INSERT — mirrors the
audit.* append-only posture). Precondition: confirm no app code path does UPDATE/DELETE on
`UserSegmentMember` (the model declares `UPDATED_AT = null`, no SoftDeletes, insert-only) — grep first. If
any path exists, STOP and surface it.

### Unit 4 — CHECK constraints (SCHEMA-6, DINT-3) · S · OPTIONAL — recommend DEFER
Lower value; include only if Josh wants Batch 11 fully closed.
- `SCHEMA-6` — `core.feedback.type` CHECK (nullable, staff-only internal table; the finding itself calls
  skipping it "a defensible, documented judgment call"). Two-step: `ADD CONSTRAINT … CHECK (…) NOT VALID`
  then `VALIDATE CONSTRAINT` in a SEPARATE transaction (guard Check 8).
- `DINT-3` — `site.shop_brands` selection_mode/link_mode CHECK. **First confirm `site.shop_brands` is not
  vestigial** — commerce/Shopify was stripped in the standalone strip-down; if the table is dormant and
  not on the pilot path, DEFER and tick `no_change_needed` with that note. Same NOT VALID + VALIDATE
  two-step if you do proceed.

## When your slice is done
- `php scripts/guard-no-unsafe-migrations.php` passes for every new file.
- LOCAL `scripts/db/fresh-reset.sh` applies clean from zero and the RLS/grant posture is confirmed by psql.
- `composer test` green on your branch.
- Each signed-off migration applied to dev surgically (single file, not `db push`) + recorded in
  `schema_migrations`.
- Tick your boxes in `TRIAGE-3-P3.md`. NOTE: this will NOT auto-archive the folder — 59 other P3s remain
  open and `CONSOLIDATED.md` is unreconciled. Do NOT run `archive-done.sh`.
- Report: units done / accepted-as-no-change (with evidence) / deferred / blocked; guard + rehearsal +
  test status; which migrations were applied to dev; branch name; your tick delta.
  **Do NOT push to development/production, and do NOT apply anything to prod** — Josh reviews, merges, and
  owns the prod cutover.

## Stop and ask if
- A migration plan is ready — present it with the exact DDL, blast radius, and a recommendation (blocker
  gate); wait for sign-off before implementing AND before the dev apply.
- Any change would have a NON-zero runtime effect today (breaks an app read/write) — the P3 calibration
  would be wrong; surface it rather than shipping.
- A precondition fails (table already forced/policied; an UPDATE/DELETE path exists; shop_brands is live) —
  surface it; convert to `no_change_needed` or re-scope.
- Two review rounds fail on a unit — mark it blocked and surface it.

=== PROMPT END ===
```
