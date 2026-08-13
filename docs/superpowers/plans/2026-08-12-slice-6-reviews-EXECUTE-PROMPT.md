# EXECUTE PROMPT — Slice 6: Reviews → `content.*`

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-6-exec`.**

You are implementing an approved plan. The design decisions are already made and
signed off — you are not re-opening them. Read, in full, before touching
anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-12-slice-6-reviews-design.md` — the spec.
   §2 (the PII contract) is the part you must not get wrong.
3. `docs/superpowers/plans/2026-08-12-slice-6-reviews.md` — the plan you are
   executing. 11 tasks, TDD, with the code written out.

## What this slice is

Google reviews already land in `content.*` (slice 1b's shared `places.details`
call brought them). This slice adds the render path, retires the legacy
`platform_connections` review read, and closes a **live P0 privacy defect**:
the projector copies the reviewer's display name into
`content.items.headline_cache` and `content.f_text.headline`, neither of which
is reached by the redaction manifest, `PruneOrphanedReviewPiiCommand`, or the
DSAR omission. Verified on 5 real claimed rows on dev.

**Treat every PII decision as a blocker-gate item.** If the code disagrees with
the spec on a redaction, retention or disclosure behaviour, STOP and report —
that is a decision, not an edit.

## Workspace — isolated worktree, and it already exists

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git worktree list
```

`.worktrees/slice-6-reviews` on branch `feat/slice-6-reviews` is yours,
rebased onto `52c81ba43`. Work there and nowhere else.

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend/.worktrees/slice-6-reviews"
git log --oneline -3   # expect fec9337c4 docs(plan): slice 6 implementation plan
cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env   # not tracked; artisan cannot boot without it
composer install                                              # NOT dump-autoload
```

**`composer dump-autoload` is not enough and this cost real time.** A fresh worktree here
had a STUB `vendor/` — `autoload.php` plus `composer/` and nothing else — and no `.env`,
so `artisan` could not boot at all and `dump-autoload` had no installed packages to map.
Copy the parent checkout's `.env` first, then run a full `composer install`. Verify with
`php artisan --version` before running a single test.

If that worktree is missing, recreate it from `origin/development` — do NOT
work in the main checkout. Other sessions share it and a peer can switch its
branch mid-task.

**Never run `git stash`.** Not once, not "just to check something". The main
checkout is shared and a stash there has wiped uncommitted peer work before.

## Concurrency — two lanes, strict file ownership

The plan's 11 tasks are **not** all parallelisable: Tasks 2 and 4 edit the same
projector, and Tasks 6 and 7 edit the same resolver. Run them in waves, with
one agent per lane. **The file-ownership map below is what prevents two agents
writing the same file — respect it literally.**

```
WAVE 0  (blocking, one agent)
  Task 1 — schema: author_uri + content.source_stats
           migrations, tests/Pest.php stand-in, PG-lane DDL,
           ProjectionWriter::SINGLETON_FACETS
  → Everything else depends on this. Do not start Wave 1 until it is committed.

WAVE 1  (two agents, concurrent)
  Lane A — "ingest/PII"   Task 2 → Task 3 → Task 4   (serial within the lane)
  Lane B — "pools"        Task 5 → Task 6 → Task 7   (serial within the lane)

WAVE 2  (one agent, after BOTH lanes land)
  Task 8 — retire the legacy read + DSAR follows

WAVE 3  (serial, gated)
  Task 9  — provisioning, docs, propagation
  Task 10 — verify on dev  (BLOCKED on the coordinator applying the migrations)
  Task 11 — merge          (BLOCKED on explicit owner sign-off)
```

### File ownership — do not cross these lines

| Owner | Files |
|---|---|
| **Wave 0 only** | `supabase/migrations/**`, `tests/Pest.php`, `tests/Postgres/**`, `ProjectionWriter::SINGLETON_FACETS` (the const only) |
| **Lane A** | `app/Ingest/**` (incl. the rest of `ProjectionWriter.php`), `app/Console/Commands/PruneOrphanedReviewPiiCommand.php`, `app/Console/Commands/PurgeReviewHeadlinePiiCommand.php`, `tests/Feature/Ingest/**`, `tests/Feature/Content/PurgeReviewHeadlinePiiTest.php` |
| **Lane B** | `app/Site/Pools/**`, `app/Http/Controllers/Api/Content/PoolItemCreateController.php`, the section-curation controller behind `UpsertSectionItemRequest`, `tests/Feature/Pools/**`, `tests/Feature/Content/PoolWireShapeTest.php` |
| **Wave 2** | `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`, `app/Services/User/DataExport/**`, `app/Services/Platforms/DsarPayloadFilter.php` |

