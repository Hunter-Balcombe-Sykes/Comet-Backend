# EXECUTE PROMPT — Item Feed (spec + plan 2026-08-19)

Paste everything below the line into a fresh **Opus** session. It is self-contained.

---

**First action: rename this session to `item-feed-exec`.**

You are the **orchestrator** for an approved plan. The design decisions are made
and signed off — you are not re-opening them. You do not write code yourself:
you dispatch one implementer subagent per task, review between tasks, and keep
the branch green. Read, in full, before dispatching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-19-item-feed-design.md` — the spec. §3 (wire),
   §4 (resolution), §5 (settings), §7 (degradation), §10 (out of scope).
3. `docs/superpowers/plans/2026-08-19-item-feed.md` — the plan you are executing.
   6 tasks, TDD, code written out, exact signatures verified against the tree
   on 2026-08-19.

Then invoke the skill **`superpowers:subagent-driven-development`** and follow it
for every task: fresh implementer subagent → spec-compliance review →
code-quality review → next task. Do not skip a review because a task "looks
small".

## What this builds

A new public-wire surface `profile.feed`: one mixed ordered list of pool-item
REFERENCES (videos, songs, dishes, services, products, events, links) with three
site-level modes (`manual` / `newest` / `score`, **default `newest`**), served
beside — never instead of — the button-level `rankedActions`. Menus/services
items group into category blocks; `reviews` is never a source; shop products
float. No new tables, no migrations, no new analytics lane: the resolver is
**pure** (wire pools in → ordered refs out), scores come from the existing
`content_popularity_scores` rows, settings ride `PATCH /api/site`.

**Hard scope lines (spec §10):** `rankedActions` / `SiteActionsService` /
`RankedActionsComputer` untouched. No server-side cap on entries. No SQL of any
kind — if an implementer thinks they need a migration, they have misread the
plan; stop them.

## Model policy — this is not optional

You are on Opus. **Every subagent you spawn inherits Opus unless you say
otherwise.** Set `model:` explicitly on every `Agent(...)` call:

| Work | `model:` |
|---|---|
| Implementer for a plan task | `sonnet` |
| Spec-compliance reviewer, code-quality reviewer | `sonnet` |
| Any read-only lookup ("what shape does buildPools emit", "which helpers does UpdateSiteValidationTest use", greps) | `haiku` |
| Task 6 docs prose (api.md + wire-changes manifest) | `sonnet` |

Reserve **your own** Opus context for: reading reviews, judging red tests,
the merge/PR decision, and the handoff. If you catch yourself about to run a
grep, dispatch `haiku` instead.

## Workspace — one worktree, serial tasks

The spec (`a4906a466` + `42f921b85`) and plan (`2ec462a2f`) were committed on the
UNRELATED branch `fix/public-contact-shareable-2026-08-19`. Cherry-pick just
those three docs commits onto a fresh feature branch off `origin/development`:

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git fetch origin development
git worktree add .worktrees/item-feed -b feature/item-feed-2026-08-19 origin/development
cd .worktrees/item-feed
git cherry-pick a4906a466 42f921b85 2ec462a2f   # spec, spec §9 addendum, plan — docs-only, conflict-free
cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env   # untracked; artisan cannot boot without it
composer install                                # NOT dump-autoload — a fresh worktree has a stub vendor/
php artisan --version                           # must print before any test runs
```

If a cherry-pick conflicts (the source branch moved), take the file contents
from `fix/public-contact-shareable-2026-08-19` verbatim — they are docs, the
newest wins.

**All six tasks run in THIS worktree, STRICTLY SERIAL** — Task 1 defines the
service Tasks 3–5 import; Task 4's `publicPools()` rename is consumed by Task 5.
One implementer at a time; concurrent subagents in one checkout share the git
index and have clobbered each other's staged files before (memory: "Agents
share INDEX").

**Never** work in the main checkout (peers share it and can switch its branch
mid-task — `git worktree list` currently shows FOUR other live worktrees; touch
none of them). **Never run `git stash`** anywhere. **Never** run `php artisan
boost:install`.

## Task order

```
Task 1  ItemFeedService — pure resolver + unit tests            (the core)
Task 2  ContentPopularityReader::itemScoresForSite()            (fail-open score map)
Task 3  Settings — validation trait + LIST_SETTINGS_KEYS + config knob
Task 4  Public payload — profile.feed + buildPools → publicPools rename
Task 5  Dashboard — GET /api/site/actions feed preview
Task 6  Docs, composer test, pint --test, wrap-up
```

## Per-task instructions to every implementer

Include in each dispatch, verbatim:

- Work ONLY in `/Users/joshuahunter/Herd/Side Street/backend/.worktrees/item-feed`.
- Your task is Task N of `docs/superpowers/plans/2026-08-19-item-feed.md`. Follow
  its steps in order: failing test → run (must fail for the stated reason) →
  implement → run (must pass) → `vendor/bin/pint <files>` → `vendor/bin/pint
  --test <files>` → commit with the plan's message.
- Test runs: `vendor/bin/pest <path>` only. NEVER `composer test --filter`
  (broken with pint). NEVER push.
- The plan's test code contains placeholder helpers (`patchSite()`,
  `publishedSiteWithPoolContent()` etc.) — replace them with the target test
  FILE's own existing arrangement, inlined. Do NOT create cross-file test
  helpers (they break the parallel runner).
- Plan code is the contract for names/signatures/behaviour. If the tree
  disagrees with the plan (a signature moved, a helper is gone), STOP and
  report; do not improvise a different interface.
- Judgment calls already made — do not "fix": multi-category items home to
  their first SERVED collection; manual item refs match `itemId` only; score
  collisions keep max; `newest` is the default mode.

## Blocker gates — STOP and ask Josh, do not decide

1. **Any change under `supabase/`** — the plan needs none; wanting one means a
   misread.
2. **Any edit to `SiteActionsService`, `RankedActionsComputer`, or an existing
   wire key** — out of scope by spec §10.
3. **A pre-existing test goes red** (`PoolWireShapeTest`,
   `StaffUpdateSiteValidationTest`, the profile controller suites) and the fix
   is not obviously in the new code — that may be a real finding; report, don't
   force-green.
4. **Task 6's full `composer test` fails on something unrelated** to this
   branch — verify against a clean `origin/development` run before blaming or
   touching it.

## Definition of done

- All 6 tasks committed on `feature/item-feed-2026-08-19`, each reviewed
  (spec-compliance + code-quality) with findings addressed.
- `composer test` green in the worktree; `vendor/bin/pint --test` clean on all
  changed files. Report the truth: this is the SQLite lane — say "composer test
  green" and note that `test:pg`/`test:schema` are not implicated (no
  ProjectionWriter, no DDL), rather than claiming "all lanes green".
- PR opened against `development` (spec + plan commits travel with it; body ends
  with the standard generated-with footer). Do NOT merge — Josh reviews.
- Handoff: run the `partna-handoff-status` skill, then summarize per task:
  commit hash, review outcomes, anything stopped at a gate.
