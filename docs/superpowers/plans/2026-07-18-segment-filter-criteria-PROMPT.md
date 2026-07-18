# Handoff prompt — segment filter criteria expansion

Paste everything below the line into a fresh Claude Code session in this repo.

---

Implement the segment filter criteria expansion: extend the staff user-segment filter engine with four new targeting criteria (location, tenure, Instagram followers, analytics), restructured as a criterion registry.

**Plan (source of per-task detail + complete code):** `docs/superpowers/plans/2026-07-18-segment-filter-criteria.md` — 8 tasks / 5 commits. Read a task's full section before dispatching its implementer; every step carries literal code and its verification command.

**Spec (design rationale, already approved — do NOT re-litigate):** `docs/superpowers/specs/2026-07-18-segment-filter-criteria-design.md`

## Execution mode

Use the **superpowers:subagent-driven-development** skill. Per task: fresh implementer subagent (`model: sonnet`) → independent reviewer subagent (`model: sonnet`) → fix loop → mark complete in the ledger. Keep a durable ledger at `.superpowers/sdd/progress.md`. **Set `model: sonnet` explicitly on every subagent** — they inherit the session model otherwise, and an all-Opus fan-out has blown the session limit before.

After all 8 tasks: one final whole-branch reviewer (`model: sonnet`, fresh context, sees only the diff and the plan), then the integration steps at the bottom.

**Never run `composer test` in the main session while a reviewer subagent is also running tests** — they collide.

## Workspace

Work **inline in the main checkout**, on a feature branch off `origin/development`:

```bash
git fetch origin && git log --oneline -10
git checkout -b feat/segment-filter-criteria-2026-07-18 origin/development
```

### ⚠️ The working tree is dirty with OTHER sessions' in-flight work

As of 2026-07-18 the checkout contains, none of it yours:

- `docs/deploy/production-cutover.md` — **already staged in the index**
- a mid-flight audit archive move: ~35 deletions under `audits/sweeps/` + `audits/archive/` plus 6 untracked `audits/archive/*` dirs
- 2 deleted files under `docs/legal/`
- 4 unrelated plan/spec files under `docs/superpowers/`

Therefore, **absolutely mandatory**:

- **NEVER `git add -A`, `git add .`, or `git commit -a`.** Stage explicit paths only — the plan's commit steps already list them.
- **Before every commit, run `git diff --cached --stat` and read it.** If it shows a file the plan's commit step did not name, unstage it (`git restore --staged <path>`) before committing.
- Do not `git stash`, do not clean, do not "tidy up" the tree. Leave the other sessions' work exactly as found.

## Scope guards — these are invariants, not suggestions

- **ZERO migrations.** This feature reads only existing columns and tables. No Laravel migrations (a composer guard rejects them) and **no files under `supabase/migrations/`** either. If you find yourself wanting one, you have misread the plan — stop and report.
- **No Supabase schema changes at all.** The only Supabase interaction in this work is the read-only verification in Task 8, via the Supabase MCP or tinker against dev ref `glncumufgaqcmqhzwrxm`. **NEVER** the prod ref `edplucmvkcnokyygxqsb`.
- **No config keys, no feature flags.** The feature is purely additive; new keys are inert on every existing segment.
- No changes to `StaffSegmentController`, `UserSegmentResource`, `UpdateSegmentRequest`, or any `SegmentResolver` consumer.

## Read the plan's "Verified Spec Deltas" section FIRST

Five of the spec's factual premises were checked against the repo and the live dev DB on 2026-07-18 and found stale; the plan implements corrected versions. An implementer who "helpfully" restores the spec's wording will ship broken SQL. In particular:

- Analytics reads **`analytics.site_visits` / `analytics.link_clicks`**, NOT `site_metrics_daily` (which has no writer and zero rows). Josh approved this retarget.
- The correlating column is **`user_id`**, not `professional_id` (renamed by migration `20260527030000`; the baseline DDL still shows the old name — do not trust it).
- `window_days` is capped at **90**, not 365 (raw events purge at 90 days).
- The analytics subquery **needs `GROUP BY`** — Postgres tolerates a bare `HAVING`, SQLite rejects it.
- The IG follower guard uses **`CASE WHEN <regex> THEN <cast> ELSE NULL END`**, NOT `<regex> AND <cast>` — Postgres does not guarantee `AND` operand evaluation order.

