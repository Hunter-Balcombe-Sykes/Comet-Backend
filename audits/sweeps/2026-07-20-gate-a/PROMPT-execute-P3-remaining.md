# Gate A — execute prompt, PART 3 (remaining P3 units)

Finishes `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`. Run this **after** the P2 session
(`PROMPT-execute-P2-remaining.md`) has completed — some of these units depend on earlier ones. Covers
the low-tier units: **B16, B17, B18, B19.** When this session finishes and every box is `[x]`, the run
folder auto-archives.

**How to use:**
1. Open a **fresh Claude Code session in this repo** on model **Opus**.
2. Paste everything from `=== PROMPT START ===` to the end as your first message.
3. Only B19 trips the blocker gate (it's a migration unit). B16/B17/B18 are mechanical and run without
   asking.

---

```
=== PROMPT START ===

Continue executing audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md — the final P3 units (B16,
B17, B18, B19) on the existing branch audit-fix/gate-a-2026-07-20. Follow scripts/audit/fix-flow.md
with the gate-specific overrides below. Do NOT create a new branch; do NOT redo finished units.

## First: orient yourself
- `git fetch && git checkout audit-fix/gate-a-2026-07-20 && git log --oneline -20` — confirm the P0/P1
  and P2 fix(audit) commits are present. If the P2 units (B7–S4) are NOT all committed, STOP: this
  prompt runs after the P2 session, and B17/B19 depend on P2 and P0 work.
- Read `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` end to end — the `## Progress` block, the
  `## Discovered during execution` section (DISC-1 folds into B19 here), the `## Requires a schema
  change` table, and every `sources/<run>.md` file the four units below point at. **Finding lines
  carry only `Where`+`What to do`; the real evidence is in the source files — read them in full before
  planning.** The top "Findings at a glance" table is wrong as generated; trust the Progress block.

## Verify every premise (this run's defining lesson)
~40% of findings worked in earlier sessions had wrong, stale, or already-fixed premises — several were
closed by prior audit-fix runs whose commits are ancestors of this branch (`7b68bda5`, `3f41d147`,
`f5cdcd99`). Before touching any file: read the current code, confirm the defect exists, and
`git log --oneline --since=2026-07-10 -- <file>`. If a premise is false/already-satisfied, mark it
`no_change_needed` with evidence and move on. Line numbers in the audit have drifted — locate code by
reading, not by the cited line.

## Standing decisions carried from earlier sessions
- **Cutover (Josh):** prod cutover collapses migration history into a fresh baseline, so migration
  files will NOT replay against prod. **B19 is therefore hygiene-only** for local `db reset` / preview
  branches / DR — real severity is low. Prefer editing existing migration files IN PLACE over creating
  new `supabase/migrations/` versions (a new version is applied to the live dev DB by `db push`).
  **Do not apply any migration to any live DB in this run.**
- **SQLite string-literal trap:** unknown quoted identifier = string literal, not an error, so
  "does the query run" tests are vacuous. Verify columns against `supabase/migrations/` DDL, not
  `tests/Pest.php`. Reuse `DataExportCoverageTest`'s `MigrationColumnReplay` approach if needed.
- **Config lives in `config/partna.php`** (the canonical home for Partna limits/flags) — B18 is about
  moving literals there. Match the existing block structure; don't invent a new namespace (session 1
  hit a finding whose suggested `partna.public_site.*` key didn't exist — the real home was
  `partna.cache`).
- **Pin subagent models** (`model: sonnet` for impl+review) — inheritance defaults to the main-loop
  model and an Opus fan-out exhausts the budget.
- **Never `git stash`/`git checkout <file>`/`git restore`/`git reset`** — second active developer +
  prior stash. Read-only git only; `git show <ref>:<path>` to see old content. Forbid `git stash`
  explicitly in every spawned prompt.
- **Before every commit:** `git diff --cached --stat`, surgical, no `php artisan pint` sweep. Commit
  code + ticked audit file together: `fix(audit): <unit> — <ids>`. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- Tick `[ ]`→`[x]` only after tests pass AND independent review PASS; update `## Progress`. `composer
  test` is the gate; never run it while a subagent runs tests.

## Cadence
These are low-risk mechanical units. **Combine plan+implement (Sonnet) + single independent review
(Sonnet)** for B16, B17, B18. **B19 is a BLOCKER** (migration) — full plan (Opus) → implement (Sonnet)
→ independent review (Sonnet), and present the plan for sign-off before implementing.

## Unit order and notes

