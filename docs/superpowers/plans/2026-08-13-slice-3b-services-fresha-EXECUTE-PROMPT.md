# EXECUTE PROMPT — Slice 3b: Fresha services → `content.*`

Parallel subagent execution of
`docs/superpowers/plans/2026-08-13-slice-3b-services-fresha.md`.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-3b-exec`** so it is identifiable
in Remote Control instead of appearing as a machine name.

You are executing an implementation plan with **parallel subagents**. Use
`superpowers:subagent-driven-development`. Read these in full before dispatching
anything:

1. `CLAUDE.md`
2. `docs/superpowers/plans/2026-08-13-slice-3b-services-fresha.md` — the plan.
   Its tasks are the units of work; its Global Constraints bind every subagent.
3. `docs/superpowers/specs/2026-08-13-slice-3b-services-fresha-design.md` — the
   spec the plan argues from. A subagent that needs to know *why* reads this.

## The worktree already exists — do not create another

```
/Users/joshuahunter/Herd/Side Street/backend/.worktrees/slice-3b-fresha
branch: feat/slice-3b-fresha   base: origin/development (carries slice 3a)
```

`composer install` has been run and `.env` copied. Enter it with
`EnterWorktree` (path form). Sibling worktrees `slice-3-services` and
`slice-5-shop` belong to other sessions — never touch them, and never touch the
main checkout.

**Do NOT dispatch subagents with `isolation: "worktree"`.** Wave 3 needs Wave
2's code in the same tree (Task 3 consumes Task 2's `Pull.config` key; Tasks
9/10 consume Task 8's `ServiceCollections`). Per-agent worktrees would hide it
and every dependent task would fail against code it cannot see. All agents share
this one worktree.

## Two rules that exist because agents run in parallel

**1. Subagents run NO git commands. You own every one.** Parallel agents
committing into one worktree race on `.git/index.lock`, and a failed `git add`
mid-wave is silent. Each subagent edits files and runs its filtered tests, then
reports. You review, then you `git add` and `git commit` — one task per commit,
using the plan's own commit message, in task order, after the wave lands.
Put **"Do not run any git command — not `add`, not `commit`, not `status`, and
never `git stash`"** in every prompt you dispatch. `git stash` is shared across
all worktrees on this machine; a stray pop takes another session's work.

**2. Subagents run FILTERED tests only — never the full suite.** A full run
takes ~315s, and a sibling agent editing app code inside that window makes the
suite load a half-refactored pair of files and fail in areas nobody touched.
That failure is indistinguishable from a real regression and has cost this repo
real time before. Agents run `./vendor/bin/pest --filter=<their own>`. **You**
run the full suite between waves, when no agent is live.

## Wave plan

15 tasks in 9 waves. Dispatch every task in a wave in **one message with
multiple Agent calls** so they run concurrently. Do not start a wave until the
previous one is reviewed and committed.

| Wave | Tasks | Parallel | Files touched — verified disjoint |
|---|---|---|---|
| 0 | Task 0 (below) | solo | three test files, read-only recon |
| 1 | 1 | solo | migration, PG stand-in DDL, **dev DB** |
| 2 | **2, 5, 8, 12** | ×4 | `SourceProvisioner`+`RunExecutor`+`Pull` · `ProjectionWriter` · new `ServiceCollections` · new `FreshaServiceItems`+`FreshaSelectionResource` |
| 3 | **3, 9, 10, 11** | ×4 | `FreshaConnector` · `UserServiceCategoryController` · `UserServiceController`+`ServicePolicy`+`ManualServiceItems` · `StaffServiceManagementController` |
| 4 | **4, 13** | ×2 | `FreshaConnector` · new test files |
| 5 | 6 | solo | `FreshaServiceProjector` |
| 6 | 7 | **solo, exclusive** | none — live dev verification |
| 7 | 14 | solo | docs |
| 8 | 15 | solo | merge, after explicit sign-off |

Tasks 3 and 4 both edit `FreshaConnector.php` and are deliberately in different
waves. Do not merge them into one wave to save a round.

### Wave 6 is exclusive — and not only of your own agents

Task 7 dispatches a real ingest run against the **shared dev database**
(`glncumufgaqcmqhzwrxm`). A worktree isolates files; it does not isolate the
database. Parent spec §4.3 rule 3: two slices touching one kind must not run
their verification windows simultaneously. Before Wave 6, run `git worktree
list` and check each sibling's `git status` for live `service`-kind work. Run
nothing else while it is in flight.

### Deviation from the plan, made deliberately — say so in the checkpoint

The plan puts Task 7 (prove the lane on dev) **before** Task 8, reasoning that
read paths should not be built on an unproven lane. This wave plan runs it after
the read-path tasks, because they are fixture-driven and do not consume real dev
data, and the entry gate already proved the vendor side live on three slugs. The
gain is four tasks of parallelism; the cost is that if the lane fails on dev,
some read-path work was premature. **Task 7 still blocks Tasks 14 and 15** — the
merge gate is unchanged. If Task 7 fails, stop and fix before any doc or merge
work.

## Task 0 — close the plan's one soft spot, before Wave 1

The plan names three test files it located by grep but never opened, so their
helper identifiers are placeholders. Dispatch one agent to read them and report
the real names — do **not** let four Wave 2 agents each guess independently.

Files to open:
- the `SourceProvisioner` test (`grep -rln "SourceProvisioner" tests/`)
- the `FreshaConnector` test (`grep -rln "FreshaConnector" tests/`)
- the `ProjectionWriter` test (`grep -rln "ProjectionWriter" tests/`)
- `tests/Postgres/ShopStorefrontUpsertConflictTest.php` — Task 1 copies its
  bootstrapping
- `tests/Feature/Api/User/ServiceEndpointCutoverTest.php` — Task 9 copies its
  assertion style

Report, for each: the real fixture/helper function names, how a connection or
source is built, and how the `Io` fake and `StreamSpec` are constructed. Then
**edit the plan in place** so Tasks 1, 2, 3, 5 and 9 name real identifiers.
A plan that ships invented helper names makes every downstream agent invent its
own, differently.

## What every dispatched prompt must carry

Subagents start with no context. Each prompt needs, in full:

- the task's own section of the plan, **pasted verbatim** — do not tell an agent
  to "read Task 5", paste it
- the plan's **Global Constraints** section, pasted verbatim
- the worktree path, and "work only in this directory"
- the exact list of files it may modify, and "touch nothing else — three other
  agents are editing this tree right now"
- "run only `./vendor/bin/pest --filter=<yours>`; never the full suite"
- "run no git command; the dispatching session commits"
- `COMPOSER_PROCESS_TIMEOUT=0` on any composer invocation
- the mutation checks its task specifies, as work to do rather than a suggestion

## Non-negotiables, carried from the plan

- **`composer test:pg` is mandatory** in this slice — it edits `ProjectionWriter`.
  The lane's stand-in DDL is hand-written and drifts; if Task 1 does not add
  `external_ref`, `removed_at`, `selection_ref` **and the unique index** to it,
  Task 5's upsert silently inserts duplicates there and passes.
- **Qualify every column in `ON CONFLICT DO UPDATE`.** A bare column is
  SQLSTATE 42702 on Postgres and fine on SQLite — slice 5a shipped exactly this
  through a green suite.
- **The bundle fix has TWO independent defects** (Task 4): a regex pinned to
  `s:`, and `primaryAction.id ?? secondaryAction.id` being a null-coalesce over
  a non-null string. Fixing either alone still loses the row. Both get their own
  mutation check; one mutation passing is not evidence.
- **Assert exact cache-revision deltas, never `content_revision > 0`.** 3a's
  three-lane test passed with the `BuildState` lane deleted.
- **Never write `content.source_items.removed_at` for a user deletion.**
- **Never route a Fresha row through `ManualServiceWriter::projectionFor()`** —
  its `price_cents === 0 → 'free'` rule is right for typed data and a lie on
  scraped data.
- **Never pin a test to a live vendor count.** Edward's storewide menu moved
  22 → 25 between the kickoff and the entry gate, four days apart.
- **Task 10's coupling is not optional:** `ServicePolicy::updateCategory()`'s
  gate and `ManualServiceItems::publicList()`'s hardcoded `'category' => 'Services'`
  move together or neither moves. The policy docblock states this outright.
  Removing the gate alone ships a page labelling every category "Services".

## Between waves — your job, not an agent's

1. Review each task's diff yourself against its plan section. The
   `subagent-driven-development` skill's two-stage review applies: a fresh
   reviewer agent may check a task, but you make the call.
2. Commit each task separately, in task order, with the plan's commit message.
3. Run the full suite **once per wave**, with no agent live:
   `COMPOSER_PROCESS_TIMEOUT=0 composer test`
4. If something fails in an area no task touched, do not assume a regression.
   `git status --short`, then `stat -f "%Sm %N"` the failing files — an mtime
   inside your run window is proof of a concurrent edit, not a defect. Re-run
   that directory alone before believing it.

## Gates

- **After Wave 1:** paste the dev schema verification SQL output. Nothing
  proceeds on an unapplied migration.
- **After Wave 6 (Task 7):** paste the live SQL. The gates are: `manual`
  source_items still **21**, `connection` > 0, zero `qualifier='exact'` offers
  with `amount_minor = 0`, owner items still 18 live / 3 retired, and a **second
  identical run** changing none of it. **STOP — sign-off.**
- **Before Wave 8 (merge):** full suite + `composer test:pg` +
  `composer test:schema` + PHPStan + Pint all green **on the rebased tree**, not
  the pre-rebase one. **STOP — explicit sign-off.** Then
  `git push origin feat/slice-3b-fresha:development`. **Never push to
  `production`.**

## Owed on merge

`PoolRegistry`'s four const arrays collide with slice 5b's `shop` entry and both
edit the same docblock sentence. It is a union, not a design conflict. Whoever
merges second re-runs `PoolRegistryTest` **and** the pool provisioning tests
**after** resolving — a union merge that drops half a const array still passes
every test written by the branch that added the other half.

Task 14 edits the slice 4, 5b, 6 and 7 prompts in place. A checkpoint is not a
communication channel: parent invariant #5 forbids citing one as evidence, so a
discovery written only into your own checkpoint guarantees the next session
never acts on it. Say the fact, not the story.
