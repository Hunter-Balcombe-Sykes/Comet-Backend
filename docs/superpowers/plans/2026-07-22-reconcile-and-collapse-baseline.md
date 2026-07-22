# Reconcile Dev Drift + Collapse to Fresh Baseline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile the dev Supabase migration ledger to the repo, then collapse all 214 incremental migrations into a single `<ts>_baseline_pilot.sql` that provably applies from an empty DB and provably reproduces the live dev schema — the Phase-0 prerequisite for the prod cutover (`docs/deploy/production-cutover.md`).

**Architecture:** Three stages, each sign-off-gated: (1) reconcile dev's `supabase_migrations.schema_migrations` ledger against `supabase/migrations/` using `migration repair` (never bulk `db push`); (2) `supabase db dump` the verified dev schema and stitch on the cluster-level scaffolding a dump cannot emit (role bootstrap **incl. BYPASSRLS**, extensions, guard marker); (3) prove parity by applying the baseline to a throwaway local DB via `scripts/db/fresh-reset.sh` and diffing that DB directly against dev with `supabase db diff --from local --to linked`.

**Tech Stack:** Supabase CLI (`link`, `migration list/repair`, `db dump`, `db diff --from/--to`), `psql` via `scripts/db/fresh-reset.sh`, MCP `execute_sql` (read-only dev queries), Pest.

**Companion docs:** `docs/deploy/production-cutover.md` (the cutover runbook this feeds), `docs/deploy/PROMPT-execute-reconcile-and-collapse-baseline.md` (the paste-in execute prompt for this plan — **where the two conflict, THIS plan wins**; see "Corrections vs the PROMPT").

## Global Constraints

