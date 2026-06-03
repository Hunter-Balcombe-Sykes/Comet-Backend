# Async Analytics Ingest — Execution Prompt

**Hand this prompt to a fresh Claude Code session to implement, review, and ship the async-analytics-ingest plan.**

Three phases, gated:
1. **Implement** the plan with **Sonnet** (subagent-driven, TDD, per-task review).
2. **Review** the full result with **Opus** (top-level correctness + spec-compliance pass).
3. **Push** to `development` (git → dev deploy) and Supabase dev — only after review is green **and** the human says go.

---

## Inputs you will read

| File | Purpose |
|------|---------|
| `docs/superpowers/plans/2026-05-30-async-analytics-ingest.md` | The plan you execute. 9 tasks. **Read fully once at start.** Its "Code-grounded deviations" and "Ground-truth reference" sections are binding. |
| `docs/superpowers/specs/2026-05-30-async-analytics-ingest-design.md` | The design spec (rev. 2) — read once for the "why" (idempotency, fail-open, contract preservation). |

## What you are building (one paragraph)

Move the three public analytics ingest endpoints (`pageview` / `click` / `section-seen`) off the visitor request path behind two swappable seams — `AnalyticsIngestor` (transport) and `AnalyticsEventWriter` (storage) — with an immutable `AnalyticsEvent` DTO flowing through both. The controller mints the row PK, stamps request-time fields, does a Redis-only dedup, and hands off to the ingestor; a queued job writes via `insertOrIgnore` (PK-idempotent). `local`/`testing` uses an inline `SyncIngestor` so the suite stays synchronous. **No schema change. No new queue connection. No Horizon change.**

---

## Pre-flight (before dispatching any subagent)

```bash
# 1. Land on a clean, current development base (this work ships to the dev environment).
git fetch origin
git checkout development && git pull origin development

# 2. Branch. (The current working branch feat/design-kit-email-branding is UNRELATED — do not build here.)
git checkout -b feat/async-analytics-ingest

# 3. Baseline green BEFORE you touch anything.
composer test

# 4. Fresh optimized autoload (avoids the worktree classmap-poisoning gotcha).
composer dump-autoload -o
```

If `composer test` is not green on a fresh `development`, **STOP and escalate** — do not build on a broken baseline.

---

## Phase 1 — Implement (Sonnet)

**Skill (required):** `superpowers:subagent-driven-development`.
**Model:** dispatch every implementer and per-task reviewer subagent with **`model: sonnet`**.

For each of the 9 tasks, in order:

1. Extract the task's full text from the plan (code blocks, exact paths, run commands).
2. Dispatch a **Sonnet implementer** subagent with the complete task text + context (which classes already landed). It follows the plan's `Step 1 write failing test → run (fail) → implement → run (pass) → commit` loop exactly.
3. On implementer `DONE`, dispatch a **Sonnet spec-compliance reviewer**: does the diff match the task — nothing missing, nothing extra?
4. If gaps: re-dispatch the implementer with the gap list. Repeat until clean.
5. Mark the task done; move on. Execute continuously — only pause if `BLOCKED`.

Commit per task using the messages in the plan. End every commit message with the required `Co-Authored-By` trailer.

### NON-NEGOTIABLE decisions (reject any subagent that reverts these)

These come from reading the real code. They are binding — see the plan's "Code-grounded deviations" for the full rationale.

