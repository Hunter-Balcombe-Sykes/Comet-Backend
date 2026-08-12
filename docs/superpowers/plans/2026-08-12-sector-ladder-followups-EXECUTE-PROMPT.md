# Execute prompt — sector ladder follow-ups

Three small, independent follow-ups left behind by the sector-provenance ladder
(`42f715fbe`, merged 2026-08-12). Hand the block below to a fresh session.

They share a branch because they are all small and all fall out of the same merge. They do not
depend on each other — a reviewer can reject any one without blocking the others.

**Runs in parallel with three content-pool sessions** — slice 1b, slice 3 (services) and slice 5
(shop) — and with connection-pooling work in the main checkout. Code overlap with all four is zero;
the one genuine contention is a shared planning document, and the merge phase must not touch the
main checkout. Read "Concurrency" below before starting Task A, and Phase 4 before merging.

---

```
Implement the three tasks below in an isolated git worktree, then merge to development and push.

Context: they are the actionable residue of docs/superpowers/specs/2026-08-11-sector-provenance-precedence-design.md,
whose implementation merged as 42f715fbe. Read §4.8 and §9 of that spec before Task A. You do not
need to read the whole thing.

REQUIRED SKILL: use superpowers:subagent-driven-development — a fresh subagent per task, with a
review between tasks. Three tasks. They are independent; do them in order A, B, C.

## Hard rules — violating any of these silently corrupts the run

- NEVER run `git stash`. Other sessions share this repository. Pass this rule to every subagent you
  dispatch, in their prompt text.
- NEVER touch the main checkout from inside the worktree. If a subagent proposes it, reject the task.
- NEVER create a Laravel migration file. The Composer guard rejects them; none of this needs schema.
- NEVER read `account_type` outside `AccountCapabilities`.
- `composer test --filter` is BROKEN in this repo — it reads like "no tests matched". Always run
  Pest with a file path: `./vendor/bin/pest tests/path/File.php`.
- `composer pint` / `php artisan pint` is BROKEN. Use `./vendor/bin/pint <paths>`.
- If you add a NEW directory under `tests/Feature/`, you MUST wire it into `codebase_chunks()` in
  `scripts/audit/audit.sh` AND the matching scope-group in `scripts/audit/lenses/*.md`, or
  `AuditPipelineIntegrityTest` fails the suite. None of these tasks should need a new directory —
  if you think one does, stop and reconsider.
- Do NOT use `pgrep -f "vendor/bin/pest"` to wait on a suite. Sibling sessions run wait-loops whose
  own command lines contain that string, so the pattern matches the waiter and never returns. Match
  on something specific, e.g. `pest --configuration=.*<your-worktree-name>`.

## Phase 1 — worktree setup

Do NOT use EnterWorktree's fresh mode: `origin/HEAD` is `production`, so fresh mode bases off the
wrong branch and silently renames the branch. Use these commands exactly.

    git -C "/Users/joshuahunter/Herd/Side Street/backend" fetch origin development
    git -C "/Users/joshuahunter/Herd/Side Street/backend" worktree add \
      ".claude/worktrees/ladder-followups" development \
      -b chore/sector-ladder-followups

Base off local `development`, not `origin/development` — a sibling session lands commits locally
before pushing, and you want the newest base. Confirm afterwards that your HEAD contains 42f715fbe:

    git merge-base --is-ancestor 42f715fbe HEAD && echo "base OK"

Then make the worktree self-sufficient. `vendor/` MUST be a real copy, never a symlink — PHP
resolves symlinks, so Composer's `$baseDir` would point back at the main checkout and every edit you
make would be invisible to the tests, which then pass against unchanged code.

    # composer.lock parity check first; if this prints anything, run `composer install` in the
    # worktree instead of copying vendor.
    git -C "/Users/joshuahunter/Herd/Side Street/backend" diff --stat HEAD development \
      -- composer.lock composer.json

    cp -R "/Users/joshuahunter/Herd/Side Street/backend/vendor" \
          "/Users/joshuahunter/Herd/Side Street/backend/.claude/worktrees/ladder-followups/vendor"
    cp "/Users/joshuahunter/Herd/Side Street/backend/.env" \
       "/Users/joshuahunter/Herd/Side Street/backend/.claude/worktrees/ladder-followups/.env"

Copy `.env`, do not symlink it.

VERIFY before writing a single line of code — from inside the worktree:

    php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("App\\Services\\Profile\\FoodContentProbe"))->getFileName(), PHP_EOL;'

The path MUST contain `.claude/worktrees/ladder-followups`. If it points at the main checkout, the
vendor copy is wrong — stop and fix it. A green suite means nothing until this passes.

Do NOT run `composer dump-autoload -o` from the main checkout at any point. Other worktrees exist
and the optimized classmap would pin `App\...` classes into one of them.

## Task A — make slice 4 unable to miss FoodContentProbe

`app/Services/Profile/FoodContentProbe.php` decides whether a user has live food content, and one of
its four EXISTS clauses (`$hasMenuItems`) reads `site.menus` / `site.menu_items`. Content-pool
slice 4 retires those tables onto `content.items where kind = 'menu_item'`. When that lands, the
clause silently starts matching nothing and the food-demotion guard quietly weakens. No endpoint
exposes the probe, so nothing will point at it.

The good news is that the repair is a deletion, not a rewrite: the probe ALREADY has a fourth
clause (`$hasFoodItems`) reading `content.items` where `kind = 'menu_item'` and `removed_at IS NULL`
— the exact shape slice 4 migrates into. So slice 4 should DELETE `$hasMenuItems` and its
`->orWhereExists(...)`, and the remaining clause covers it.

Two edits:

1. `docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md` — add a short, additive
   section naming `FoodContentProbe` as a silent consumer of `site.menus`/`site.menu_items`, stating
   that the correct action is to delete the `$hasMenuItems` clause (not port it) because
   `$hasFoodItems` already reads the destination table, and pointing at
   `tests/Feature/Profile/FoodContentProbeTest.php` as the file whose "is true when a menu carries
   items" case will need to move to a `content.items` fixture. Match the document's existing voice
   and heading style — read it first. Keep it to a handful of lines; do not restructure the document.

2. `app/Services/Profile/FoodContentProbe.php` — the `$hasMenuItems` clause carries a
   "SLICE 4 SWAP POINT" comment that says the migration "replaces one expression". That is now known
   to be wrong in a helpful direction: it deletes one. Correct the comment to say so, and name the
   sibling clause that already covers the destination. Comment only — do not change the query.

Verification: `./vendor/bin/pest tests/Feature/Profile/FoodContentProbeTest.php` stays green (the
comment edit cannot break it, but run it to prove the file still parses), and `./vendor/bin/pint`
on the touched PHP file.

CONFLICT RISK — read before editing. The slice-4 kickoff prompt belongs to the content-pool
programme, which has an active session. Before editing it, run `git worktree list` and check the
sibling worktree's `git status`. If that file is modified there, STOP and report rather than racing
it — the section can be added later, and a lost edit to someone else's planning document is worse
than a deferred one.

## Task B — pin the seeder's write → refresh → read ordering

`InstagramConnectionSeeder` resolves `$user = User::find($userId)` (around `:227`), passes it to
`$this->identitySync->applyIdentity($user, $profile)` (around `:229`), and then hands THE SAME
INSTANCE to `$this->autoSaveUnmatchedLinks($user, ...)` (around `:230`), which reaches
`CustomLinkSeeder::seed` → `LinkRouter::route` → `gateAllows`, and that reads `$user->sector` at
`app/Services/Platforms/LinkRouter.php:164`.

`InstagramIdentitySync::applySector` calls `$user->refresh()` after its transaction precisely so the
second half of that run does not gate on a stale sector. Nothing currently pins the ORDERING. A
reorder of `seed()` — which the media-pool work is expected to do — breaks it silently and merges
cleanly.

Write a test that fails if the refresh is removed, OR if `applyIdentity` and `autoSaveUnmatchedLinks`
are reordered relative to each other.

Prefer a behavioural test. If the seeder's HTTP and Apify surface makes a full behavioural test
disproportionate, a source-order structural pin is acceptable — this repo has precedent for exactly
that in `tests/Feature/Platforms/IdentitySyncConcurrencyTest.php`, which greps the source because
`lockForUpdate()` is a no-op on SQLite. If you take the structural route, the test's comment must
say why a behavioural one was not worth it, and the assertion must be specific (the offset of the
`applyIdentity` call is less than the offset of the `autoSaveUnmatchedLinks` call, and
`InstagramIdentitySync::applySector` still contains `refresh()`), not a bare "the file mentions
refresh".

Put it in `tests/Feature/Platforms/` — an existing directory, so no audit-pipeline wiring is needed.

**DO NOT HARDCODE LINE NUMBERS.** Content-pool slice 1b is running in parallel and its Task 7 adds a
`MediaMirror` call to the seeder's mirroring region around `:82-130` — which shifts every line below
it, including the three this test cares about. A structural test must locate the two calls by
searching for `applyIdentity(` and `autoSaveUnmatchedLinks(` in the source and comparing their
offsets, never by asserting they sit at `:229` and `:230`. The same applies to any line reference
you put in a comment: name the method, not the line.

Whichever route you take, PROVE IT CAN FAIL: temporarily swap the two calls in the seeder, confirm
RED; restore; temporarily delete the `refresh()` in `InstagramIdentitySync::applySector`, confirm
RED; restore. Confirm `git diff app/` is empty afterwards. Record both mutations in your report — a
test added without a demonstrated failure is not yet a test.

TRAP THAT COST A PREVIOUS SESSION REAL TIME: if your test creates a `User` and then saves it before
creating its `site.sites` row, `UserObserver::updated` → `UserCacheService::invalidateUser` evaluates
`$professional->site`, which CACHES the relation as null permanently on that instance. Any later code
guarded on `$user->site` then silently no-ops. Create the site row FIRST. See
`tests/Feature/Platforms/IdentitySyncTest.php`'s `idsyncSite()` helper for the established pattern.

## Task C — check the six category keys against reality

`SectorTaxonomy::INSTAGRAM_CATEGORY_SECTORS` gained six entries: `sports bar`, `juice bar`,
`bartender`, `barre studio`, `sportswear store`, `hair removal service`. They were derived by
confirming that the substring map MIS-MAPS those strings — not by confirming Instagram emits them.
`barre studio` and `bartender` are the least certain.

Establish whether the raw Instagram category values are persisted anywhere, then compare.

1. Find where the raw Apify profile node's category lands. `InstagramIdentitySync::applyIdentity`
   reads `businessCategoryName`, `business_category_name` and `category_name` from a payload the
   seeder passes in. Candidates worth checking: `site.platform_connections.payload`,
   `ingest.record_versions`, `content.sources`. Read the seeder to see what it persists.
2. If the values ARE persisted, query the DEV database read-only for the distinct set — use the
   Supabase MCP against dev (`glncumufgaqcmqhzwrxm`), a plain `SELECT`. Never write.
3. Compare against the six keys and report: which are confirmed, which never appear, and — most
   useful — any observed value that the current maps still classify wrongly.

**If the values are NOT persisted anywhere, say so plainly and stop.** That is a complete and
acceptable answer to this task. Do not fabricate a conclusion, do not go scraping Instagram, and do
not infer from the fixtures in the test suite — those are the values we invented, so checking our
keys against them is circular.

If you find a real value that maps wrongly, you MAY add a key for it (a dead exact-match key costs
nothing, so additions are safe). Do NOT remove any of the six on the grounds that you did not see it
— absence in dev data is weak evidence. Do NOT touch `KEYWORD_SECTORS` or `fromGoogleCategory()`;
the Google path must not regress. Every value you add must be a real slug in `users_sector_check`
(the test file's existing `isValid()` pin will catch a typo that SQLite would let through and
Postgres would reject as 23514).

## Phase 3 — verification gate

    ./vendor/bin/pest tests/Unit/Profile/ tests/Feature/Profile/ tests/Feature/Platforms/
    COMPOSER_PROCESS_TIMEOUT=0 composer test
    ./vendor/bin/phpstan analyse --memory-limit=2G

`COMPOSER_PROCESS_TIMEOUT=0` is required — the suite exceeds Composer's default timeout and dies
without it. It takes roughly 8 minutes.

The schema lane is NOT needed: none of these tasks touches a constraint, a migration, or
`SectorProvenance::RANKS`. Say so in your report rather than running it.

## Phase 4 — merge and push

**DO NOT MERGE FROM THE MAIN CHECKOUT.** The repository owner is doing connection-pooling work there
on his own branch, and other sessions share it. On 2026-08-12 a peer switched that checkout's branch
mid-task and a commit intended for `development` landed on the peer's branch instead. Use a
dedicated worktree for the merge — `development` will not be checked out anywhere, so a worktree can
claim it:

1. Commit everything in your work worktree.
2. `git -C "/Users/joshuahunter/Herd/Side Street/backend" worktree add ".claude/worktrees/dev-merge" development`
   — if this fails with "already checked out", someone has `development` open. STOP and report;
   do not force it.
3. From `.claude/worktrees/dev-merge`: `git fetch origin development` and fast-forward `development`.
   Copy `vendor/` and `.env` in as in Phase 1 — you need to run the suite here.
4. Check what landed while you worked: `git log --oneline <your-base>..development` and
   `git diff --stat <your-base>..development`. Three content-pool sessions are active. Read the
   diff before merging, not after, if any of these moved:
   `app/Services/Platforms/InstagramConnectionSeeder.php` (1b's mirror call shifts line numbers),
   `app/Services/Profile/SectorTaxonomy.php`, `app/Services/Profile/FoodContentProbe.php`,
   or `docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md` (all three may edit it).
5. `git merge --no-ff chore/sector-ladder-followups`
6. RE-RUN THE SUITE ON THE MERGE: `COMPOSER_PROCESS_TIMEOUT=0 composer test`. This is the step that
   catches semantic conflicts — two branches that each pass alone can fail together. Do not skip it
   because both sides were green.
7. `git push origin development`

Expect 1–3 fetch+rebase cycles at push; sibling sessions land on `development` continuously. Note
that pushing `development` may also carry up commits a sibling session committed locally but has not
pushed — check `git log --oneline origin/development..development` before pushing and say what is
going up.

This pushes `development` only. It does NOT deploy production; that is a separate
`git push origin development:production`. Do not do that.

## Phase 5 — cleanup

    git worktree remove .claude/worktrees/ladder-followups --force
    git worktree remove .claude/worktrees/dev-merge --force
    git branch -d chore/sector-ladder-followups

`--force` is needed because both worktrees hold an untracked `vendor/` copy and `.env`. Leave the
main checkout's branch exactly as you found it — you should never have switched it.

## Concurrency — three content-pool sessions run alongside you

These run in parallel with you, each in its own worktree:

- `docs/superpowers/plans/2026-08-12-media-pool-slice-1b-EXECUTE-PROMPT.md`
- `docs/superpowers/plans/2026-08-12-slice-3-services-KICKOFF-PROMPT.md`
- `docs/superpowers/plans/2026-08-12-slice-5-shop-KICKOFF-PROMPT.md`

I read all three against your three tasks. **Code overlap is zero.** They work
`ProjectionWriter`, `PoolResolver`, `PoolRegistry`, `MediaUrlResolver`, `app/Services/Migration/`,
`site.services`, `site.shop_products`, and (1b only) `app/Services/Media/MediaMirror.php` and
`BrandAssetPipeline.php`. None of them names `SectorTaxonomy`, `SectorProvenance`,
`FoodContentProbe`, `InstagramIdentitySync`, or `OnboardingSuggestions`.

**One shared document, and it is your real conflict risk.** All three carry a "update the downstream
prompts" table, and all three list `slice-4-menus` as a downstream consumer — 3 and 5 for the
`SECTION_SHAPE` convention for priced undated items, 1b for `ProjectionWriter`/`PoolResolver`
changes. Task A edits that same file. So:

- Before editing `2026-08-12-slice-4-menus-KICKOFF-PROMPT.md`, run `git worktree list`, check each
  sibling worktree's `git status`, and `git log --oneline -3 -- <that file>` on `development`.
- Your edit is **additive and in its own section** — a short "silent consumers" note. Keep it that
  way so a concurrent edit to their `SECTION_SHAPE` section merges cleanly beside it.
- If the file is dirty in a sibling worktree, **stand down and report it**. A deferred note is
  cheaper than a lost edit to someone else's planning document. Slice 4 is several slices away;
  there is no urgency.

**On `InstagramConnectionSeeder` specifically — smaller than it looks.** The old sector-provenance
prompt warned that "slice 1 rewrites the seeder's mirroring". That is region `:82-130`, not the
identity fold at `:227-230`. I checked 1b's plan directly: Task 7 creates `MediaMirror.php` and
modifies `BrandAssetPipeline.php:187-222`; the seeder appears only in a comment, and **nothing in
that plan touches `applyIdentity`, `autoSaveUnmatchedLinks` or the `:220-239` region.** So:

- Task B commits **only a new test file**. It makes no permanent edit to the seeder, so there is no
  textual conflict with 1b at all.
- 1b may still add a `MediaMirror` call around `:82-130`, which **shifts every line number below
  it**. That is why Task B must not hardcode line numbers (see the task).
- If 1b lands first and your test then fails, the test is doing its job. Do not delete it — move the
  `refresh()` so the invariant holds again, and tell the 1b session.

**Reciprocate the courtesy those prompts demand.** They require a session that changes something
downstream to edit the affected prompt in place rather than bury it in a checkpoint. If 1b has not
merged when you finish Task B, add one line to its execute prompt saying a test now pins
`applyIdentity` → `refresh()` → `autoSaveUnmatchedLinks` ordering in `seed()`, and that a reorder
must carry the refresh with it. Say the fact, not the story.

## Final report

Tell me:
- Which tasks completed, and any rejected in review and why.
- The merge commit SHA and confirmation the suite was green ON THE MERGE (not just on the branch).
- For Task C specifically: whether the raw category values turned out to be persisted at all, and
  the actual finding — including "not persisted, could not check" if that is the answer.
- Anything whose premise did not match the code.
- Whether you had to stand down on the slice-4 document, and if so, that it still needs doing.
```