- **DEV-ONLY.** Every `link` / `repair` / `psql` / MCP call targets dev (`glncumufgaqcmqhzwrxm`) or a throwaway local DB. The prod project `edplucmvkcnokyygxqsb` is never contacted.
- **No `supabase db push` to dev, ever, in this task.** Dev records many repo migrations under different version numbers; a push re-runs DDL that already exists and can VALIDATE-fail against live data. Ledger fixes are `migration repair` (history-only); genuinely-new files are applied surgically one at a time.
- **App schemas (every dump/diff):** `public,core,site,notifications,analytics,audit,moderation`. Never include Supabase-managed `auth`, `storage`, `realtime`, `graphql`, `vault`, `extensions`, `pgbouncer`, `_analytics`, `supabase_functions`.
- **Sign-off gates:** present a written plan and WAIT for Josh before (a) any `migration repair`, (b) authoring the collapsed baseline, (c) archiving the incrementals. Blocker-gate task end to end.
- **Git discipline:** never `git stash` / `git checkout <file>` / `git restore` / `git reset` (shared stash across worktrees). `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before every commit. No `pint` sweep. DO NOT push; Josh reviews and owns the merge.
- **Subagents:** pin `model: sonnet` on implement/verify subagents; forbid `git stash` explicitly in every subagent prompt.
- **Local stack:** stop the sibling Comet stack before `supabase start` (shared ports 54321–54327). Local project id is `Partna-Development` (`supabase/config.toml`).

## Corrections from the 2026-07-22 adversarial review (now folded into the PROMPT + runbook — kept here as the rationale of record)

1. **BYPASSRLS is load-bearing and invisible to dump AND diff.** The current baseline runs `ALTER ROLE app_backend BYPASSRLS` (line ~2279) because several FORCE-RLS tables have **no** app_backend policy — without it the app is default-denied at runtime. It is a cluster-level role attribute: `db dump` won't emit it, no schema differ will report its absence, and the PROMPT's C3/D3 never mention it. The stitch (Task 5) must add it and the posture assertions (Task 8) must assert `rolbypassrls = true`.
2. **Pre-collapse `supabase db diff --linked` is expected to fail.** It builds a shadow DB by applying all `supabase/migrations/` through the pipelined applier, and 11 files bundle `CONCURRENTLY` with other statements (SQLSTATE 25001 — the exact defect `fresh-reset.sh` exists to bypass). Attempt it once; on failure use the sanctioned fallback: realize the repo into the local DB via `fresh-reset.sh`, then `supabase db diff --from local --to linked` (no shadow build). Post-collapse diffs are unaffected (the baseline is CONCURRENTLY-free).
3. **An empty schema diff does NOT prove grant/role parity.** Schema differs don't reliably track privileges, default ACLs, or role attributes. Task 8 adds an explicit grant-matrix comparison (same SQL run on both sides, outputs diffed) plus a canary check of the differ.
4. **Extensions:** the current baseline creates NO extensions; `pg_trgm` comes from incremental `20260721040000`. A `--schema`-filtered `pg_dump` does not emit `CREATE EXTENSION`, so the stitch must add it (and match the schema it's installed into on dev).
5. **Guard-marker justification:** the collapsed baseline fails guard **Checks 2 and 5** (pg_dump emits validated FKs as plain `ADD CONSTRAINT … FOREIGN KEY` with no `NOT VALID`; hot-table `ALTER TABLE` with no `BEGIN` + `SET LOCAL lock_timeout`) — **not** Checks 1/6 as the PROMPT's C4 says (Check 1 is exempted because every indexed table is created in the same file; Check 6 needs a `CONCURRENTLY`, and the dump has none). The `-- guard:no-unsafe-migrations:disable-file` marker skips ALL eight checks (guard line 92), so it is still the right opt-out — but write the accurate justification.
6. **The "9 grandfathered CONCURRENTLY bundles" count is 11.** Nine files bundle *multiple* CONCURRENTLY statements; `20260526210002_feedback_hardening.sql` and `20260612140000_site_custom_domain.sql` each pair ONE CONCURRENTLY with other statements — equally fatal to a pipelined from-zero apply. Fix the count where touched in Task 10 (CLAUDE.md, CONVENTIONS §1) — moot for the future (all archived) but wrong today.
7. **Audit append-only posture:** on a fresh DB, the dump's net ACLs (`GRANT SELECT, INSERT … TO app_backend`, no UPDATE/DELETE) + per-schema `ALTER DEFAULT PRIVILEGES` reproduce the posture — no REVOKE needed (there's nothing to revoke on a fresh DB; the REVOKEs in history exist only because a broad grant preceded them). Verify the NET posture (Task 8), don't hand-author REVOKEs into the dump.
8. **Reference sweep is wider than the PROMPT's E:** `AI_CONTEXT.md`, `scripts/audit/adjudicate-prompt.md` (3 mentions), and four app docblocks (`StaffSiteResource`, `EmailSubscription`, `Enquiry`, `PartnaStaff`) also name the old baseline file. Audit archives are left untouched.

---

### Task 0: Verify the GATE (read-only; STOP if any box fails)

**Files:** none (verification only).

- [ ] **Step 1: Pre-pilot RLS slice is merged and applied.** `git log --oneline origin/development | head -30` shows the `audit-fix/pre-pilot-rls-2026-07-22` merge (SCHEMA-7/9/10/11/12/13 — plus SCHEMA-6/DINT-3 if Josh opted into the optional tail; if he did, those migrations must be on dev too). Its new `supabase/migrations/*.sql` files exist in the working tree.
- [ ] **Step 2: B19 merged.** `scripts/guard-no-unsafe-migrations.php` contains Checks 5–8 (grep `CONCURRENTLY_GUARD_CUTOFF`, `DROP_INDEX_GUARD_CUTOFF`, `VALIDATE_TXN_GUARD_CUTOFF`). Already true on `origin/development` as of 2026-07-22.
- [ ] **Step 3: B8 + B20 on dev under exact repo versions.** Via MCP `execute_sql` (read-only):
  ```sql
  SELECT version FROM supabase_migrations.schema_migrations
  WHERE version IN ('20260722010000','20260721010000','20260721020000','20260721030000',
                    '20260721040000','20260721040100','20260721040200','20260721040300',
                    '20260721040400','20260721040500','20260721040600','20260721040700')
  ORDER BY version;
  ```
  Expected: all 12 rows.
- [ ] **Step 4: No other schema-bearing work in flight.** Confirm with Josh nothing schema-bearing since the 07-11 P0/P1 close (TRIAGE-1 23/23 @ `54929ef2`) is un-applied to dev.
- [ ] **Step 5: Suite + guard green on base.** `composer test` and `php scripts/guard-no-unsafe-migrations.php` both pass on `origin/development`.

If ANY box fails: STOP and tell Josh which. Do not reconcile a moving schema.

### Task 1: Isolated worktree on a new branch

**Files:** creates worktree `../backend-wt/collapse-baseline`.

- [ ] **Step 1:** `git fetch origin`
- [ ] **Step 2:** `git worktree add ../backend-wt/collapse-baseline -b chore/collapse-baseline-cutover origin/development && cd ../backend-wt/collapse-baseline` (NOT under `.claude/worktrees/` — poisons the Composer classmap).
- [ ] **Step 3:** `composer install`; copy a working `.env` into the worktree (plain copy — do not symlink `vendor`/`.env`, symlinks break the suite).
- [ ] **Step 4:** `git rev-parse --abbrev-ref HEAD` → must print `chore/collapse-baseline-cutover`.

### Task 2: Phase A — state report (read-only)

**Files:** Create: `/tmp/dev-drift.sql`, a STATE-REPORT note (scratch, pasted to Josh — not committed).

- [ ] **Step 1: Link + ledger.** `supabase link --project-ref glncumufgaqcmqhzwrxm` then `supabase migration list`. Capture the full `Local | Remote | Time` table. Classify every mismatched row: **Remote-only** (applied out-of-band, no repo file), **Local-only** (repo file never applied), **Aligned**.
- [ ] **Step 2: Attempt the direct drift diff (expect failure — that's fine):**
  ```bash
  supabase db diff --linked --schema public,core,site,notifications,analytics,audit,moderation > /tmp/dev-drift.sql
  ```
  Expected: **likely FAILS with SQLSTATE 25001** while building its shadow DB (11 CONCURRENTLY-bundle files; see Corrections #2). If it succeeds, use its output and skip Step 3.
- [ ] **Step 3: Fallback drift diff (no shadow build).** Stop the sibling Comet stack, then:
  ```bash
  supabase start
  scripts/db/fresh-reset.sh        # realizes ALL repo migrations into the local DB via the psql loop
  supabase db diff --from local --to linked \
    --schema public,core,site,notifications,analytics,audit,moderation > /tmp/dev-drift.sql
  ```
  Output = DDL to turn the repo-realized schema into dev = the out-of-band drift, made concrete. Empty file = only ledger bookkeeping remains.
- [ ] **Step 4: Record baseline counts** for the final reconcile-check: `ls supabase/migrations/*.sql | wc -l` (repo files — currently 214 + the pre-pilot RLS files), dev ledger rows (`SELECT count(*) FROM supabase_migrations.schema_migrations;` via MCP), and `/tmp/dev-drift.sql` byte size.
- [ ] **Step 5: Write the STATE REPORT** (remote-only list, local-only list, drift summary, counts) and present it to Josh before touching anything. Known drift to EXPECT (confirm live, don't trust): version-renumbered dupes — menu/services dev `…080945/081007/081023/081111` vs repo `…090000/150000/150001/180000`; Gate-A CHECK/FK batch dev `…052546→052646` vs repo `…100000→100600`; handle-prune dev `20260718020855` vs repo `20260718010000`; ~55 dev-only pre-consolidation rows; B8/B20/pre-pilot-RLS already aligned.

### Task 3: Phase B — reconcile the ledger (sign-off-gated per class)

**Files:** Create: adopted migration files (only if remote-only REAL schema exists). Modify: dev's `supabase_migrations.schema_migrations` via `supabase migration repair` only.

For EACH mismatched row, pick exactly one resolution and justify it in the report:

- [ ] **Step 1 — B1, version-renumbered dupes** (same DDL, two versions). Confirm the pair match: `SELECT version, name, statements FROM supabase_migrations.schema_migrations WHERE version IN ('<dev-ver>');` vs the repo file's statements. Then:
  ```bash
  supabase migration repair --status applied <repo-version>
  supabase migration repair --status reverted <dev-dupe-version>
  ```
- [ ] **Step 2 — B2, remote-only REAL schema** (applied directly, no repo file): adopt into the repo — reconstruct the file from `statements` (or `supabase db pull` to a capture file), commit it, then `repair --status applied <version>` if the file's version differs from the ledger's.
- [ ] **Step 3 — B3, remote-only genuinely superseded** (object provably absent from the live schema — prove with `to_regclass` / `pg_get_constraintdef` / `pg_policies` probes): `supabase migration repair --status reverted <version>`.
- [ ] **Step 4 — B4, local-only** (repo file not applied): if genuinely absent on dev, apply that ONE file surgically (MCP `apply_migration` or `psql -f` of that file against dev), then align the ledger version. **CONCURRENTLY caveat:** MCP/`execute_sql` wrap in a txn → `CREATE INDEX CONCURRENTLY` fails 25001; if the index already exists in `pg_indexes`, just `repair --status applied` — never re-run. **Data-safety pre-flight for ANY replay:** check current dev data can't VALIDATE-fail the constraint (`SELECT col, count(*) … GROUP BY` against the predicate; archetype: the early `platform_connections` CHECK predating `'custom'`/`'square'`). When in doubt, mark-applied instead of replay.
- [ ] **Step 5 — Converge.** Repeat until BOTH are clean:
  ```bash
  supabase migration list        # Local and Remote aligned, no orphans
  # drift diff (Task 2 Step 2 or 3 method) — EMPTY
  ```
  Note: if any repair/apply changed the repo-vs-dev delta, re-run `fresh-reset.sh` before re-running the `--from local` diff, so the local side reflects the current repo.
- [ ] **Step 6: Present proof (aligned list + empty diff) and get sign-off.**
- [ ] **Step 7: Commit** adopted files + a note listing repaired rows: `chore(migrations): reconcile dev ledger drift before baseline collapse`.

### Task 4: Phase C1–C2 — dump the verified dev schema

**Files:** Create: `/tmp/dev-schema.sql`.

- [ ] **Step 1: Choose the baseline version.** `ls supabase/migrations/*.sql | tail -1` → take the highest 14-digit prefix, pick the next round timestamp strictly greater (e.g. latest `20260722010000` → `20260723000000`; the pre-pilot RLS files will be later — derive from the actual max, don't guess). Name: `<ts>_baseline_pilot.sql`.
- [ ] **Step 2: Dump (structure only — the CLI default):**
  ```bash
  supabase db dump --linked --schema public,core,site,notifications,analytics,audit,moderation \
    -f /tmp/dev-schema.sql
  ```
- [ ] **Step 3: Cross-check the dump contains** (grep, spot-read): every app schema's tables; `ENABLE ROW LEVEL SECURITY` **and** `FORCE ROW LEVEL SECURITY` lines; every `CREATE POLICY`; functions with `SET search_path` clauses; triggers; `CREATE … VIEW site.all_site_data` and `site.public_site_payload`; CHECK constraints; `GRANT` lines for `app_backend`/`anon`/`authenticated`/`service_role`; `ALTER DEFAULT PRIVILEGES` per schema. Expected ABSENT (stitched in Task 5): any `CREATE ROLE`, any `CREATE EXTENSION`, `BYPASSRLS`.

### Task 5: Phase C3 — stitch the cluster-level scaffolding

**Files:** Modify: `/tmp/dev-schema.sql` (prepend the header block below).

- [ ] **Step 1: Check where pg_trgm lives on dev** (MCP, read-only): `SELECT e.extname, n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_trgm';` — if `nspname` isn't the default target, add `WITH SCHEMA <nspname>` to the CREATE EXTENSION below so the dumped `gin_trgm_ops` references resolve identically.
- [ ] **Step 2: Prepend this block** (hoisted ABOVE the first statement referencing `app_backend`):
  ```sql
  -- guard:no-unsafe-migrations:disable-file — collapsed baseline snapshot of the verified dev
  -- schema (<date>). Applies ONLY to an empty from-zero DB (prod cutover via psql, or
  -- scripts/db/fresh-reset.sh locally) — never against live traffic, so lock-safety patterns
  -- don't apply. Without this marker, Check 2 fails (pg_dump emits validated FKs as plain
  -- ADD CONSTRAINT ... FOREIGN KEY without NOT VALID) and Check 5 fails (hot-table ALTER TABLE
  -- with no BEGIN + SET LOCAL lock_timeout). See docs/deploy/production-cutover.md
  -- "Migration collapse" and docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md.

  -- Extensions: a --schema-filtered pg_dump does not emit CREATE EXTENSION.
  CREATE EXTENSION IF NOT EXISTS pg_trgm;

  -- Runtime role bootstrap (cluster-level: pg_dump never emits roles or role attributes).
  DO $$
  BEGIN
      IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
          -- NOLOGIN; password + LOGIN set out-of-band at prod cutover:
          --   ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret-store>';
          CREATE ROLE app_backend NOLOGIN;
      END IF;

      -- LOAD-BEARING (baseline decision 16): several FORCE-RLS tables have no explicit
      -- app_backend policy; without BYPASSRLS the app is default-denied on them.
      EXECUTE 'ALTER ROLE app_backend BYPASSRLS';
  END $$;
  ```
- [ ] **Step 3: Negative checks on the stitched file.**
  - `grep -c "CREATE ROLE" /tmp/dev-schema.sql` → exactly **1**, and it is `app_backend … NOLOGIN`. Supabase-managed roles (`authenticated`, `anon`, `service_role`, `postgres`) may be *referenced* in GRANTs but must never be `CREATE ROLE`-d (they pre-exist after `db reset`).
  - `grep -ci CONCURRENTLY /tmp/dev-schema.sql` → **0**.
  - No `CREATE SCHEMA auth|storage|extensions` and no objects in Supabase-managed schemas.
- [ ] **Step 4: Present the stitched header + checks to Josh; sign-off before Task 6.**

### Task 6: Phase C4 — install the baseline, archive the incrementals

**Files:** Create: `supabase/migrations/<ts>_baseline_pilot.sql`. Move: every other `supabase/migrations/*.sql` → `supabase/migrations-archive/` (dir exists, 151 files; do NOT create a nested `migrations/migrations-archive/`).

- [ ] **Step 1:** `cp /tmp/dev-schema.sql supabase/migrations/<ts>_baseline_pilot.sql`
- [ ] **Step 2:** `git add supabase/migrations/<ts>_baseline_pilot.sql` then `git mv` every OTHER `supabase/migrations/*.sql` — including old `20260526000000_baseline_standalone_user.sql` — into `supabase/migrations-archive/`.
- [ ] **Step 3:** `ls supabase/migrations/*.sql` → exactly one file (the new baseline). `CONVENTIONS.md` stays put.

### Task 7: Phase D1–D2 — prove from-zero apply + parity with dev

**Files:** none (throwaway local DB).

- [ ] **Step 1: From-zero apply.** With only the baseline in `supabase/migrations/`: `scripts/db/fresh-reset.sh`. Expected: `Applied 1 migrations`, zero errors. (A from-zero `supabase db reset`/`db push` is NOT a valid test — must be `fresh-reset.sh`. If the apply fails on `gin_trgm_ops`, Task 5 Step 1's extension stitch is wrong — fix and re-run.)
- [ ] **Step 2: Diff the ACTUAL fresh local DB against dev** (no shadow — diff the artifact you just built):
  ```bash
  supabase db diff --from local --to linked \
    --schema public,core,site,notifications,analytics,audit,moderation
  ```
  Expected: **EMPTY**. Any output means the dump/stitch dropped something — fix the baseline, re-run Steps 1–2. Do not proceed on a non-empty diff. If it won't reach empty after two dump/stitch iterations, STOP and surface exactly what differs.
- [ ] **Step 3: Differ canary (because empty ≠ grant parity — Corrections #3).** On the local DB only: `REVOKE SELECT ON site.sites FROM app_backend;` → re-run the Step-2 diff. If the diff **reports** the revoke, the differ tracks privileges (good — note it). If the diff stays empty, record that ACL parity rests entirely on Task 8. Either way restore: `GRANT SELECT ON site.sites TO app_backend;` and confirm the Step-2 diff is empty again.

### Task 8: Phase D3 — explicit posture assertions (both sides where noted)

**Files:** Create: `/tmp/grants-local.txt`, `/tmp/grants-dev.txt` (scratch).

Run via `psql` against the fresh local DB (`docker exec -i supabase_db_Partna-Development psql -U postgres -d postgres`); dev-side queries via MCP `execute_sql` (read-only).

- [ ] **Step 1: Role posture (local):**
  ```sql
  SELECT rolname, rolcanlogin, rolbypassrls FROM pg_roles WHERE rolname = 'app_backend';
  ```
  Expected: `rolcanlogin = f`, `rolbypassrls = t`. **Both matter** — NOLOGIN is the fail-closed cutover contract; BYPASSRLS is the runtime contract.
- [ ] **Step 2: Grant matrix — run identically on local AND dev, then `diff` the outputs:**
  ```sql
  SELECT grantee, table_schema, table_name,
         string_agg(privilege_type, ',' ORDER BY privilege_type) AS privs
  FROM information_schema.role_table_grants
  WHERE table_schema IN ('public','core','site','notifications','analytics','audit','moderation')
    AND grantee IN ('app_backend','anon','authenticated','service_role')
  GROUP BY 1,2,3 ORDER BY 2,3,1;
  ```
  ```sql
  SELECT pg_get_userbyid(d.defaclrole) AS owner, n.nspname, d.defaclobjtype, d.defaclacl::text
  FROM pg_default_acl d JOIN pg_namespace n ON n.oid = d.defaclnamespace
  WHERE n.nspname IN ('public','core','site','notifications','analytics','audit','moderation')
  ORDER BY 2,3;
  ```
  Expected: `diff /tmp/grants-local.txt /tmp/grants-dev.txt` → empty. In particular every `audit.*` table shows app_backend `INSERT,SELECT` only (append-only net posture).
- [ ] **Step 3: RLS flags + policies (local):** `SELECT n.nspname||'.'||c.relname, c.relrowsecurity, c.relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind='r' AND n.nspname IN ('core','site','notifications','analytics','audit','moderation') ORDER BY 1;` — compare against the same query on dev (diff empty). Policy names: `SELECT schemaname, tablename, policyname FROM pg_policies ORDER BY 1,2,3;` both sides, diff empty.
- [ ] **Step 4: Pinned search_path (local):** spot-check `SELECT proname, proconfig FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'audit';` → prune fns show `search_path=…`. The suite's `tests/Feature/Security/FunctionSearchPathTest.php` covers the app path in Task 9.
- [ ] **Step 5: Views resolve (local):** `SELECT count(*) FROM site.all_site_data; SELECT count(*) FROM site.public_site_payload;` → both run (0 rows fine).

### Task 9: Phase D4 — repo-level gates

**Files:** none.

- [ ] **Step 1:** `php scripts/guard-no-unsafe-migrations.php` → passes (baseline skipped via its disable-file marker; archived files aren't scanned).
- [ ] **Step 2:** `composer test` → green (SQLite can't test RLS, but the suite must boot + read/write; `AuditPipelineIntegrityTest` and `FunctionSearchPathTest` must pass).
- [ ] **Step 3:** Re-grep the committed baseline: exactly one `CREATE ROLE` (app_backend, NOLOGIN), `BYPASSRLS` present, `CREATE EXTENSION IF NOT EXISTS pg_trgm` present, zero `CONCURRENTLY`.

### Task 10: Phase E — update references + commit (unpushed)

**Files:** Modify: `CLAUDE.md`, `AI_CONTEXT.md`, `docs/deploy/production-cutover.md`, `supabase/migrations/CONVENTIONS.md`, `scripts/audit/adjudicate-prompt.md`, `app/Http/Resources/Staff/StaffSiteResource.php`, `app/Models/Core/Notifications/EmailSubscription.php`, `app/Models/Core/Site/Enquiry.php`, `app/Models/Core/Staff/PartnaStaff.php` (docblock pointers only).

- [ ] **Step 1: CLAUDE.md** — update the baseline filename (line ~67) to `<ts>_baseline_pilot.sql`; rewrite the "⚠ From-zero apply" paragraph (the CONCURRENTLY bundles are now archived — a from-zero apply of `supabase/migrations/` is just the one baseline; `fresh-reset.sh` still the local path, psql still the prod path; fix the stale "9 grandfathered files" count if the sentence survives). Do NOT touch the "Current reality" env block here — that's cutover-day scope.
- [ ] **Step 2: `docs/deploy/production-cutover.md`** — tick the Phase-0 collapse checkbox with a note (baseline filename, parity diff proven empty, fresh-reset clean), and adjust Phase-1 wording that names the old baseline.
- [ ] **Step 3: `CONVENTIONS.md` §1** — the grandfathered-bundle sentences now describe archived files; reword to past tense and correct the count (11, not 9 — see Corrections #6).
- [ ] **Step 4: Remaining pointers** — `git grep -n "20260526000000_baseline_standalone_user" -- ':!supabase/migrations-archive' ':!audits'` and update each live pointer (adjudicate-prompt lines that tell the model where to read schema; the four app docblocks) to the new baseline path. Leave `audits/archive/**` untouched.
- [ ] **Step 5: Commit** on `chore/collapse-baseline-cutover`: `chore(migrations): collapse history into <ts>_baseline_pilot (snapshot of verified dev schema)`. Before committing: `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` shows the baseline **added**, incrementals **renamed** (moved, not deleted), and the doc edits. **DO NOT push. DO NOT touch prod.**
- [ ] **Step 6: Final report to Josh** — reconciliation ledger actions, baseline filename + line count, the Task 7–9 proofs (paste them), reference updates, branch + `git log --oneline` (unpushed), explicit "prod was never contacted", and what remains for cutover day (Phases 1–4 of `production-cutover.md`: wipe + psql-apply baseline to prod, `ALTER ROLE app_backend WITH LOGIN PASSWORD`, secrets, deploy, verify).
