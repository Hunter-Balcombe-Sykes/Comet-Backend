# Execute prompt — P0-LAUNCH, concurrent with a live P1-PILOT session

Paste the block below into a **fresh Claude Code session** at the repo root.

This is the standard P0-LAUNCH bucket (Prompt 3 of `CONSOLIDATED.md`) hardened to run **alongside** an
already-running P1-PILOT session on `audit-fix/p1-pilot-2026-07-30`. Collision analysis behind the
ownership table lives in `CONSOLIDATED.md` → `## Execution prompts`.

**Do not use this file to launch P1-LAUNCH concurrently** — that bucket has three hard collisions with
P1-PILOT (`RunExecutor::execute`, the DSAR export path, and `cloudflare-worker/`).

---

## The prompt

> Work the **P0-LAUNCH** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit, models
> from that file's `## Execution policy` header. Do not invent a different flow.
>
> 🔴 **A second Claude session is running RIGHT NOW** on branch `audit-fix/p1-pilot-2026-07-30`, working
> the P1-PILOT bucket. Everything below about file ownership is load-bearing, not advisory. Read the
> whole prompt before touching anything.
>
> **Scope — these 6 findings ONLY**, in 4 units. Everything else in the file is out of scope.
>
> | Unit | IDs | Gate | Source of the full finding |
> |---|---|---|---|
> | 1 | `#TEST-2` + `#TEST-1` + `271-PARITY-1` | P0 | `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md` |
> | 2 | `LC-ROLLBACK` | P0 | `audits/launch-check/2026-07-26/REPORT.md` |
> | 3 | `#API-1` | P0 + public wire | `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md` |
> | 4 | `LC-DAST` | P0 + decision | `audits/launch-check/2026-07-26/REPORT.md` |
>
> ---
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> The other session is live in this repo, so you must **not** work in the main tree.
>
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p0-launch-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — all must exist, or you are on the wrong base. STOP if any fail.
> ls audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md
> ls .github/workflows/ci.yml
> ls scripts/launch-check/schema-drift-baseline.json
> ls tests/Pest.php
> ls app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
> ```
>
> The worktree needs its **own** `composer install` and a **real copied** `.env` — do not symlink either.
> A symlinked `vendor/` or `.env` is the known cause of phantom feature-test failures.
>
> - All commits land on `audit-fix/p0-launch-2026-07-30`. Never commit to `development` or `production`.
> - **Never run `git stash`.** The other session shares this checkout's stash stack and can pop or drop
>   yours. There is no situation in this task where you need it.
> - Never run `git checkout` / `git switch` in the main tree.
>
> ---
>
> ### Step 1 — concurrency pre-flight (run BEFORE planning anything)
>
> 🔴 **A branch-to-branch diff is NOT sufficient and will lie to you.** As of 2026-07-30 15:20 the other
> session's entire changeset was **uncommitted working-tree state** — `git diff origin/development...
> audit-fix/p1-pilot-2026-07-30` returned *empty* while fifteen files were actively modified on disk.
> You must inspect their **worktree**, not just their branch:
>
> ```bash
> git fetch origin
> git worktree list
>
> SIB="/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-pilot-2026-07-30"
>
> # 1. Their COMMITTED work (may legitimately be empty)
> git log --oneline origin/development..audit-fix/p1-pilot-2026-07-30 2>/dev/null
> git diff --name-only origin/development...audit-fix/p1-pilot-2026-07-30 2>/dev/null | sort
>
> # 2. Their UNCOMMITTED work — this is the one that matters right now
> git -C "$SIB" status --short
> ```
>
> The union of both lists is **live enemy territory**. Re-run **both** before every commit and once more
> before your final report — their file set grows as they advance through their units, and several of
> their remaining findings (`PRIV-3`, `271-SEM-1`, `#43`/`#EDGE-2`) have not started yet.
>
> If a file you have edited appears in either list, stop and reconcile. Do not resolve it silently at
> merge time.
>
> **Read-only rule:** you may `git -C "$SIB" status/diff/log` to observe. You must **never** run any
> mutating git command against that worktree, and never `git stash` in either tree.
>
> ---
>
> ### File ownership — the other session owns these. Do NOT edit them.
>
> **Confirmed modified on disk as of 2026-07-30 15:20** (observed, not predicted):
>
> | File | Why theirs |
> |---|---|
> | `app/Services/Analytics/Writers/PostgresEventWriter.php` | `PRIV-1` + `PRIV-2` |
> | `app/Services/Analytics/AnalyticsEventSanitizer.php` | `PRIV-2` |
> | `app/Console/Commands/PurgeRawAnalyticsEvents.php` | `PRIV-4` |
> | `config/partna.php` | `PRIV-4` retention keys |
> | `app/Observers/Core/IntegrationConnectionObserver.php` | `#CCH-4` + `#LIFE-11` |
> | `app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php` | `#LIFE-12` |
> | `app/Jobs/Platforms/MenuFetchJob.php` | `#LIFE-12` (the scrape-failure caller) |
> | `app/Ingest/Runtime/RunExecutor.php` | `#JOB-4` |
> | 🆕 `supabase/migrations/20260730110000_site_visits_location_precision_comment.sql` | `PRIV-1` — **new file, see flashpoint 2** |
> | `tests/Feature/Analytics/PublicIngestHardeningTest.php` | |
> | `tests/Feature/Console/PurgeRawAnalyticsEventsCommandTest.php` | |
> | `tests/Feature/Ingest/RunExecutorProjectionTest.php` | |
> | `tests/Feature/Middleware/LogLeadRateLimitsTest.php` | |
> | `tests/Unit/Analytics/AnalyticsEventSanitizerTest.php` | |
> | `tests/Unit/Analytics/PostgresEventWriterTest.php` | |
> | `tests/Unit/Services/Notifications/PlatformHealthNotifierTest.php` | |
>
> **Not yet touched, but they WILL claim these — treat as theirs from the start:**
>
> | File | Why theirs |
> |---|---|
> | `app/Services/User/AccountDeletionService.php` | `PRIV-3` (not started) |
> | `app/Services/User/DataExport/DataExportPayloadBuilder.php` | `PRIV-3` (not started) |
> | **`tests/Feature/Security/DataExportCoverageTest.php`** | `PRIV-3` — **most likely accidental collision, see flashpoint 1** |
> | `app/Services/Analytics/AnalyticsEvent.php` | `PRIV-1` |
> | `app/Console/Commands/ComputeContentPopularityScores.php` | `PRIV-4` |
> | `app/Services/Site/ItemSlugAllocator.php` | `271-SEM-1` (not started) |
> | **`cloudflare-worker/**`** (entire directory) | `#43` + `#EDGE-2` + `#10` (not started) |
>
> **If a fix seems to require one of these files, stop and ask** rather than editing it. The confirmed
> list is a snapshot — **re-derive it from Step 1 rather than trusting this table**, which will be stale
> by the time you read it.
>
> ---
>
> ### The two real flashpoints
>
> **1. `tests/Feature/Security/DataExportCoverageTest.php` — `#TEST-1` will want to touch it.**
> `#TEST-1` is about widening the schema-drift gate so it can see test files carrying inline
> `CREATE TABLE`. That test file is a plausible candidate for the sweep, and the other session is
> actively editing it for `PRIV-3`. **Exclude it from your change and leave a note in the commit
> message** naming it as deferred-for-concurrency. You may *read* it.
>
> Related, and subtler: `PRIV-3` adds moderation tables to `DataExportPayloadBuilder::COVERED_PII_TABLES`.
> Your `#TEST-1` work touches `tests/Feature/User/DataExport/DataExportTestCase.php` fixtures. Those two
> are semantically coupled even though they are different files — a new covered table may need matching
> fixture columns. **Neither branch will fail in isolation. Flag this explicitly in your final report so
> the DSAR tests get re-run on the merged result.**
>
> **2. `supabase/migrations/*.sql` — `LC-ROLLBACK` touches nearly every file in that directory.**
> The other session did **not** edit `20260707020000_site_visits_lat_lon.sql` as originally predicted —
> they created a **new** file instead:
> `supabase/migrations/20260730110000_site_visits_location_precision_comment.sql`.
>
> - **Skip that new file entirely.** It is theirs, it is untracked on their branch, and it needs its own
>   `-- to revert:` comment — which *they* should add, not you. Note it in your final report so the
>   convention isn't missed.
> - `20260707020000_site_visits_lat_lon.sql` is **not contested** — treat it as yours.
> - Every other migration file is yours.
>
> Because they are creating new migration files as they go, re-run Step 1 before your `LC-ROLLBACK`
> commit specifically — a migration that didn't exist when you started may exist by the time you finish
> sweeping the directory.
>
> ---
>
> ### Step 2 — verify the premise (per unit, before planning)
>
> This file was built by verifying 48 findings against live code; **12 (25%) were already done or wrong.**
> `VERIFICATION-LOG.md` §V1 carries 2026-07-30 evidence for all of unit 1 and records every part of it as
> **PARTIAL** — real progress already landed. **Scope to the remainder, not a rewrite.** Spot-check the
> cited lines still say what the log claims. If a finding no longer reproduces, tick it DEAD with a
> one-line note and move on. Deleting work is a successful outcome here.
>
> ---
>
> ### Blocker gate — every unit is a P0. Plan, present blast radius, wait for Josh's go-ahead, then implement.
>
> Do not implement any unit before sign-off. Produce all four plans up front if that's faster for him to
> review in one pass.
>
> ---
>
> ### Per-unit notes
>
> **Unit 1 — treat as one coherent piece of work.** All three findings are the same root problem: guards
> that exist but never execute.
> - `#TEST-2` remainder: `CheckConstraintsTest` already runs in the `schema-tests` CI job.
>   `IndexCoverageTest`, `ArchitectureSystemConstraintsTest` and `UpdatedAtTriggerCoverageTest` do **not** —
>   wire them into a Postgres-backed lane so their `pgsql` driver guard actually passes.
> - `#TEST-1` remainder: `NoLocalCanonicalTableDdlTest` narrowed the hole to 13 canonical tables
>   (grandfathered offenders 15 → 6), but **77 test files still carry inline `CREATE TABLE`** invisible to
>   the gate. Widen the gate's scope or its allowlist — **do not hand-migrate 77 files.**
> - `271-PARITY-1`: `site.menus.user_id` is already `NOT NULL`; **`site.menu_items.menu_id` and
>   `site.menu_items.name` remain nullable** in `tests/Pest.php` and grandfathered in
>   `scripts/launch-check/schema-drift-baseline.json`. Tighten both, remove the grandfather entries.
>
> ⚠️ **Tests run SQLite, prod runs Postgres.** That mismatch is the entire subject of this unit — verify
> every change against the DDL in `supabase/migrations/`, not against a green suite.
>
> **Unit 2 — `LC-ROLLBACK`.** 49 migrations landed since prod's last deploy; only 12 of 51 files carry any
> revert note. Add a one-line `-- to revert:` comment to each file lacking one, and record the convention
> in `supabase/migrations/CONVENTIONS.md` so new files inherit it.
> 🔴 **Documentation only. Do not write or run a reverse migration against any database.** The Free plan
> has no PITR, which is exactly why this matters.
> Skip `20260707020000_site_visits_lat_lon.sql` per the flashpoint note above.
>
> **Unit 3 — `#API-1`.** Add a `SHOP_PRODUCT_ALLOWLIST` and map each brand's products through
> `array_intersect_key` before they reach `PublicIntegrationConnectionResource`. This is the public
> unauthenticated wire → **escalate implement to Opus.** Add a test asserting an unlisted key never
> reaches the response. Note the other session does **not** touch this resource, so it is safely yours —
> but the P0-PILOT bucket's `271-PRIV-2` will, later. Keep the allowlist mechanism additive so that
> lands cleanly on top.
>
> **Unit 4 — `LC-DAST`.** The tooling already shipped to `development`; what's missing is baseline triage.
> Produce the triaged findings list with a recommended disposition per item and **present it to Josh** —
> do not silently accept or suppress anything.
>
> ---
>
> ### Step 3 — before every commit
>
> 1. Re-run the Step 1 pre-flight. If the sibling file list now intersects yours, stop and reconcile.
> 2. `composer test` green in your worktree.
> 3. Tick the finding in **both** `CONSOLIDATED.md` (this bucket) and its source audit file, and bump the
>    source file's `## Progress` counts.
> 4. Commit code + ticked files together: `fix(audit): p0-launch — <ids>`.
>
> Running the suite concurrently with the other session is safe: `phpunit.xml` pins `CACHE_STORE=array`,
> `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, so the worktrees share no Redis
> or Postgres state. It only costs CPU.
>
> ---
>
> ### Final report — must include a merge section
>
> Report: units done, units blocked (with reason), units awaiting Josh, test status, branch name. Then a
> dedicated **Merge notes** section stating:
> - every file you edited that also appears in `git diff --name-only origin/development...audit-fix/p1-pilot-2026-07-30`
> - anything you deliberately skipped for concurrency (expect at least `DataExportCoverageTest.php` and
>   the `site_visits_lat_lon` migration)
> - the DSAR fixture/`COVERED_PII_TABLES` coupling described above, flagged for a merged-result re-run
>
> 🔴 **Do not merge, and do not push to `development` or `production`.** Josh sequences the two branches.
> Whichever merges second must run the **full suite on the merged result**, not just on its own branch —
> both branches passing in isolation proves nothing about their combination.
