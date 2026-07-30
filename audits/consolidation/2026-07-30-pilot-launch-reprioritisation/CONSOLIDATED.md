# Pilot / Launch re-prioritisation — CONSOLIDATED — 2026-07-30

**302 open audit findings, re-graded against the pilot and launch gates and verified against live code.**
Execution prompts are directly below; the reasoning and evidence follow them.

---

## Execution policy  (how `execute audit` runs this file)

Per `scripts/audit/fix-flow.md`, these three values govern every unit worked out of this file:

| Step | Model |
|---|---|
| **Plan** | Opus |
| **Implement** | Sonnet — escalate to Opus for any unit touching the public wire, a lock, or a DB write path |
| **Review** | Sonnet — always a **separate** instance from the implementer |

**Blocker gate** (pause for Josh's sign-off before implementing): any P0-PILOT or P0-LAUNCH unit, or any
unit touching auth/authorization, money, a DB migration, or graded L/XL.

**One rule specific to this file, and it is non-negotiable:**

> **Verify the premise before writing a line of code.** This file was built by verifying 48 findings
> against live code, and **12 of them (25%) were already done or simply wrong.** The source audits are
> between 2 and 19 days old. Every unit therefore starts by re-confirming the finding still reproduces.
> `VERIFICATION-LOG.md` in this folder records what was true on 2026-07-30 — start from it, don't
> re-derive it, but don't trust it blindly either. **If a finding no longer reproduces, tick it as DEAD
> with a one-line note and move on. Do not invent work to justify the ticket.**

## Execution prompts

Four prompts, one per bucket. Paste the indented block into a **fresh Claude Code session** at the repo
root. Run them in order — **P0-PILOT → P1-PILOT → P0-LAUNCH → P1-LAUNCH** — and don't run two at once
(units within a bucket touch overlapping files, and `audit.sh` adjudications are sequential).

This file carries **summaries, not full findings**. Every prompt names the source audit file each ID
lives in; the `Where:` / `Technical:` / `Evidence:` blocks there are the real spec.

---

### Prompt 1 — P0-PILOT (7 findings, 6 units)

> Work the **P0-PILOT** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit, models
> from this file's `## Execution policy` header. Do not invent a different flow.
>
> **Scope — these 6 units ONLY.** Everything else in the file is deliberately out of scope for this run.
>
> | Unit | ID | Kind | Source of the full finding |
> |---|---|---|---|
> | 1 | `271-PRIV-2` | **product/legal decision, then code** | `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md` |
> | 2 | `#INH-7-DRIFT` | code — one validation rule | `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` + `VERIFICATION-LOG.md` §V3 |
> | 3 | `LC-PROD-ENV` | ops — Josh only | `audits/launch-check/2026-07-26/REPORT.md` |
> | 4 | `LC-BACKUP` | ops + decision — Josh only | same |
> | 5 | `LC-RUNBOOKS-2` | docs — two runbooks | same |
> | 6 | `LC-EDGE-HARDENING` | dashboard config — Josh only | same — **covers two report rows**: Cloudflare dashboard + Supabase dashboard |
>
> **Read this before planning:** only units 2 and 5 are things you can do unaided. Units 3, 4 and 6 are
> Josh's to execute (stopped Cloud env, Supabase plan tier, Cloudflare/Supabase dashboard toggles) — for
> those, produce a precise checklist with the exact commands/settings and **stop**. Unit 1 needs his
> decision before any code exists to write. Do not attempt to action 3, 4 or 6 yourself, and **never**
> start, stop, deploy or promote an environment.
>
> ### Step 0 — isolated worktree (REQUIRED)
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p0-pilot-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-pilot-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-pilot-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — all must exist, or you are on the wrong base. STOP if any fail.
> ls audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md
> ls app/Services/Platforms/Payloads/GoogleBusinessPayload.php
> ls app/Services/Platforms/DisplaySettingsFilter.php
> ls docs/runbooks/
> ```
> Own `composer install` and a real copied `.env` — **never symlink either**; symlinked `vendor/`/`.env`
> is the known cause of phantom feature-test failures. All commits land on the branch above.
> **Never run `git stash`** — another session may pop or drop it.
>
> ### Step 1 — verify the premise (do this first, per unit)
> Re-confirm each finding still reproduces. `VERIFICATION-LOG.md` §V3 and §V6 already carry evidence
> dated 2026-07-30 — read it rather than re-deriving, then spot-check the cited lines still say what it
> claims. If a finding no longer reproduces, tick it DEAD with a one-line note and move on.
>
> ### Blocker gate — every unit here is a P0. Plan first, wait for sign-off, then implement.
>
> **Unit 1 — `271-PRIV-2` is a decision, not a task.** Current verified behaviour: `stripThirdPartyPii`
> runs only while `user.status === 'unclaimed'`; on claim the strip stops applying and the next refresh
> restores full Google reviewer names, photos and review text into the payload, which then ships to
> unauthenticated visitors because the `google-business` allowlist includes `reviews`/`reviewSummary`
> behind a `DisplaySettingsFilter` toggle that defaults **ON**. Present Josh the options — strip reviewer
> identity entirely / redact to first name or initial / keep and record the lawful basis — with the blast
> radius of each. **Do not pick for him.** Note this data is CDN-cached, so whatever is chosen, ask
> whether a purge is needed for anything already served.
>
> **Unit 2 — `#INH-7-DRIFT` is the one clean code fix in this bucket.** A shared `WithBotProtection`
> trait already exists and is used by three of the four public form request classes, all requiring
> `form_started_at_ms`. `PublicEarlyAccessSignupRequest` skipped the trait and declares that field
> `nullable`; because the controller gates on `is_int($startedMs)`, omitting the field bypasses the
> timing/anti-automation check entirely on that endpoint. Fix by adopting the trait (preferred) or making
> the field required — **and add a regression test asserting a submission with the field absent is
> rejected on all four endpoints.** Do not refactor the other three controllers in this unit; that is
> `#INH-7`'s consistency half and it is BACKLOG.
>
> **Unit 5 — `LC-RUNBOOKS-2`.** Write `docs/runbooks/` entries for **DB pool exhausted** and **queue
> backed up**, matching the shape of the existing `vendor-outage` and `redis-down` runbooks. Ground the
> pool one in the real constraint: Supavisor session mode pins a connection slot per *process*, and six
> Horizon daemons consume six of fifteen — so symptoms, how to confirm via `pg_stat_activity`, and what
> to shed first.
>
> ### Record + report
> Tick each finding in **both** this file and its source audit file, bump the source file's `## Progress`
> counts, and commit code + ticked files together as `fix(audit): p0-pilot — <ids>`. Then report: units
> done, units awaiting Josh, test status, branch name. **Do not push to `development` or `production`.**

---

### Prompt 2 — P1-PILOT (12 findings, 6 units)

> Work the **P1-PILOT** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit, models
> from this file's `## Execution policy` header.
>
> **Scope — these 12 findings ONLY**, grouped into 6 units. Work units sequentially.
>
> | Unit | IDs | Eff | Source file |
> |---|---|---|---|
> | 1 | `PRIV-1` + `PRIV-2` + `PRIV-4` | S+S+S | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 2 | `PRIV-3` | M | same |
> | 3 | `#CCH-4` + `#LIFE-11` | S+S | `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md` |
> | 4 | `#LIFE-12` + `#JOB-4` | S+S | same |
> | 5 | `271-SEM-1` | M | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 6 | `#43` + `#EDGE-2` + `LC-NIGHTWATCH` | M+S+S | `#43` → 07-11 sweep; `#EDGE-2` → 07-28 sweep; `LC-*` → launch-check report |
>
> ### Step 0 — isolated worktree (REQUIRED)
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p1-pilot-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-pilot-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-pilot-2026-07-30"
> composer install && cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — STOP if any fail.
> ls app/Services/Analytics/AnalyticsEventSanitizer.php
> ls app/Observers/Core/IntegrationConnectionObserver.php
> ls app/Services/Site/ItemSlugAllocator.php
> ls cloudflare-worker/src/index.js
> ```
> Own `composer install`, real copied `.env`, never symlinked. **Never run `git stash`.**
>
> ### Step 1 — verify the premise per unit
> `VERIFICATION-LOG.md` §V5 and §V6 carry 2026-07-30 evidence for units 1–5. Start there; spot-check the
> cited lines. Tick DEAD and move on if a finding no longer reproduces.
>
> ### Blocker gate
> - **Unit 2 (`PRIV-3`)** touches the GDPR erasure path → plan, present blast radius, **wait for sign-off**.
> - **Unit 5 (`271-SEM-1`)** changes public URL slugs and may need a migration → same, wait for sign-off.
> - Units 1, 3, 4, 6 proceed without asking.
>
> ### Per-unit notes
> **Unit 1** — three small analytics-privacy changes in one pass: round visitor lat/long to ~2dp in
> `PostgresEventWriter::visitRow()`; extend `AnalyticsEventSanitizer::userAgent()` to reduce to browser
> family + major version rather than only length-capping at 256; add
> `analytics.content_popularity_scores` to `PurgeRawAnalyticsEvents::TABLES` with an explicit retention
> window in `config/partna.php`. Document the lat/long truncation in a migration comment.
>
> **Unit 3** — `#CCH-4`: add `$connection->user?->site?->touch()` inside the same
> `hasCompletenessPredicate()` gate in `deleted()` and `restored()` that `saved()` already uses.
> `#LIFE-11`: wrap `cleanupMirroredMedia`'s body in try/catch matching every sibling best-effort method
> in that class. Same file — one unit, one review.
>
> **Unit 4** — `#LIFE-12`: the sibling `connectionRefreshFailing` **already implements the fix** and
> carries a comment documenting the earlier discovery; copy that episode-boundary pattern into
> `menuScrapeFailed` rather than designing a new one. `#JOB-4`: in `RunExecutor`'s projection catch block,
> downgrade `$worstOutcome` to `'degraded'` — that rank already exists in `worse()` and is currently unused.
>
> **Unit 6** — `#43` is the largest item here: `cloudflare-worker/` has **zero** tests. Add a minimal
> Vitest/Miniflare suite covering the security-relevant branches (KV `individual` vs `alias` routing,
> the 301 path, cache-key construction, security headers). Do not attempt full coverage — cover what
> would silently break. `#EDGE-2` is the paired hour: rewrite `cloudflare-worker/README.md` to match the
> actual `individual`/`alias` KV contract. While in this file, also fix `#10` (`unclaimedHtml()`
> hardcodes `https://partna.au`) if it is a one-liner — otherwise leave it, it is P1-LAUNCH.
> ⚠️ `SyncSubdomainToKvJob` is the **only** permitted KV writer — do not add another.
>
> ### Record + report
> Tick in **both** this file and each source audit file, bump their `## Progress` counts, commit as
> `fix(audit): p1-pilot — <ids>`. Run `composer test` green before reporting. Do not push to
> `development`/`production`.

---

### Prompt 3 — P0-LAUNCH (6 findings, 4 units)

> Work the **P0-LAUNCH** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly. **Every unit here is a P0 → plan first, wait for sign-off,
> then implement.**
>
> **Scope — these 6 findings ONLY**, in 4 units.
>
> | Unit | IDs | Source file |
> |---|---|---|
> | 1 | `#TEST-2` + `#TEST-1` + `271-PARITY-1` | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 2 | `LC-ROLLBACK` | `audits/launch-check/2026-07-26/REPORT.md` |
> | 3 | `#API-1` | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 4 | `LC-DAST` | `audits/launch-check/2026-07-26/REPORT.md` |
>
> ### Step 0 — isolated worktree
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p0-launch-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30"
> composer install && cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — STOP if any fail.
> ls .github/workflows/ci.yml
> ls scripts/launch-check/schema-drift-baseline.json
> ls tests/Schema/ tests/Postgres/
> ```
> **Never run `git stash`.**
>
> ### Step 1 — verify the premise
> `VERIFICATION-LOG.md` §V1 carries 2026-07-30 evidence for all of unit 1 and records each as **PARTIAL** —
> real progress already landed, so scope this to the *remainder*, not a rewrite. Confirm before planning.
>
> ### Per-unit notes
> **Unit 1 is one coherent piece of work — treat it as such.** All three findings are the same root
> problem: guards that exist but never execute.
> - `#TEST-2` remainder: `CheckConstraintsTest` already runs in the new `schema-tests` CI job.
>   `IndexCoverageTest`, `ArchitectureSystemConstraintsTest` and `UpdatedAtTriggerCoverageTest` do not —
>   wire them into a Postgres-backed lane so their `pgsql` driver guard actually passes.
> - `#TEST-1` remainder: `NoLocalCanonicalTableDdlTest` narrowed the hole to 13 canonical tables
>   (grandfathered offenders 15 → 6), but **77 test files still carry inline `CREATE TABLE`** invisible
>   to the schema-drift gate. Widen the gate's scope or the allowlist — do not hand-migrate 77 files.
> - `271-PARITY-1`: `site.menus.user_id` is already `NOT NULL`; **`site.menu_items.menu_id` and
>   `site.menu_items.name` remain nullable** in `tests/Pest.php` and grandfathered in
>   `scripts/launch-check/schema-drift-baseline.json`. Tighten both and remove the grandfather entries.
>
> ⚠️ **Tests run SQLite, prod runs Postgres.** That mismatch is the entire subject of this unit — verify
> every change against the DDL in `supabase/migrations/`, not against a green suite.
>
> **Unit 2 — `LC-ROLLBACK`.** 49 migrations landed since prod's last deploy; only 12 of 51 files carry any
> revert note. Add a one-line `-- to revert:` comment to each migration lacking one, and add the
> convention to `supabase/migrations/CONVENTIONS.md` so new files inherit it. **Do not write or run
> reverse migrations against any database** — this unit produces documentation only. Note the Free plan
> has no PITR, which is why this matters.
>
> **Unit 3 — `#API-1`.** Add a `SHOP_PRODUCT_ALLOWLIST` and map each brand's products through
> `array_intersect_key` before it reaches `PublicIntegrationConnectionResource`. Touches the public wire →
> escalate implement to Opus. Add a test asserting an unlisted key never reaches the response.
>
> **Unit 4 — `LC-DAST`.** The tooling already shipped to `development`; what is missing is baseline
> triage. Produce the triaged findings list with a recommended disposition per item and **present it to
> Josh** — do not silently accept or suppress anything.
>
> ### Record + report
> Tick in both files, commit as `fix(audit): p0-launch — <ids>`, `composer test` green. No pushes to
> shared branches.

---

### Prompt 4 — P1-LAUNCH (27 findings, 6 units)

> Work the **P1-LAUNCH** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly. This is the largest bucket and the least urgent — **it is
> also the one most likely to contain findings that are already done.** Verify every premise before
> planning; expect casualties.
>
> **Scope — these 27 findings ONLY**, in 6 units.
>
> | Unit | IDs | Theme | Source |
> |---|---|---|---|
> | 1 | `#SCALE-13/14/17/19/20` + `#CACHE-1/2/3` | unbounded `whereIn` + per-row INSERT loops | 07-28 sweep |
> | 2 | `DINT-1` + `271-PRIV-1` + `#3` | missing indexes + unbounded retention | 07-24 sweep; `#3` → 07-11 |
> | 3 | `#SCALE-11` | `SiteMedia` force-delete storage I/O | 07-28 sweep |
> | 4 | `#TEST-9`/`271-TEST-1` + `#TEST-41` + `#TEST-49` + `#TEST-50` + `#38` | migration invariant guards | 07-24 + 07-28 sweeps |
> | 5 | `#9` + `#10` + `#SEC-4` + `#INH-6` | residuals | 07-11, 07-28, inheritance |
> | 6 | `LC-DRILL-worker-kill` + `LC-DRILL-vendor-outage` + `LC-DRILL-redis-down` + `LC-K6` + `LC-RERUN` | operational drills | `audits/launch-check/2026-07-26/REPORT.md` |
>
> ### Step 0 — isolated worktree
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p1-launch-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-launch-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-launch-2026-07-30"
> composer install && cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
> ```
> **Never run `git stash`.**
>
> ### Step 1 — verify the premise, and expect to delete work
> This bucket draws partly on the 2026-07-11 sweep, where sampling found **6 of 14 findings already
> fixed**. Before planning any unit, re-confirm each finding reproduces. **Tick DEAD and move on** for any
> that don't — that is a successful outcome, not a failed one.
>
> ### Blocker gate
> - **Unit 2** adds DB indexes → migration → plan, wait for sign-off.
> - **Unit 3 (`#SCALE-11`)** is listed **standalone** in the 07-28 sweep because it touches the GDPR
>   account-deletion data path → plan, wait for sign-off, isolate its review.
> - **Unit 4** changes `supabase/migrations` invariants → wait for sign-off.
> - Units 1, 5, 6 proceed without asking.
>
> ### Per-unit notes
> **Unit 1** — pure scale work; none of it bites at pilot volume. Chunk unbounded `whereIn` arrays
> (~500/batch) and collapse per-row INSERT loops into multi-row inserts. Measure before and after on one
> representative path rather than fanning out on assumption.
>
> **Unit 2** — `DINT-1` needs `CREATE INDEX CONCURRENTLY` on `analytics.action_events(user_id)` and
> `analytics.item_views(user_id)`. ⚠️ **One `CONCURRENTLY` statement per migration file, alone** — the
> Supabase CLI pipelines multi-statement files and `CONCURRENTLY` cannot run in a pipeline (`SQLSTATE
> 25001`). `271-PRIV-1` adds `retired_at` + a scheduled prune command in `routes/console.php`.
>
> **Unit 4** — these are grep-based invariant tests, cheap and high-value. Note `#TEST-9`/`271-TEST-1`
> is worse than the source audits record: `ArchitectureSystemConstraintsTest` exists but does **not**
> assert `site.themes` stays dropped, **and** it doesn't run in CI. `CLAUDE.md` currently claims this
> rule is "pinned" by that test — **correct that claim as part of this unit.** `#TEST-41`:
> `ItemTombstoneBackfillTest` already reads real migration SQL off disk; copy that pattern into
> `BrandAssetPipelineTest` and `CatalogSyncIdempotenceTest`.
>
> **Unit 5** — `#INH-6` is verified **NOT drifted**: the three `normalizeName`/`norm` copies are still
> byte-identical, so this is consolidation into the `NormalizesMenuData` trait, not a bug fix. Prove
> byte-identity again before touching it; if they *have* drifted since 2026-07-30, stop and escalate —
> that would be a live menu-matching bug, not a refactor.
>
> **Unit 6 is not code.** Run the three drills from `docs/runbooks/drills/` on the **LOCAL** stack only
> and log to `docs/runbooks/drills/logs/`. Run the k6 baseline (10 VU/5 min) + public-handle spike
> (50–100 VU) **against dev only**, watching edge cache-hit ratio, Supavisor headroom and p95.
> ⚠️ The k6 harness hard-codes three real invariants (gallery capped at 6/site, gallery items needing a
> matching `site.media_variants` row, analytics writes needing a matching `Origin`/`Referer`) — if a seed
> fails, check those before debugging the harness. `LC-RERUN` is a process change: document re-running
> launch-check after every migration push and before every promote.
>
> ### Record + report
> Tick in both files, commit as `fix(audit): p1-launch — <ids>`, `composer test` green. Report DEAD
> findings separately from fixed ones so the count is honest. No pushes to shared branches.

---

## Scope

- **Input:** every open (`- [ ]`) item across all six non-archived audit folders + the launch-check report
- **Method:** mechanical extraction (Haiku ×7) → verification against current code (Sonnet ×6) → re-grading (Opus)
- **Branch at time of run:** `guard/postgres-lane-walker`
- **Sources read:**
    - `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md` — 170 open
    - `audits/sweeps/2026-07-11-full-work-sweep/CONSOLIDATED.md` — 59 open (all P3)
    - `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md` — 43 open
    - `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` — 17 open
    - `audits/sweeps/2026-07-27-dead-code/BACKEND-INHERITANCE-CONSOLIDATION-VERIFIED.md` — 16 open (same 17 findings as above, re-numbered; **not** double-counted)
    - `audits/launch-check/2026-07-26/REPORT.md` — 13 open action items
- **Universe:** **302 unique open items.**

## Why this file exists

The existing audit folders grade findings on *technical severity at scan time*. They do not answer
"what must be true before a real customer touches this." That is a different question, and the answer
re-orders the list substantially: several P2/P3 items are pilot blockers, and a large majority of the
P2 tranche is safely post-launch.

Two structural problems in the source corpus had to be solved first:

1. **A tick records intent, not state.** The 07-28 sweep says so in its own header — a large share of
   its P1s were closed by *re-grading*, not by writing what was asked.
2. **The inverse is also true, and nobody was tracking it.** Work shipped between 07-11 and today
   closed findings that were never ticked back. There is no mechanism in the audit pipeline that
   would notice.

So nothing here carries a priority tag until it was checked against the code as it exists now.

## New priority vocabulary

| Tag | Means |
|---|---|
| **P0-PILOT** | Blocks the first real customer. Do not run a pilot with these open. |
| **P1-PILOT** | The pilot itself will expose this. Land it before or in the first week. |
| **P0-LAUNCH** | Blocks public launch. Pilot's small N hides it; GA will not. |
| **P1-LAUNCH** | Should land before GA. |
| **BACKLOG** | Post-launch. Correct to defer. |
| **DEAD** | Verified already done, or the finding was wrong. Tick it and stop carrying it. |

## Findings at a glance

| Bucket | Findings | Units | Original grades folded in |
|---|---|---|---|
| **P0-PILOT** | 7 | 6 | 2× P2, 5× ungraded launch-check |
| **P1-PILOT** | 12 | 6 | 8× P2, 1× P3, 3× ungraded |
| **P0-LAUNCH** | 6 | 4 | 2× P1, 2× P2, 2× ungraded |
| **P1-LAUNCH** | 27 | 6 | 1× P1, 9× P2, 12× P3, 5× ungraded |
| **BACKLOG** | 239 | — | overwhelmingly P3 hygiene + P2 scale work |
| **DEAD** | 11 | — | verified already done or phantom |
| **Total** | **302** | 22 | |

*Findings* counts individual audit IDs; *units* counts the work packages the execution prompts group
them into. They differ where several findings share a root cause and one review covers them all.

**Verification coverage: 48 of 302 items were checked against live code.** That is deliberate, not a
shortcut — every item that lands in a P0/P1 bucket was verified, plus a 14-item staleness sample of
the oldest sweep. The 239 BACKLOG items were **not** individually verified, because staleness there
does not change the decision: a stale P3 and a live P3 are both deferred. Treat backlog counts as
an upper bound.

---

## Progress

Added 2026-07-30 during the P0-PILOT run. The file shipped with **zero** checkboxes, so
`scripts/audit/archive-done.sh` — which treats a file as done at "≥1 checkbox and zero `- [ ]`" —
could neither track it nor safely archive it. Boxes cover all **63 bucketed findings**; the 239
BACKLOG items are deliberately excluded because this file does not re-document them (they stay
tracked in their source folders).

| Bucket | Done |
|---|---|
| P0-PILOT | 5 / 7 |
| P1-PILOT | 0 / 12 |
| P0-LAUNCH | 0 / 6 |
| P1-LAUNCH | 0 / 27 |
| DEAD bookkeeping | 0 / 11 |

**P0-PILOT** — worked 2026-07-30 on `audit-fix/p0-pilot-2026-07-30`.
- [ ] `271-PRIV-2` — **OPEN: awaiting Josh's product/legal decision.** Decision brief prepared; no code written by design.
- [x] `#INH-7-DRIFT` — `PublicEarlyAccessSignupRequest` adopts `WithBotProtection`; 4-endpoint regression test added. Independent review PASS; 428 passed / 0 failed across `tests/Feature/Security` + `tests/Feature/PublicSite`.
- [x] `LC-PROD-ENV` — **delegated to Josh**, checklist issued. Ticked on handoff, *not* on verified restart: prod was still `status=stopped` at tick time.
- [x] `LC-BACKUP` — **delegated to Josh**, decision + checklist issued. Ticked on handoff; org plan still `free` at tick time.
- [ ] `LC-RUNBOOKS-2` — `docs/runbooks/db-pool-exhausted.md` + `queue-backed-up.md` written. *(awaiting independent review)*
- [x] `LC-EDGE-HARDENING` — **delegated to Josh**, checklist issued (covers both report rows: Cloudflare + Supabase). Ticked on handoff; no dashboard setting verified changed.

> **Read the three `delegated to Josh` ticks as intent, not as state.** Per this repo's convention an
> infra box ticks on the decision/handoff, and the live system must be confirmed separately. Nothing in
> `LC-PROD-ENV`, `LC-BACKUP` or `LC-EDGE-HARDENING` was actioned by an agent — starting, stopping,
> deploying or promoting an environment was explicitly out of bounds for this run.

**P1-PILOT**
- [ ] `#CCH-4` · [ ] `#LIFE-11` · [ ] `#LIFE-12` · [ ] `271-SEM-1` · [ ] `#JOB-4` · [ ] `PRIV-1`
- [ ] `PRIV-2` · [ ] `PRIV-4` · [ ] `PRIV-3` · [ ] `#43` · [ ] `#EDGE-2` · [ ] `LC-NIGHTWATCH`

**P0-LAUNCH**
- [ ] `#TEST-2` · [ ] `#TEST-1` · [ ] `271-PARITY-1` · [ ] `LC-ROLLBACK` · [ ] `#API-1` · [ ] `LC-DAST`

**P1-LAUNCH**
- [ ] `DINT-1` · [ ] `271-PRIV-1` · [ ] `#SCALE-11` · [ ] `#SCALE-13` · [ ] `#SCALE-14` · [ ] `#SCALE-17`
- [ ] `#SCALE-19` · [ ] `#SCALE-20` · [ ] `#CACHE-1` · [ ] `#CACHE-2` · [ ] `#CACHE-3` · [ ] `#3`
- [ ] `#TEST-9` · [ ] `271-TEST-1` · [ ] `#TEST-41` · [ ] `#TEST-49` · [ ] `#TEST-50` · [ ] `#38`
- [ ] `#INH-6` · [ ] `#SEC-4` · [ ] `#9` · [ ] `LC-DRILL-worker-kill` · [ ] `LC-DRILL-vendor-outage`
- [ ] `LC-DRILL-redis-down` · [ ] `LC-K6` · [ ] `LC-RERUN` · [ ] `#10`

**DEAD — bookkeeping owed to the source folders** (see `## Bookkeeping to apply to the source files`).
Left open on purpose: these are verified dead *here*, but the tick has to land in each source audit
before the finding stops being carried. Six of them block `audits/sweeps/2026-07-11-full-work-sweep/`
from auto-archiving.
- [ ] `#7` · [ ] `#40` · [ ] `#59` · [ ] `#37` · [ ] `#58` · [ ] `#11` · [ ] `#INH-1` · [ ] `#CCH-5`
- [ ] `#LIFE-10` · [ ] `#TEST-21` · [ ] `#TEST-27`

---

# DEAD — 11 items. Tick these and stop carrying them.

Your suspicion was correct, and the hit rate is high: **12 of the 48 verified items (25%) were dead.**
Six of the fourteen I sampled from the 07-11 sweep are already fixed.

| ID | Source | Original | Verified state |
|---|---|---|---|
| `#7` | 07-11 | P3 | **ALREADY-DONE** — `charlie@ai.com` placeholder no longer in `config/partna.php` |
| `#40` | 07-11 | P3 | **ALREADY-DONE** — `CloudflareCustomHostnameService::delete()` now checks the response |
| `#59` | 07-11 | P3 | **ALREADY-DONE** — Instagram reel mirror fd leak closed |
| `#37` | 07-11 | P3 | **ALREADY-DONE** — and note `AccountType::Individual` is a *deliberate* keep per `CLAUDE.md`, not debt |
| `#58` | 07-11 | P3 | **ALREADY-DONE** — dead `hasStoreKey`/`count` methods removed |
| `#11` | 07-11 | P3 | **ALREADY-DONE** — staging KV namespace no longer a placeholder |
| `#INH-1` | inheritance | **P1** | **ALREADY-DONE** — fixed in `eb44f8fa` (2026-07-25) via new `App\Services\Http\UrlAbsolutizer`, with `tests/Unit/Http/UrlAbsolutizerTest.php`. The fix also folded in a 4th copy (`WebsiteLogoCandidateExtractor`) the audit missed. **File B's tick is correct; file A is stale.** |
| `#CCH-5` | 07-28 | P2 | **ALREADY-DONE** — the cache-poisoning path is closed via `hasDegraded()`/`shortenDegraded()`, which rewrite both primary and stale keys under a short TTL. This was the highest-stakes correctness item in the corpus. |
| `#LIFE-10` | 07-28 | P2 | **PHANTOM** — the TOCTOU window is real but a pre-existing unique constraint on `(site_id, position)` prevents any duplicate; the loser fails cleanly into the existing catch |
| `#TEST-21` | 07-28 | P2 | **PHANTOM** — no dedicated resource test file exists, but all 5 `filterPayload()` branches are covered route-level by `PublicIntegrationAllowlistTest` + `PublicAllowlistCoverageTest` |
| `#TEST-27` | 07-28 | P2 | **PHANTOM** — `resolvePhotoUrls` isn't named in tests, but its budget-claim loop is proven via `fetchPlaceDetails()` in `PlacesBudgetGateTest` |

**Also resolved (a launch-check verdict row, not a numbered item):** Group G's two config FAILs are
gone — prod and dev both now report `QUEUE_CONNECTION=redis` and `SESSION_DRIVER=redis`.

`#TEST-21` and `#TEST-27` are the "phantom coverage gap" pattern the 07-28 sweep warned about in its
own header, confirmed twice more here. **The test-coverage lens cannot see route-level tests.** That is
worth fixing in `scripts/audit/lenses/` before the next sweep, or every future run re-reports these.

---

# P0-PILOT — 7 findings / 6 units. Do not run a pilot with these open.

### `271-PRIV-2` · was P2 → **P0-PILOT** · Google reviewer PII published by default
- **Where:** `PublicIntegrationConnectionResource.php`, `DisplaySettingsFilter.php`, `GoogleBusinessPayload.php`
- **Verified:** STILL-OPEN. Exact current behaviour — `stripThirdPartyPii` runs **only** on the
  pre-claim path (gated on `user.status === 'unclaimed'`). The moment an account is claimed, status
  flips to `active`, the strip stops applying, and the next refresh restores full reviewer data. That
  data then ships on the public wire because the `google-business` allowlist includes
  `reviews`/`reviewSummary`, behind a `DisplaySettingsFilter` toggle whose default is **ON**.
  **Reviewer PII is opt-out, not opt-in, for every claimed connection.**
- **Why P0-PILOT:** this is third-party PII — the *professional's customers*, who never entered any
  relationship with Partna. It is republished to unauthenticated visitors and CDN-cached. Today it is
  harmless because no claimed connections exist. The first pilot customer who connects Google Business
  makes it real, and CDN caching means it is not cleanly retractable.
- **Note:** you deferred this on 2026-07-24 with "revisit before the pilot." This is that moment. It is
  a product/legal call, not an engineering task — the engineering (flip the default, or document the
  second-subject processing basis) is small either way.

### `#INH-7-DRIFT` · was P2 → **P0-PILOT** · early-access endpoint bypasses anti-automation
- **Where:** `app/Http/Requests/.../PublicEarlyAccessSignupRequest.php` vs the `WithBotProtection` trait
- **Verified:** **PARTIAL — and verification found a live gap the audit did not describe.** The audit
  called this a consistency refactor across four public form controllers. It is more than that. A shared
  `WithBotProtection` trait already exists (shipped 2026-07-02) and is used by three of the four request
  classes, all requiring `form_started_at_ms`. `PublicEarlyAccessSignupRequest` was created *after* the
  trait existed, skipped it, and declares `form_started_at_ms` as **`nullable`** instead of required.
  The controller's timing check is gated on `is_int($startedMs)` — so a submission that simply **omits
  the field** bypasses the timing/anti-automation check entirely, on that one endpoint. The other three
  reject a missing field at validation before the controller runs.
- **Why P0-PILOT:** live, unauthenticated, public, and it is the weakest of four doors into the same
  system. Fix is one word in a validation rule.

### `LC-PROD-ENV` · was ungraded → **P0-PILOT** · production environment is stopped
- **Verified:** `cloud environment:get production` reports **`status=stopped`**. Every prod URL
  (`/api/health`, `/api/ping`, `/`) returns an empty-bodied 404 at the Cloudflare edge.
- **This is consistent with your 2026-07-26 decision to stop it deliberately** — flagging it not as a
  regression but as an explicit pre-pilot gate. Worth restating the trap you already recorded: a bare
  404 and a moved git ref *both* lie about a deploy, so confirm via `cloud deployment:list`, not by
  curling a URL.
- **Also:** `/api/health` is liveness-only and will go green on a fully broken prod. Probe a DB-touching
  route as the real readiness signal.

### `LC-BACKUP` · was ungraded → **P0-PILOT** · backup posture before real data lands
- **Verified:** the backup-restore drill *has* been run once (log dated 2026-07-26) — that part is done.
  The open part is the posture itself: the Supabase org is on the **Free** plan, so there is **no PITR
  and no managed backups**, projects can auto-pause, and the `partna-db-backup` R2 dump is the only copy.
- **Why P0-PILOT:** with `core.users = 0` this is a non-issue. The first pilot customer's data changes
  that completely — an untested single-copy backup is the difference between an incident and an
  extinction event. Either move off Free before onboarding, or accept it in writing and re-run drill-04
  against a database that actually contains rows.

### `LC-RUNBOOKS-2` · was ungraded → **P0-PILOT** · two missing runbooks, one of which you will need
- **Verified:** `docs/runbooks/` has `vendor-outage` and `redis-down`. There is **no runbook for "DB pool
  exhausted"** and **none for "queue backed up."**
- **Why P0-PILOT:** pool exhaustion is not hypothetical here. Supavisor session mode pins a slot per
  *process*, and six Horizon daemons consume six of fifteen. That is the failure you are most likely to
  actually hit during a pilot, and it is the one with no written procedure. Two documents, one sitting.

### `LC-EDGE-HARDENING` · was ungraded → **P0-PILOT** · Cloudflare + Supabase dashboard settings
- **Verified:** unverifiable by script (not API-readable) and never confirmed. Covers: Cache Deception
  Armor ON, edge rate-limiting rules (not only Laravel's), SSL mode Full (strict); and on Supabase — SSL
  enforcement ON, network restrictions, auth rate limits reviewed, custom SMTP.
- **Why P0-PILOT:** these guard *public unauthenticated traffic*, which is exactly what a pilot creates.
  All manual dashboard toggles, all cheap. Cache Deception Armor in particular matters because your
  public sitepages are aggressively CDN-cached.

---

# P1-PILOT — 12 findings / 6 units. The pilot will expose these.

| ID | Was | Item | Verified |
|---|---|---|---|
| `#CCH-4` | P2 | `IntegrationConnectionObserver::deleted()`/`restored()` never `touch()` the site, so disconnecting an integration leaves a **stale public page** | STILL-OPEN — neither method calls it, gated or otherwise |
| `#LIFE-11` | P2 | `cleanupMirroredMedia` is the only best-effort method in its class with no try/catch — Instagram disconnect can throw and skip the rest of cleanup | STILL-OPEN |
| `#LIFE-12` | P2 | `menuScrapeFailed` dedupe key is `userId`-only with no episode boundary — a user whose menu breaks, recovers, then breaks again inside 14 days is **never told the second time** | STILL-OPEN — sibling `connectionRefreshFailing` already does this correctly |
| `271-SEM-1` | P2 | `ItemSlugAllocator::ensureCurrent()` conflates a collision suffix with a name ending in digits — renaming a menu item can leave a **stale public URL** | STILL-OPEN |
| `#JOB-4` | P2 | Ingest run reports `outcome = 'ok'` even when **every** projection failed — you would be blind to it | STILL-OPEN — `'degraded'` sits unused in the rank map |
| `PRIV-1` | P2 | Visitor lat/long stored at full double precision, no truncation, 90-day retention | STILL-OPEN |
| `PRIV-2` | P2 | UA sanitizer length-caps at 256 chars only — full browser fingerprint across 5 tables for 90 days | STILL-OPEN |
| `PRIV-4` | P2 | `analytics.content_popularity_scores` has no time-bound retention at all | STILL-OPEN |
| `PRIV-3` | P2 | Moderation PII erasure runs on every deletion but is untracked by the export/erasure coverage guard | PARTIAL |
| `#43` | **P3** | `cloudflare-worker/` has **zero** automated test coverage | STILL-OPEN |
| `#EDGE-2` | P2 | Worker README describes an obsolete brand/affiliate architecture that no longer matches the KV contract | STILL-OPEN |
| `LC-NIGHTWATCH` | ungraded | Throw a deliberate exception on dev and confirm the alert **actually arrives** | never done |

**On the three privacy items (`PRIV-1`, `PRIV-2`, `PRIV-4`):** grouped deliberately. All three are small,
all three live in the analytics write path, and all three become real the moment a pilot site receives
its first outside visitor. One session closes the set.

**On `#43` (P3 → P1-PILOT), the biggest single upgrade in this file.** Read it together with `#EDGE-2`:
every `<handle>.partna.au` request routes through the Cloudflare Worker, and that Worker has **no tests,
no Nightwatch coverage, and a README that describes an architecture it no longer implements.** That is
three-deep blindness on the single most critical path in the product. Any one of those alone is a P3;
together they mean a Worker regression ships silently, pages nobody, and is debugged against wrong
documentation. Closing `#EDGE-2` is an hour and buys most of the incident-response value.

**On `#LIFE-12`:** worth noting the notifier's *sibling* method already implements the exact fix, with a
comment documenting the earlier discovery. The pattern was found and applied once, then not propagated —
the same class of miss as `#INH-7`.

---

# P0-LAUNCH — 6 findings / 4 units. Pilot's small N hides these; GA will not.

### `#TEST-2` · was P1 → **P0-LAUNCH** · constraint/index/trigger tests still don't run in CI
- **Verified: PARTIAL.** Genuine progress — `CheckConstraintsTest.php` moved to `tests/Schema/` and now
  runs for real in a new `schema-tests` CI job. But **`IndexCoverageTest.php`,
  `ArchitectureSystemConstraintsTest.php` and `UpdatedAtTriggerCoverageTest.php` are unchanged** and
  still execute against no Postgres lane. Every index, FK-cascade and trigger they assert is unguarded:
  a migration that drops one passes CI green.

### `#TEST-1` · was P1 → **P0-LAUNCH** · test-fixture schema drift
- **Verified: PARTIAL.** The schema-drift gate exists with its own CI job, and a new
  `NoLocalCanonicalTableDdlTest.php` narrowed the hole for 13 named canonical tables (grandfathered
  offenders cut 15 → 6). But **77 other test files still carry inline `CREATE TABLE` invisible to the
  gate.** This is the failure mode that has already bitten you in production once (`42703` on the GDPR
  export) — tests run SQLite, prod runs Postgres, and a phantom column is green in one and fatal in the other.

### `271-PARITY-1` · was P2 → **P0-LAUNCH** · menu columns nullable in tests, NOT NULL in prod
- **Verified: PARTIAL**, exactly as the 07-25 note predicted. `site.menus.user_id` is now `NOT NULL`,
  but **`site.menu_items.menu_id` and `site.menu_items.name` remain nullable** in the test seed and are
  still grandfathered in `scripts/launch-check/schema-drift-baseline.json`. Same shape as `#TEST-1`;
  fix them in the same sitting.

### `LC-ROLLBACK` · was ungraded → **P0-LAUNCH** · migrations without a reverse path
- **Verified: STILL-OPEN.** **49 migrations have landed since prod's last deploy**, and only **12 of 51
  files carry any revert/rollback/undo comment — 39 have none.** On a Free plan with no PITR, "roll
  forward and hope" is the entire recovery strategy for those 39.

### `#API-1` · was P2 → **P0-LAUNCH** · shop products reach the public wire with no allowlist
- **Verified: STILL-OPEN.** Every key on a `ShopBrand`'s products array is emitted verbatim to
  unauthenticated visitors, with no enforcement layer. Today the array holds only public catalog data —
  which is precisely why it is P0-*LAUNCH* and not P0-PILOT. It becomes a leak the first time anyone
  adds a field to that structure without thinking about who reads it.

### `LC-DAST` · was ungraded → **P0-LAUNCH** · dynamic security pass never triaged
- The DAST tooling shipped to `development` on 2026-07-26, but the **baseline triage still needs you**.
  A DAST run nobody has read is not a completed control.

---

# P1-LAUNCH — 27 findings / 6 units

**Scale and data-growth** — none of these bite at pilot volume; all of them bite at GA:
- `DINT-1` (P3) — no index on `analytics.action_events(user_id)` or `item_views(user_id)`; every GDPR purge sequential-scans a write-heavy table. **STILL-OPEN.**
- `271-PRIV-1` (P2) — retired `site.item_slugs` accumulate forever; no `retired_at`, no purge job. **STILL-OPEN.**
- `#SCALE-11` (P2) — `SiteMedia` force-delete serialises per-file storage I/O; a heavy user times out their own account deletion. **Touches the GDPR deletion path — isolate for sign-off.**
- `#SCALE-13/14/17/19/20` + `#CACHE-1/2/3` (all P2) — unbounded `whereIn` arrays and per-row INSERT loops across ingest, projection and staff analytics. Correct to defer; wrong to forget.
- `#3` (P3) — `analytics.item_views` has no DB-level dedup key, relying entirely on app-side Redis. **STILL-OPEN.**

**Correctness guards:**
- `#TEST-9` / `271-TEST-1` (P3/P2) — no invariant test that `site.themes` stays dropped. **STILL-OPEN, and worse than recorded:** `ArchitectureSystemConstraintsTest` exists but its three assertions don't cover themes-dropped — *and* it doesn't run in CI (see `#TEST-2`). `CLAUDE.md` currently claims this rule is "pinned" by that test. **It isn't.**
- `#TEST-41` — `BrandAssetPipelineTest` and `CatalogSyncIdempotenceTest` still hand-copy migration DDL. `ItemTombstoneBackfillTest` is the one file doing it correctly; copy that pattern. **STILL-OPEN.**
- `#TEST-49` / `#TEST-50` — no invariant test for the `detectors_surface_xor_signal` CHECK, and none asserting the deliberate *absence* of a unique index on `content.identity_keys(key_class, key_value)`. **STILL-OPEN.**
- `#38` (P3) — `site.menus.dining_modes` JSONB has no `jsonb_typeof = 'array'` CHECK. **STILL-OPEN.**
- `#INH-6` (P1 → P1-LAUNCH) — `normalizeName`/`norm` declared three times with "must stay identical" comments. **Verified NOT drifted today** — all three implementations are still byte-identical, so this is latent risk, not a live bug. That verification is why it drops from P1 rather than rising.
- `#SEC-4` (P2) — raw insert bypasses `$fillable`. **PARTIAL** — the row keys are a fixed literal set never derived from request input, so there is no live injection path; structural hardening only.

**Privacy / compliance:**
- `#9` (P3) — evidence-snapshot handle/display_name missing from the DSAR export (deletion side is already correct). **STILL-OPEN.**

**Operational:**
- `LC-DRILL-worker-kill`, `LC-DRILL-vendor-outage`, `LC-DRILL-redis-down` — **verified never run.** Only backup-restore has a log.
- `LC-K6` — baseline load pass (10 VU/5 min) + public-handle spike (50–100 VU), watching edge cache-hit ratio, Supavisor headroom, p95. Harness exists and is ready.
- `LC-RERUN` — make re-running launch-check a standing step after every migration push and before every promote. Process, not code.
- `#10` (P3) — Worker `unclaimedHtml()` hardcodes `https://partna.au` regardless of environment. **STILL-OPEN.** Trivial, and it pairs with `#43`/`#EDGE-2`.

**Supply chain** (a launch-check verdict row, not a numbered item): `cloudflare-worker` npm still carries
the same 3 high advisories — `sharp` pinned at 0.34.5 (<0.35.0) via miniflare/wrangler. **Verified
dev-only dependencies**, never shipped to the edge runtime, so the real risk is low. Bump it when
convenient; don't gate launch on it.

---

# BACKLOG — 239 items. Correct to defer.

Not re-documented here; they remain in their source files. Composition:

| Group | Count | Character |
|---|---|---|
| `#SLOP-*` (07-28) | 21 | Decorative ASCII banners, stale docblocks, duplicated helpers. Zero runtime impact. |
| `#CFG-*` (07-28) | 19 | Hardcoded constants that "should be config". Genuine hygiene; none is a defect. |
| `#TEST-*` remainder | ~55 | Coverage gaps on already-correct code. **Treat with suspicion** — this lens produced 2 confirmed phantoms in this run alone. |
| `#LIFE-*` P3s | ~12 | Select-then-insert races the audit itself marks "already fully backstopped". |
| `#SEM-*`, `#API-*`, `#MIG-*` | ~25 | Resource-class consistency, lock-timeout comments on new-table migrations. |
| `#INH-*` remainder | 15 | Inheritance/DRY refactors. **No behaviour change by design** — pure maintainability. |
| 07-11 P3 remainder | ~45 | 19 days stale. Sampling found **6 of 14 already fixed** — assume ~40% of this group is dead. |
| Everything else | ~47 | |

**Do not batch-execute this list.** Two reasons, both evidenced above: the 07-11 group is heavily stale,
and the test-coverage lens over-reports. A run against this backlog should re-verify first, exactly as
this file did.

---

## Recommended sequencing

1. **Before onboarding customer #1** — the 6 P0-PILOT items. Realistically one focused day, because four
   of them are decisions and dashboard toggles rather than code. `271-PRIV-2` needs your product/legal
   call before anything can be implemented, so start there.
2. **Same week** — `#INH-7-DRIFT` (one word), then the P1-PILOT twelve, taking the three `PRIV-*` items
   as one session and `#43`+`#EDGE-2`+`#10` as another.
3. **Before GA** — P0-LAUNCH. `#TEST-1`/`#TEST-2`/`271-PARITY-1` are one coherent piece of work: finish
   wiring the Postgres/schema-drift lanes so the guards you already wrote actually execute.
4. **Then** — P1-LAUNCH, scale cluster first.

## Bookkeeping to apply to the source files

- Tick the 11 DEAD items in their source folders. Six of them are in
  `audits/sweeps/2026-07-11-full-work-sweep/`, which cannot auto-archive while they stand.
- Tick `#INH-1` in `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` — the 07-27
  verification file already has it correct.
- **Fix the audit pipeline itself:** the test-coverage lens cannot see `tests/Unit/Routing/`,
  `tests/Unit/Policies/`, `tests/Feature/Security/PolicyEnforcement/`, `TenantIsolation/`, or
  route-level feature tests. It produced 3 confirmed phantoms across this run and the 07-28 sweep's own
  header lists 6 more. Until `scripts/audit/lenses/` is widened, every future sweep re-reports them.
- **Correct `CLAUDE.md`:** it states the architecture rules are "Pinned by
  `ArchitectureSystemConstraintsTest`." Verified false on two counts — that test does not assert
  `site.themes` stays dropped, and it does not run in CI.