All five compile shapes were executed against both real Postgres and SQLite before the plan was written. If a test disagrees with the plan's SQL, suspect the test.

## Task-specific cautions

- **Task 2 is the highest-risk task.** Its acceptance proof is that `tests/Feature/Staff/SegmentResolverTest.php` passes with **zero edits** — verify with `git diff --stat tests/Feature/Staff/SegmentResolverTest.php` printing nothing. If an implementer wants to change a test there, it changed behavior: reject and re-do.
- **Task 4 ends deliberately red** (2 passed, 5 failed) and is **not committed**. Task 6 turns it green and commits it. Do not let an implementer "fix" it early by implementing ahead.
- **Task 8 is read-only verification against dev Postgres** and produces no commit. Its numbers are the handover evidence — capture them.

## Tooling gotchas

- Focused tests: `php artisan test --filter=X`. Full suite: bare `composer test` (`composer test -- --filter=X` does NOT work — composite script).
- Style: `vendor/bin/pint --dirty` (the plan says `php artisan pint`; `vendor/bin/pint` is the reliable path in this repo). The Pint baseline is clean as of 2026-06-11 — keep commits surgical, do not let Pint reformat unrelated files.
- This machine runs **PHP 8.4** while the project targets 8.2. If you hit a whole-suite abort (bare `pest` exit code 2 = compile fatal) in a file you did not touch, check whether it is the known pre-existing `GenericShopScraperTest` PCRE2 issue before blaming your change.
- **Never trust a "these failures are pre-existing" claim** from an implementer or reviewer. Prove it: stash the change, run the failing test on the clean tree, show the output. This has caught a real regression before.

## Reviewer brief (final whole-branch review)

Give the final reviewer, in a fresh context: the diff, the plan, and this instruction set. It must independently verify:

1. `tests/Feature/Staff/SegmentResolverTest.php` has **28 tests** and the 6 original ones are textually unchanged from `origin/development`.
2. No file exists under `supabase/migrations/` that is new on this branch, and no Laravel migration was created.
3. Every commit's file list contains only segments-related paths — no `audits/`, no `docs/deploy/`, no `docs/legal/`.
4. `AnalyticsCriterion` references `user_id` (never `professional_id`), contains `GROUP BY`, and reads the raw event tables.
5. `IgFollowersCriterion::followersExpression('pgsql')` places the digit regex before `::bigint` and uses `CASE`.
6. The metric allowlist is a hardcoded map and no user input reaches SQL by interpolation.
7. Task 8's dev-Postgres counts were actually run and are recorded — specifically that a `max`-only analytics count exceeds the matching `min: 1` count (proving the zero-row semantics).

## Integration — push IS authorized this time

Josh has authorized the push for this work. Proceed **only** when all three gates pass:

- **Gate A:** `composer test` green on the full suite.
- **Gate B:** final reviewer verdict is PASS (not "PASS with minor notes" — resolve notes first).
- **Gate C:** every commit's `git diff --cached --stat` was verified to contain only intended paths.

If any gate fails, **stop and report to Josh** — do not push.

**Be aware what this deploys:** per `CLAUDE.md`, the `development` Laravel Cloud env currently serves **both** `dev-api.partna.au` **and** `api.partna.au`, backed by the dev Supabase. Pushing `development` is push-to-deploy and updates the live API domain. There is no separate production step gating it afterwards.

Then:

```bash
git checkout development
git merge --ff-only feat/segment-filter-criteria-2026-07-18
git log --oneline origin/development..HEAD          # expect exactly 5 commits
git push origin development
git branch -d feat/segment-filter-criteria-2026-07-18
```

(If `--ff-only` refuses because `origin/development` moved, rebase the feature branch on the new tip, re-run `composer test`, then retry. Do not force-push anything.)

**No Supabase push is needed** — this feature has no migrations. Do not run `supabase db push`.

## After the deploy

1. Watch the build: `cloud deployment:list development` (CLI at `~/.composer/vendor/bin/cloud`).
2. Pull logs once it succeeds: `cloud env:logs partna development --minutes 10` — confirm no new exceptions.
3. Check Nightwatch for new exceptions or slow routes on the staff segment endpoints.
4. Report to Josh: the 5 commits, Task 8's per-criterion dev-Postgres counts, deploy status, and anything surprising.

Delete the feature branch locally and remotely once merged (it was never pushed, so local `-d` is enough).
