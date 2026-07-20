# Handoff prompt — segment analytics `min: 0` semantics

Paste everything below the line into a fresh Claude Code session in this repo.

---

Fix a known, deliberately-deferred semantic bug in the staff segment filter engine: `analytics.min = 0` silently behaves like `min = 1`, excluding zero-activity users.

This is a small, well-understood change — **one criterion class, one shared trait helper, and tests.** Do not turn it into a refactor.

## Background — what shipped and why this was deferred

The segment filter criteria expansion shipped to `development` on 2026-07-18 (merge `49583207`). `App\Services\Segments\Criteria\SegmentCriteria::all()` is now a registry of 11 criterion classes; each owns BOTH its validation rules and its query compilation. `SegmentResolver::dynamicQuery()` iterates it.

The final whole-branch review found this issue. Josh chose to ship as-is and fix in a follow-up — **this prompt is that follow-up.** The behaviour is verbatim from the original plan's SQL, so it is a spec-level decision, not an implementation defect. Nobody made a mistake implementing it.

## The bug

In `app/Services/Segments/Criteria/AnalyticsCriterion.php`:

- `rules()` permits `min: 0` (`'filters.analytics.min' => ['sometimes','nullable','integer','min:0']`)
- `isActive()` counts `0` as a set bound (`isset($config['min'])` is true for `0`)
- `apply()` branches on `if ($min !== null)` — and `0 !== null`, so it takes the `EXISTS` path

The `EXISTS` path needs `GROUP BY m.user_id` to form a group. **A user with no rows in the window forms no group at all**, so `HAVING COUNT(*) >= 0` is never evaluated and the user is excluded. `min: 0` — which reads as "no minimum, match everyone" — silently means "must have at least 1 event."

**Measured on live dev Postgres (`glncumufgaqcmqhzwrxm`) on 2026-07-18:** `min:0` → 9 users, `min:1` → 9 users, out of 21 live users. 12 zero-activity users silently dropped.

Worse, `min: 0, max: N` — intended as "target 0-N low-traffic users", the feature's headline use case — currently takes the `EXISTS` branch and drops exactly the users it most wants to find. On dev, `min:0, max:5` returns **3 users where it should return 14**. And `min: 0, max: 0` returns the **empty set for everyone, always**: a `GROUP BY` group only exists when there is at least one row, so `COUNT(*) >= 0 AND COUNT(*) <= 0` is unsatisfiable by construction. Targeting "users with no activity at all" is currently impossible.

By contrast `TenureCriterion` treats `tenure_days_min = 0` as a genuine no-op (`created_at <= now()` matches everyone). So the registry currently disagrees with itself about what a zero minimum means.

## The decision (already made by Josh — do not re-litigate)

**Treat `min === 0` as "no lower bound."**

The rejected alternatives, for context only: forbidding `min: 0` in validation (rejects the legitimate `min:0, max:N` intent), and documenting the current behaviour as-is (leaves the registry inconsistent).

### Target behaviour — this table is your spec

| `filters.analytics` | validation | `isActive()` | query branch | zero-activity users | change? |
|---|---|---|---|---|---|
| `min:1` | pass | true | `EXISTS … >= 1` | excluded | unchanged |
| `min:1, max:5` | pass | true | `EXISTS … >= 1 AND <= 5` | excluded | unchanged |
| `max:5` | pass | true | `NOT EXISTS … > 5` | included | unchanged |
| `min:0, max:5` | pass | true | `NOT EXISTS … > 5` | **included** | **CHANGED** (dev: 3 → 14) |
| `min:0, max:0` | pass | true | `NOT EXISTS … > 0` | **included — yields exactly the zero-activity users** | **CHANGED** (dev: 0 → 11; was unsatisfiable) |
| `min:0` alone | **422** | n/a | n/a | n/a | **CHANGED** (was: saved, silently excluded zeros) |

### Why `min: 0` alone must be a 422, not an inert no-op

This is the subtlety that makes the change non-trivial — think it through before coding.