`ProjectionWriter.php` is the one genuinely shared file: Wave 0 edits its
`SINGLETON_FACETS` const, Lane A's Task 4 edits `projectStream()`. Because
Wave 0 fully completes and commits first, they never overlap in time. **Lane B
must not touch `ProjectionWriter.php` at all.**

### How to run the lanes

Dispatch one agent per lane with the lane's task list, the file-ownership row,
and the instruction to commit after each task. Between waves, review the diff
yourself before dispatching the next. Do not dispatch Wave 2 until both Wave 1
lanes have committed — Task 8 asserts against what both produced.

Each agent gets, verbatim:

> You own ONLY the files listed for your lane. If your task appears to require
> editing a file owned by another lane, STOP and report rather than editing it.
> Commit after each task. Never run `git stash`. Run
> `vendor/bin/pest --filter=<Name>` after each task, not the full suite.

## Overlap with other slices in flight — check before you start, and again before you merge

**Slice 5b merged on 2026-08-13 (`52c81ba43`).** Its `PoolRegistry` and
`PoolResolver` changes are already on `development`, so there is no 5b conflict
to negotiate. Two things it left you:

- `PoolResolver::ITEM_KEYS` + `tests/Feature/Content/PoolWireShapeTest.php`
  pin the **exact** pool item key set and catch additions as well as removals.
  Task 6 adds a `review` key, so that test goes red until `ITEM_KEYS` is updated
  in the same commit. **Do not weaken the assertion to a subset check** — its
  exactness is the point.
- `46ed03e0a` is the precedent for Task 8: it retired the legacy `/integrations`
  shop keys the same way you are retiring the review keys. Read it before
  writing Task 8.

**Slice 3b (Fresha) overlaps in exactly TWO files — measure intent, not
distance.** 3b is 25 commits behind `development`, so a plain
`git diff origin/development..feat/slice-3b-fresha` reports it touching five of
your files, including `PoolRegistry` (12+/21-) and `PoolResolver` (13+/296-).
**Those are artefacts of being behind slice 5b, not edits 3b intends.** Diff
from the merge-base instead and the picture is much smaller:

```bash
MB=$(git merge-base feat/slice-3b-fresha origin/development)
git diff --numstat $MB..feat/slice-3b-fresha -- <file>
```

Measured that way (merge-base `e12759d92`):

| File | 3b's real intent | Collides with |
|---|---|---|
| `app/Site/Pools/PoolRegistry.php` | **nothing** | — |
| `app/Site/Pools/PoolResolver.php` | **nothing** | — |
| `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` | **nothing** | — |
| `app/Ingest/Projection/ProjectionWriter.php` | adds collections handling inside `projectStream()`'s record loop | **Task 4**, which adds the `source_stats` write in the same method |
| `tests/Pest.php` | adds `external_ref`/`removed_at` + an index to the collections stand-in | Task 1, different lines — trivial |

So **Lane B (pools) has no 3b exposure at all** and can run without reference
to it. Only Task 4 needs care, and the two changes are semantically
independent — a textual conflict in one method, not a design clash. Whoever
rebases second resolves it by keeping both.

Do not "fix" 3b, and never edit files inside its worktree.

Re-run the merge-base check before Task 11 — branches move under you. 5b merged
mid-plan-drafting and invalidated a whole section of this plan.

Rules:
- **Never edit files inside `.worktrees/slice-3b-fresha`.** Check
  `git worktree list` and the sibling's `git status` before assuming any file
  is free (CLAUDE.md).
- Re-run the overlap check before Task 11, because branches move under you —
  5b merged mid-plan-drafting and invalidated a whole section of this plan:

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && git fetch origin --quiet
for b in feat/slice-3b-fresha feat/slice-4-menus; do
  MB=$(git merge-base $b origin/development)   # intent, not distance
  git rev-parse --verify --quiet $b >/dev/null || continue
  echo "=== $b ($(git rev-parse --short $b)) ==="
  for f in app/Ingest/Projection/ProjectionWriter.php app/Site/Pools/PoolRegistry.php \
           app/Site/Pools/PoolResolver.php tests/Pest.php \
           app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php; do
    n=$(git diff --numstat $MB..$b -- "$f" | awk '{print $1"+/"$2"-"}')
    echo "  ${n:-clean}  $f"
  done
