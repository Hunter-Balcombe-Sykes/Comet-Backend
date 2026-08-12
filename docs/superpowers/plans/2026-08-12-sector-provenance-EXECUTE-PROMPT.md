# Execute prompt — sector provenance precedence

Hand the block below to a fresh session. It sets up an isolated worktree, executes the plan
task-by-task, merges to `development` and pushes.

Runs concurrently with content-pool **slice 1** (media pool live). File overlap between the two is
zero — verified 2026-08-12. See §"Concurrency" in the prompt for the two semantic seams.

---

```
Implement docs/superpowers/plans/2026-08-12-sector-provenance-precedence.md in an isolated git
worktree, then merge it to development and push.

Design spec (read it before Task 1, at least §1, §3 and §4):
docs/superpowers/specs/2026-08-11-sector-provenance-precedence-design.md

REQUIRED SKILL: use superpowers:subagent-driven-development to execute the plan — a fresh
subagent per task, with a review between tasks. 14 tasks. Tasks 1-6 are independent leaves;
7-11 wire them into call sites; 12-14 pin and verify.

## Hard rules — violating any of these silently corrupts the run

- NEVER run `git stash`. Another session shares this repository. Pass this rule to every
  subagent you dispatch, in their prompt text.
- NEVER touch the main checkout from inside the worktree. Not to "fix" a boot failure, not to
  satisfy an autoloader, not for anything. If a subagent proposes it, reject the task.
- NEVER create a Laravel migration file. The Composer guard rejects them and this change needs
  no schema at all.
- NEVER read `account_type` outside `AccountCapabilities`. The sanctioned discriminator is
  `AccountCapabilities::for($user)->google_business_full_sync`. Do not call `$user->isBusiness()`
  in new code.
- NEVER modify `KEYWORD_SECTORS` or `fromGoogleCategory()`. The Google classification path must
  not regress; the plan works around its known misclassifications at a different tier.
- `composer test --filter` is BROKEN in this repo — it reads like "no tests matched". Always run
  Pest directly with a file path: `./vendor/bin/pest tests/path/File.php`.
- `composer pint` / `php artisan pint` is BROKEN. Use `./vendor/bin/pint <paths>`.
- Tests run SQLite; production is Postgres. `users_sector_check` and `users_sector_source_check`
  do not exist in SQLite, so a typo'd slug passes every test and throws 23514 in production.
  Tasks 3, 4 and 12 add the pins for this — do not weaken them.

## Phase 1 — worktree setup

Do NOT use EnterWorktree's fresh mode. This repo's `origin/HEAD` is `production`, so fresh mode
bases off the wrong branch AND silently renames the branch. Use these commands exactly:

    git -C "/Users/joshuahunter/Herd/Side Street/backend" fetch origin development
    git -C "/Users/joshuahunter/Herd/Side Street/backend" worktree add \
      ".claude/worktrees/sector-provenance" origin/development \
      -b feat/sector-provenance-precedence

Then make the worktree self-sufficient. `vendor/` MUST be a real copy, never a symlink — PHP
resolves symlinks, so Composer's `$baseDir` would point back at the main checkout and every
edit you make would be invisible to the tests, which then pass against unchanged code.

    # composer.lock parity check first: if this prints anything, run `composer install`
    # in the worktree instead of copying vendor.
    git -C "/Users/joshuahunter/Herd/Side Street/backend" diff --stat HEAD origin/development \
      -- composer.lock composer.json

    cp -R "/Users/joshuahunter/Herd/Side Street/backend/vendor" \
          "/Users/joshuahunter/Herd/Side Street/backend/.claude/worktrees/sector-provenance/vendor"
    cp "/Users/joshuahunter/Herd/Side Street/backend/.env" \
       "/Users/joshuahunter/Herd/Side Street/backend/.claude/worktrees/sector-provenance/.env"

Copy `.env`, do not symlink it.

VERIFY before writing a single line of code — from inside the worktree:

    php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("App\\Services\\Profile\\SectorTaxonomy"))->getFileName(), PHP_EOL;'

The path MUST contain `.claude/worktrees/sector-provenance`. If it points at the main checkout,
the vendor copy is wrong — stop and fix it. A green suite means nothing until this passes.

Then confirm the base is right:

    git rev-parse HEAD
    git rev-parse origin/development

These must match. If they don't, you are on a stale base — fix it before starting.

Do NOT run `composer dump-autoload -o` from the main checkout at any point. Other worktrees
exist, and the optimized classmap will pin `App\...` classes into one of them.

## Phase 2 — execute the plan

Work the 14 tasks in order via superpowers:subagent-driven-development. Each task is TDD:
write the failing test, run it and see it fail for the stated reason, implement, run it green,
pint, commit. Do not skip the "see it fail" step — several tests in this plan pin invariants
that already hold, and the plan says explicitly where to temporarily break something to prove
the test can fail.

Task-specific warnings the plan states but that are easy to skim past:

- Task 4 is the one that matters most. Tier 1 MUST delegate to the unmodified
  `fromInstagramCategory()`. Three revisions of the design spec proposed reimplementing it as
  "all exact matches, then all keyword matches", and that inverts category primacy —
  "Restaurant, Digital Creator" resolves to content-creator, which silently kills
  `can_use_menu` on a business account. Delegating makes the regression unrepresentable. If a
  subagent inlines it, reject the task.
- Task 8 INVERTS an existing assertion (`IdentitySyncTest.php:303-322`). That is intentional —
  it currently pins the behaviour being removed. It also needs `setupSectionsTables()` added to
  that file's `beforeEach`.
- Task 8 and 10 both touch the site AFTER the transaction commits, on the caller's `$user`,
  never on the locked `$fresh` row — `$fresh->site` is an unloaded relation and
  `preventLazyLoading` throws outside production.
- Task 10's `$user->refresh()` is not optional. `InstagramConnectionSeeder:230` hands the same
  instance to link routing, which reads `$user->sector`.
- `IdentitySyncConcurrencyTest` must stay green UNMODIFIED. It is the LIFE-107 pin; editing it
  is the signal that lock semantics moved.

If any task's premise does not match the code you find — a line number is off, a method has a
different signature — STOP and report rather than improvising. The plan's line references were
verified on 2026-08-12 against `origin/development`; a mismatch means the base moved.

## Phase 3 — verification gate

All of these must pass before you merge anything:

    ./vendor/bin/pest tests/Unit/Profile/ tests/Feature/Profile/ tests/Feature/Platforms/ tests/Feature/Onboarding/
    ./vendor/bin/pest tests/Feature/Routing/ tests/Unit/Routing/
    COMPOSER_PROCESS_TIMEOUT=0 composer test
    ./vendor/bin/phpstan analyse --memory-limit=2G

`COMPOSER_PROCESS_TIMEOUT=0` is required — the suite exceeds Composer's default timeout and
dies without it.

The schema lane needs shell `DB_*` variables pointing at Postgres and does NOT run under
`composer test`:

    composer test:schema

If you cannot run the schema lane, say so explicitly in your final report. Do not claim it
passed and do not silently skip it.

## Phase 4 — merge and push

You CANNOT merge from inside the worktree; a worktree session is refused access to the main
checkout. Sequence:

1. Commit everything in the worktree.
2. `ExitWorktree action:keep` (keep — do not discard).
3. From the main checkout: `git fetch origin development` and fast-forward local `development`.
4. `git merge --no-ff feat/sector-provenance-precedence`
5. RE-RUN THE SUITE ON THE MERGE: `COMPOSER_PROCESS_TIMEOUT=0 composer test`. This is the step
   that catches semantic conflicts — content-pool slice 1 may have landed while you worked, and
   two branches that each pass alone can fail together. Do not skip this because both sides
   were green.
6. `git push origin development`

Expect 1-3 fetch+rebase cycles at push — sibling sessions land on `development` continuously.
If the push is rejected, fetch, rebase, re-run the suite, push again.

This pushes `development` only. It does NOT deploy production; that is a separate
`git push origin development:production`. Do not do that.

## Phase 5 — cleanup

    git worktree remove .claude/worktrees/sector-provenance
    git branch -d feat/sector-provenance-precedence

Leaving the worktree in place risks poisoning Composer's optimized classmap for the next
session.

## Concurrency with content-pool slice 1

Slice 1 (media pool live) may be in flight in a sibling session. File overlap is zero — it
touches `ProjectionWriter`, `PoolResolver`, `PoolRegistry`, `InstagramMediaProjector`,
`InstagramConnectionSeeder`'s photo mirroring and `content.media_assets`; none of those are in
this plan. Two seams to watch, both semantic rather than textual:

1. Slice 1 rewrites `InstagramConnectionSeeder`'s mirroring (around `:90-130`). Task 10's
   `$user->refresh()` depends on that file resolving `$user` at `:227` and consuming it at
   `:230`. If slice 1 has moved that, git merges cleanly and the assumption quietly breaks —
   re-read those lines after the merge and confirm.
2. Slice 1 will likely change `content.items` / `content.media_assets` DDL in `tests/Pest.php`.
   Task 6's `FoodContentProbeTest` inserts into `content.items`. A new NOT NULL column breaks
   that fixture — a test-fixture fix, not a design problem.

Before merging, run `git worktree list` and check whether a sibling session holds any file this
plan touches. If one does, stand down on that file and report rather than racing it.

## Final report

Tell me:
- Which tasks completed, and any that were rejected in review and why.
- The merge commit SHA and confirmation the suite was green ON THE MERGE (not just on the branch).
- Whether the schema lane ran, and its result.
- Anything in the plan whose premise did not match the code.
- The four post-merge obligations from the plan's last section, restated — they belong to other
  work and someone needs to carry them.
```