If `min: 0` means "no lower bound", then `min: 0` alone means "no bounds at all". If you simply make `isActive()` return false for it, the criterion goes inert — and per `SegmentCriterion`'s own contract, **a segment where no criterion is active resolves to the EMPTY dynamic set.** So a segment whose only filter is `analytics{metric, window_days, min: 0}` would silently resolve to nobody. The operator asked for "≥0 visits" and got zero users. That is a worse failure than the bug you are fixing.

Rejecting it at validation gives a clear, actionable error instead. The existing rule already says *"analytics requires at least one of min or max"* — it just needs to stop counting `0` as one.

## Scope

**In scope:**
- `app/Services/Segments/Criteria/AnalyticsCriterion.php` — `apply()` and `isActive()`
- `app/Services/Segments/Criteria/ResolvesFilterValues.php` — the shared `requiresABound()` helper (see the coupling warning below)
- `tests/Feature/Staff/SegmentResolverTest.php` — append resolver tests
- `tests/Feature/Staff/SegmentFilterValidationTest.php` — append validation tests
- `docs/api.md` — the segments section documents the zero-row semantics; update it to match

**Out of scope — do not touch:** `SegmentResolver.php`, `StaffSegmentController.php`, `UserSegmentResource.php`, `UpdateSegmentRequest.php`, `StoreSegmentRequest.php`, any other criterion class, anything under `supabase/migrations/`.

### ⚠️ `requiresABound()` is SHARED — check before you change it

`requiresABound()` now lives on the `ResolvesFilterValues` trait and is used by **both** `AnalyticsCriterion` and `IgFollowersCriterion` (it was deduped there by the final review). Changing it to treat `0` as not-a-bound therefore changes `ig_followers` behaviour too.

**Do not blindly apply it to both.** Resolve the IG question first (next section), then decide whether the shared helper changes, or whether analytics needs its own variant. Either is acceptable — say which you chose and why.

## First task: investigate `ig_followers.min = 0` before changing anything shared

`IgFollowersCriterion` uses a **different query shape** — `EXISTS` on `site.platform_connections` with a `CASE WHEN <digit regex> THEN <cast> ELSE NULL END` expression, not `GROUP BY`/`HAVING`. So `min: 0` there means `followers >= 0`, which behaves differently: it still excludes users with no IG connection, and excludes NULL/non-numeric payloads (NULL comparisons are false).

That may be perfectly correct, or it may be the same trap wearing a different shape. **Determine which, empirically, before you touch the shared helper.**

Report what `ig_followers{min: 0}` actually does today, with real numbers. Fix it only if it is genuinely inconsistent with the rule you are establishing — and say so explicitly either way. Do not assume.

## Verification — SQLite green is not enough

Tests run on in-memory SQLite; production is PostgreSQL, and the two drivers diverge in exactly this area (SQLite rejects a bare `HAVING`; Postgres tolerates it). **A green suite proves less than it appears to.**

You must verify against real dev Postgres. **Local `php artisan tinker` cannot reach any database** — the local `.env` points at `db.bdogjhmxrvpaxqlwcbpk.supabase.co`, a Supabase ref that no longer exists. Two paths that do work:

1. **Preferred — run on the deployed env:** `~/.composer/vendor/bin/cloud tinker development --code='…'`. This reaches the real DB and exercises the deployed code end-to-end.
2. Render SQL offline with `->toSql()` / `->getBindings()` (needs no live connection), then execute it against dev ref `glncumufgaqcmqhzwrxm` via the Supabase MCP `execute_sql`.

**Read-only queries only. Create no segments. NEVER touch prod ref `edplucmvkcnokyygxqsb`.**

Baseline numbers, **measured on dev Postgres 2026-07-18** (21 live users). Note `analytics.site_visits` receives live writes, so counts drift by a user or two between runs — that is data movement, not a regression. Re-measure the "before" column yourself rather than trusting these absolutely; they are here so a wildly different result tells you something is wrong.