done
```

**Slice 4 (menus) has not started.** When it does it will add a `menu_item`
entry to `PoolRegistry` — the same const arrays. Task 9's propagation step
records `reviews`, `EXCLUDE_ONLY_POOLS` and `MANUAL_ADD_FORBIDDEN_POOLS` in
slice 4's prompt so it inherits them rather than rediscovering them in a
conflict.

**Whoever merges second re-runs `PoolRegistryTest` AFTER resolving.** A merge
that drops half a const array still passes every test written by the branch
that added the other half.

## Hard rules

- **Do NOT apply migrations.** Write the file; the coordinator applies it to
  shared dev. Three sessions use `glncumufgaqcmqhzwrxm`. Every migration must
  be mirrored into `tests/Pest.php` AND the Postgres-lane DDL in the same
  commit, or the suites break before it lands.
- **`composer test:pg` is mandatory** for any task touching `ProjectionWriter`
  (Tasks 1 and 4). That lane's DDL is hand-written and drifts silently — slice
  5a turned it red for 7 tests and two reviews missed it on a green SQLite run.
  If it cannot reach a Postgres container it **skips silently**; "31 skipped"
  reads green and tests nothing. Do not record a skip as a pass.
- **No new billed calls.** The effect digest stays `['place_id' => …]`. Adding
  an input key doubles the Places Details bill for every user.
- **Cache invalidation is three lanes** — `BuildState::bump($siteId)`, touch
  `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check
  enforces it. Required for every raw-SQL write path you add.
- **Never weaken a PII control.** A review path that bypasses `redactionScopes`,
  or leaves `f_review` rows unreachable by `PruneOrphanedReviewPiiCommand`, is a
  launch blocker.
- **Migration prefix block: `20260813110000`–`20260813119999`.** Do not consume
  outside it. If you must, update whichever slice owns the block you took from.
- **Reviewer PII stays out of transcripts.** When verifying on dev, assert with
  counts and column-shape (`count(*) FILTER (WHERE author_name IS NOT NULL)`),
  never by selecting names. The counts carry the same proof.
- Tests run SQLite, production is Postgres. SQLite treats an unknown
  double-quoted identifier as a string literal, so a typo'd column in an
  assertion passes silently. Check names against the migration.
- **`composer test -- --filter=X` does not filter — use `vendor/bin/pest --filter=X`.**
  The flag reaches `config:clear`, not Pest, so `composer test -- --filter=Foo` either
  errors on the unknown option or runs the WHOLE suite while you believe it ran one file.
  Every task in the plan is written with the broken form; substitute the working one.
  `php artisan pint <changed files>` is a separate command, never combined with a test run.
- CI runs nine jobs at ~17 minutes; red costs the same as green. Filter
  locally rather than pushing to find out.

## Gates — stop at each

1. **Wave 0 complete.** Report the migration filenames and confirm both the
   SQLite stand-in and the PG lane carry the new columns. **STOP — sign-off.**
2. **Wave 1 complete.** Both lanes committed. Report the diff and confirm
   `PoolWireShapeTest` is green with `review` in `ITEM_KEYS`.
   **STOP — sign-off.**
3. **Wave 2 complete.** **STOP — sign-off.**
4. **Independent review of the whole diff, explicitly including a PII pass.**
   Dispatch a fresh reviewer that has not written any of the code. It must
   check: no reviewer name reachable through any DSAR section; the prune
   command's guarantee now true end to end; redaction still applied at landing.
   **STOP — sign-off.**
5. **Task 10, verify on dev.** Blocked until the coordinator confirms both
   migrations are applied. Paste live SQL output into a parent-spec checkpoint.
   **STOP — sign-off.**
6. **Task 11, merge.** Rebase, full suite + `test:pg` + `test:schema` +
   PHPStan + Pint green, re-run the overlap check above.
   **STOP — explicit owner sign-off.** Then merge and push. **Never push to
   `production`.**

## Definition of done

Reviews render from `content.*` through the pool lane with rating, text and
attribution; the reviewer's name exists in `content.f_review` and nowhere else;
`content:purge-review-headline-pii` has run on dev and left zero remnants;
`PruneOrphanedReviewPiiCommand` demonstrated against real rows; the legacy
`reviews`/`reviewSummary`/`rating`/`reviewCount` read is retired with the
aggregates served from `content.source_stats`; no additional billed calls;
`PoolWireShapeTest` green with the exact key set; LEGAL-2 restated in the
checkpoint as inherited-and-extended, not discharged.