### 21. B16 — pin bare `DB::transaction()` to the pgsql connection (P3) — combine plan+impl
Six sites. `BaseModel` forces pgsql for models, but a bare `DB::transaction()` resolves the DEFAULT
connection — SQLite under test. Mechanical: pin each to `DB::connection('pgsql')->transaction(...)`.
Verify each site actually uses the default connection today (some may already be pinned). The advisory
locks / row locks inside these transactions are the reason it matters — a lock on the wrong connection
is a silent no-op.

### 22. B17 — cache-layer helper hygiene (P3) — combine plan+impl
⚠️ **Must run AFTER B1 (done in session 1).** B1 changed the invalidation call sites these helpers
would wrap. The B1 commit / CONSOLIDATED record lists the exact touched call sites — RE-READ them in
current code rather than working from the audit's stale list. Findings: shared `:stale`-key helper,
`ServiceCategoryObserver` bypassing the cache-service layer, inconsistent `->afterCommit()`, JWKS
throttle lock not pinning `cache_locks`, duplicated webhook TTL literals. Keep raw `Cache::` calls
inside cache services (the repo's GS-1 rule). Do not re-open anything B1 already settled.

### 23. B18 — config extraction sweep (P3) — combine plan+impl, do LAST of the mechanical three
Purely moving hardcoded literals into `config/partna.php`. Per finding, verify the literal isn't
already config-sourced (drift means some are). ⚠️ `authz-core/SEC-1` is different — it wants to DELETE
`EnsurePartnaAdmin`'s staff-lookup fallback as dead code "given the app's fixed middleware order."
**Verify that middleware-ordering claim against `bootstrap/app.php` before deleting anything** — if
the ordering isn't actually guaranteed, the fallback isn't dead and deleting it opens a hole.

### 24. B19 — migration hygiene, non-blocking (P2/P3) — BLOCKER (migration), Opus plan, sign-off
⚠️ **Present the plan and wait for sign-off.** Runs AFTER S1/S2 and B2 (both done — they edited some of
the same migration files; re-read current state, don't work from `git show HEAD:`).
- ⚠️ **FOLD IN `discovered/DISC-1`** (logged in CONSOLIDATED): `20260701180000_strip_block_settings_
  keys_and_views.sql:19` drops an index on `site.blocks` — a real `HOT_TABLES` entry — inside a
  transaction alongside a full-table `UPDATE site.blocks`. It is a STRONGER instance of what S1's
  `MIG-1` complained about, and the audit never opened the file. Fix it with the same `DROP INDEX
  CONCURRENTLY` pattern S1/S2 used (see those commits), respecting that the transaction wrapper and
  the `UPDATE` interact — you may need to split the drop out of the transaction as S2 did.
- **Several B19 items are "accept the exemption, apply the pattern going forward" — NOT edits.** Read
  each source entry (`migrations-early.md`, `migrations-recent.md`, `pii-schema.md`) before changing
  anything; the `pii-schema/SCHEMA-4/5/6` items are explicitly "accept as-is". Given the cutover
  makes these non-replaying, lean toward documented-exemption over churn where the source entry says
  so. The `MigrationTransactionBoundaryTest` created in B2/S1 is the right home for any new regression
  lock — extend it, following its no-Postgres-guard idiom so it runs on SQLite in CI.
- The composer migration guard (`scripts/guard-no-unsafe-migrations.php`) has a `TIMEOUT_GUARD_CUTOFF`
  — check whether the files you touch sit above/below it before adding `SET LOCAL` guards, and confirm
  you introduce no NEW guard failure.

## When the file is fully worked
1. Reconcile counts: `grep -cE '^- \[[ x]\] \*\*` on CONSOLIDATED should show every box ticked (128
   audit findings + any DISC items you closed). Any remaining `[ ]` must be a consciously-blocked or
   accepted-and-documented item — list them.
2. `composer test` once for the whole branch — must be green.
3. Run `scripts/audit/archive-done.sh audits/sweeps/2026-07-20-gate-a`. It checks only CONSOLIDATED.md
   (the `sources/*.md` boxes are deliberately ignored), so a fully-ticked consolidated file archives
   the whole gate. Do this automatically; never ask.
4. Report: total units done across all three sessions, anything blocked/accepted with reasons, test
   status, branch name.
5. **Do not push to `development` or `production`.** Josh reviews and merges. Flag for him: the
   discovered items still open (DISC-3 191-file stub sweep, DISC-4 ffprobe test, DISC-5 staff
   `{category}` route 500) and the declined `requests-resources/PRIV-1` (feedback email, product
   decision), which live outside this branch's scope.

## Stop and ask if
- B19's plan is ready — present it with blast radius + recommendation (it's a blocker).
- Two review rounds fail on the same unit — mark it blocked, surface it.
- A finding's premise turns out wrong in a way that suggests the audit misread the architecture.

=== PROMPT END ===
```