| probe (`analytics{visits, 30d, …}`) | before | after (expected) |
|---|---|---|
| `min:1` | 10 | 10 — unchanged |
| `max:5` | 14 | 14 — unchanged |
| `min:0` | 10 | **422 at validation** |
| `min:0, max:5` | **3** | **14** — must equal the `max:5` row |
| `min:0, max:0` | **0** | **11** — the zero-activity users |

Two of these deserve a second look, because they show the bug is worse than "excludes zeros":

- **`min:0, max:5` returns 3, not 9.** Today it compiles to `EXISTS … >= 0 AND <= 5`, which means "has a group, and that group has 1-5 visits" — so it finds users with 1-5 visits and drops every true zero. The operator asked for "0 to 5 visits" and got 3 of the 14 users they meant. That is the headline low-traffic case failing by a factor of nearly 5.
- **`min:0, max:0` returns 0 — always, for everyone, forever.** A `GROUP BY` group only exists when there is at least one row, so `COUNT(*)` is never 0 for a group that exists. The predicate `>= 0 AND <= 0` is unsatisfiable by construction. It is currently impossible to target "users with no activity at all" via min/max, which is arguably the single most useful segment in the set.

**`min:0, max:0` is your strongest proof.** If it still returns 0 after your change, the fix did not land — and if it returns 11 while `max:5` still returns 14, both branches are behaving.

## Tooling gotchas

- Focused tests: `php artisan test --filter=X`. Full suite: bare `composer test` (`composer test -- --filter=X` does NOT work — composite script).
- Style: `vendor/bin/pint --dirty`. **NOT** `php artisan pint` — it is not registered in this repo.
- **Full suite baseline on `development` @ `49583207` is 4094 passed / 0 failed / 132 skipped.** It is fully green — any failure is yours. (There is a long-standing "1 warning / 1 risky" that predates all of this and is nobody's yet.)
- Never accept a "that failure is pre-existing" claim without proving it: stash the change, run the failing test on the clean tree, show the output.
- This machine runs PHP 8.4 while the project targets 8.2.

## Workspace

Use a worktree — **a plain `git worktree add`, NOT the harness `EnterWorktree`.** The harness places worktrees under `.claude/worktrees/` with symlinked `vendor/` and `.env`, which breaks the Feature suite and poisons Composer's optimized classmap in this repo. The working convention is a sibling directory:

```bash
git fetch origin && git log --oneline -5 origin/development
git worktree add "../backend-wt/analytics-min-zero" -b fix/analytics-min-zero-semantics origin/development
cd "../backend-wt/analytics-min-zero"
cp "../../backend/.env" .env      # adjust if your checkout path differs
composer install && composer dump-autoload -o
```

Base off `origin/development`, never `production` (`production` is the repo's default branch and is ~530 commits behind — do not branch from it).

**Shared repo — another dev works here.** Before every commit run `git diff --cached --stat` and read it; stage explicit paths only. Never `git add -A`, `git add .`, or `git commit -a`. Do not `git stash` — a foreign stash entry from another session lives in this repo and must not be disturbed.

## Definition of done

1. Every row of the target-behaviour table is implemented **and has a test** — resolver tests for the query branches, validation tests for the 422.
2. The `min:0, max:0` case returns the zero-activity users on **real dev Postgres**, with the number recorded.
3. The `ig_followers.min = 0` question is answered with evidence, and either fixed or explicitly justified as correct-as-is.
4. `docs/api.md`'s segments section matches the new behaviour.
5. `composer test` is green — 4094+ passed, 0 failed.
6. No new files under `supabase/migrations/`; no Laravel migrations.
7. Pre-existing tests unaltered — append only.

## When done

**Do not push without asking Josh.** Present: the diff summary, the before/after dev-Postgres numbers for all five probes above, your `ig_followers` finding, and confirmation the suite is green. Josh decides on the merge.

Then update the memory note at `~/.claude/projects/-Users-joshuahunter-Herd-Side-Street-backend/memory/project_segment_analytics_min_zero.md` — it currently describes this as deferred/unfixed. Mark it resolved (or delete it and drop the `MEMORY.md` index line) so a future session does not re-investigate a closed issue.