| Decision | Why it's locked |
|----------|-----------------|
| Dispatch to the **existing `analytics` queue on the default `redis` connection** (`->onQueue('analytics')`). **Do NOT create a `redis_analytics` connection. Do NOT edit `config/horizon.php`.** | `supervisor-analytics` already consumes `['analytics','images']`; our job is the first producer. New infra is redundant. |
| Minted UUID (`Str::orderedUuid()`) **is the row PK**; writer uses `insertOrIgnore`. | At-least-once idempotency — a retry no-ops instead of double-counting. Never `->save()`/`::create()` with a DB-generated id. |
| Live column is **`user_id`** on every analytics table. | Renamed from `professional_id` by mig `20260527030000`. |
| `ClickRequest.block_id` becomes `['required','uuid']` — **drop the `Rule::exists`**. | Block validation moved to the writer; the exists-rule would 422 instead of optimistic-accept. |
| Block validation lives in `PostgresEventWriter` and **still enforces `block->site_id === event->siteId`**. | The cross-site IDOR defence must survive at the writer even though the controller no longer checks. |
| Dedup is **Redis-only** (`Cache::add` SETNX) + fail-open, keyed on `visitor_id ?? session_id`. | Accepted weaker-than-DB trade-off (deviation #6) — do not try to "restore parity" with a DB query. |
| `local`/`testing` binds `SyncIngestor`; prod binds `QueuedIngestor` (lossy fail-open, **no inline fallback**). | Inline fallback would reintroduce the request-path DB coupling this whole change removes. |
| Request-time fields (`occurred_at`, `country_code`, `device_type`, `ip_hash`, `user_agent`, `referrer`) captured in the controller into the DTO. | The worker has no request object; anything not captured is lost. Pageview referrer raw; click/section sanitized. |

### Contract-preservation guardrails (the HTTP contract must not drift except as enumerated)

The plan's deviation #4 lists six block-rejection cases that change from a synchronous 4xx to **201 + writer-drop**. These are intended. Two existing tests assert old statuses and **must be updated in Task 8**:

- `tests/Feature/Analytics/SectionSeenIngestTest.php` — cross-site `block_id` case: **404 → 201** (assert 0 rows persisted).
- `tests/Feature/Analytics/TopSectionsExpandedTypesTest.php` — untrackable-block case: **422 → 201** (assert the writer drops it).

**Before finishing Phase 1, grep for any other test asserting the old behaviour and update or flag it:**
```bash
grep -rnE "assertStatus\((404|422)\)" tests/Feature/Analytics tests/Feature/Security | \
  grep -iE "block|click|section|trackable"
```
Any hit that corresponds to a now-201 path must be updated (status → 201, keep the "0 rows / dropped" assertion) — the security invariant is *preserved at the writer*, only the status moves. If a hit is genuinely unrelated, leave it. Surface every change in your Phase 1 summary.

### Phase 1 exit criteria

```bash
composer test          # full suite green
vendor/bin/pint app/Services/Analytics app/Jobs/Analytics \
  app/Http/Controllers/Api/PublicSite/AnalyticsController.php \
  app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php \
  app/Providers/AppServiceProvider.php config/partna.php   # changed files ONLY — never repo-wide pint
```

Forbidden-pattern sweep (must return **zero** matches):
```bash
# No new queue connection, no horizon edit, no migration, no synchronous persistence in the controller.
git diff origin/development --stat -- config/horizon.php supabase/migrations/ | grep . && echo "VIOLATION: horizon/migration touched"
grep -nE "redis_analytics" config/queue.php config/horizon.php app/ && echo "VIOLATION: redis_analytics introduced"
grep -nE "->save\(\)|::create\(|->insert\(|->insertOrIgnore\(" app/Http/Controllers/Api/PublicSite/AnalyticsController.php && echo "VIOLATION: controller persists directly"
grep -nE "professional_id" app/Services/Analytics app/Jobs/Analytics && echo "VIOLATION: stale column name"
```

---

## Phase 2 — Review (Opus)

After Phase 1 exits green, run a top-level review. **Model: Opus** — either switch this session to Opus, or dispatch a single reviewer subagent with **`model: opus`**. Use `superpowers:requesting-code-review` to frame it.

The reviewer reads the **whole diff** (`git diff origin/development`) plus the plan + spec, and confirms:

1. **Idempotency is real** — `insertOrIgnore` on the minted PK across all three tables; running a job twice yields one row (there is a test; verify it actually exercises the same PK).
2. **Seams are clean** — controller depends only on `AnalyticsIngestor` + `AnalyticsDedupGuard`; job depends only on `AnalyticsEventWriter`; the DTO carries every request-derived field. No leaked Eloquent models through the seams.
3. **Contract preserved** — 422 IDOR branch intact; bot path is `200` with no `*_id`; pageview keeps no-bot-filter + no-dedup; the six 4xx→201 changes are exactly those enumerated and the writer drops (never persists) every bad-block case including cross-site.
4. **Fail-open paths** — dedup Redis fault → novel; dispatch fault → log + swallow, never inline-write, never throw; cache-bump fault → swallowed.
5. **Per-type column mapping** — `block_id`→`link_block_id` (click) vs `block_id` (section); clicks omit `country_code`/`device_type`; `created_at` set explicitly.
6. **No scope creep** — no Horizon edit, no migration, no `redis_analytics`, no read-path/rollup changes.
7. **Suite + style** — `composer test` green; pint clean on changed files only.

Reviewer returns **APPROVED** or a findings list. If findings: hand them back to a **Sonnet** implementer subagent to fix (re-running the relevant tests), then re-review. Loop until APPROVED.

> Optional extra signal: the human may also run `/code-review` for an automated pass. Not a substitute for the Opus review above.

**Do not proceed to Phase 3 until the Opus review is APPROVED.**

---

## Phase 3 — Push to development + Supabase

> **GATE:** Pushing is outward-facing. **STOP and get explicit human confirmation** before running anything in this phase. Show the human the `git diff origin/development --stat` and the Supabase dry-run output first.

### 3a. Git → development (deploys dev-api.partna.au)

```bash
composer test                      # final green check
git push -u origin feat/async-analytics-ingest
gh pr create --base development --head feat/async-analytics-ingest \
  --title "feat(analytics): async ingest behind swappable seams" \
  --body "Implements docs/superpowers/plans/2026-05-30-async-analytics-ingest.md. No schema change; reuses existing analytics queue. See plan deviations §1–6 (notably the six 4xx→201 block-rejection changes)."
```

Then, **on the human's explicit go**, merge into `development` (squash) so the dev environment deploys:
```bash
gh pr merge --squash --delete-branch   # only after human confirms
```

### 3b. Supabase dev — IMPORTANT: this change has **no migrations**

This plan ships **zero** files under `supabase/migrations/`. The Supabase push is therefore a **verification step, expected to be a no-op** — included for workflow completeness and to catch the case where the Opus review introduced a migration.

The `supabase link` step is interactive — **ask the human to run it** with the `!` prefix:
```
! supabase link --project-ref glncumufgaqcmqhzwrxm     # dev project
```
Then:
```bash
supabase db push --dry-run         # EXPECT: "Remote database is up to date" / no migrations to apply
```
- If the dry-run shows **nothing to apply** (expected): done — no `db push` needed. State this plainly.
- If the dry-run shows pending migrations (only if review added one): show the output to the human, get confirmation, then `supabase db push`.

### 3c. Post-deploy smoke (dev)

```bash
cloud env:logs partna development --minutes 10        # confirm no new exceptions after deploy
```
Optionally confirm the `analytics` queue is draining in Horizon (it should already have a worker — no change was made there). A quick check: hit a dev pageview endpoint and confirm a `RecordAnalyticsEventJob` processes without error in the logs.

---

## Done criteria

- All 9 tasks committed; `composer test` green; pint clean on changed files.
- Forbidden-pattern sweep clean; the only contract changes are the six enumerated 4xx→201 cases.
- Opus review APPROVED.
- PR merged into `development` (on human go); dev deploy healthy in `cloud env:logs`.
- Supabase dev dry-run confirmed no-op (or any review-introduced migration pushed on confirmation).

## Report back

Summarize: tasks landed, every test you changed and why (especially the 4xx→201 ones), the forbidden-sweep result, the Opus review verdict + any fixes, the merge status, and the Supabase dry-run result (expected: nothing to apply).
