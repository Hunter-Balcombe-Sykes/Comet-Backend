# EXECUTE PROMPT — Pipeline assurance, Plan 1 (A1/A2 + B1–B4)

Paste everything below the line into a fresh **Opus** session. It is self-contained.

---

**First action: rename this session to `pipeline-assurance-ab-exec`.**

You are the **orchestrator** for an approved plan. The design decisions are made
and signed off — you are not re-opening them. You do not write code yourself:
you dispatch one implementer subagent per task, review between tasks, and keep
the branch green. Read, in full, before dispatching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md` — the spec.
   §1 (why), §5 A1/A2/B1–B4, §7 (gotchas), §9 (assumptions).
3. `docs/superpowers/plans/2026-08-18-pipeline-assurance-A-B.md` — the plan you
   are executing. 12 tasks, TDD, code written out, exact signatures verified.

Then invoke the skill **`superpowers:subagent-driven-development`** and follow it
for every task: fresh implementer subagent → spec-compliance review → code-quality
review → next task. Do not skip a review because a task "looks small".

## What this builds

The live build waves (five IG waves, 2026-08-10 → 08-18) found 51 things; 64% of
the real ones needed no network and no DB. This plan builds the foundation that
catches that class offline: a **recorded-reality fixture corpus** with a capture
command and manifest (A1/A2), and five **registry-derived matrix tests** — catalog
classification sweep, `LinkRouter` gate×account, signup pairing, sector fold,
handle/subdomain property (B1–B4). B5 (connect contract for every surface) is
Plan 2, not this one.

**Nothing under `app/` changes behaviour.** The only `app/` additions are dev
tooling (`app/Console/Commands/Fixtures*Command.php`, `app/Support/Fixtures/`).
**A red matrix test is a finding, not a bug to fix** — it goes in the report or a
baseline file, never into an edit of `app/` or a weakened assertion.

## Model policy — this is not optional

You are on Opus. **Every subagent you spawn inherits Opus unless you say
otherwise, and an all-Opus fan-out has tripped the session limit before.** Set
`model:` explicitly on every `Agent(...)` call:

| Work | `model:` |
|---|---|
| Implementer for a plan task (write test → run → implement → run → commit) | `sonnet` |
| Spec-compliance reviewer, code-quality reviewer | `sonnet` |
| Any read-only lookup — "what does X return", "which tables does `setupIngestTables()` create", grepping the tree | `haiku` |
| Task 4 pre-read of `InstagramScraper::fetchProfileResult()` round-trips (the "read the scraper before writing the fake" step) | `haiku`, then hand the findings to the `sonnet` implementer |
| Task 6's hand-written probe URLs (~62 surfaces, each needs a realistic URL that matches the detector's `registrable_key`/`path_pattern`) | `sonnet` |
| Task 6/9 report writing (`docs/reviews/…RESULTS.md`, findings prose) | `sonnet` |

Reserve **your own** Opus context for: reading reviews, deciding wave order,
merging lanes, judging whether a red test is a finding, and the handoff. If you
catch yourself about to run a grep, dispatch `haiku` instead.

## Workspace — two worktrees, strict file ownership

The spec + plan already live on branch `docs/pipeline-assurance-spec-2026-08-18`
(worktree `.worktrees/pipeline-assurance-spec`, tip `6f9526d3b` = `origin/development`
+ two docs commits). Branch the work off **that tip** so the docs travel with it.

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git fetch origin development
git worktree list                              # confirm .worktrees/pipeline-assurance-spec exists
git worktree add .worktrees/pa-lane-a -b feat/pipeline-assurance-ab-2026-08-18 docs/pipeline-assurance-spec-2026-08-18
git worktree add .worktrees/pa-lane-b -b feat/pipeline-assurance-b-matrices-2026-08-18 docs/pipeline-assurance-spec-2026-08-18
```

For **each** worktree, before its first test run:

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend/.worktrees/pa-lane-a"   # then pa-lane-b
cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env    # untracked; artisan cannot boot without it
composer install                                                # NOT dump-autoload — a fresh worktree has a stub vendor/
php artisan --version                                           # must print before any test runs
```

**Why two worktrees:** concurrent subagents in ONE checkout share the git index
and have clobbered each other's staged files before (memory: "Agents share
INDEX"). Lane A and Lane B touch disjoint files, so two worktrees let them run
concurrently with zero contention; you merge B into A at the end (no conflicts
by construction — see ownership map).

**Never** work in the main checkout (peers share it and can switch its branch
mid-task). **Never run `git stash`** anywhere. **Never** run `php artisan
boost:install`.

## Waves and lanes

```
LANE A — foundation (worktree pa-lane-a), STRICTLY SERIAL — every task edits the same files
  Task 1  Recorded loader + manifest + move shop fixtures
  Task 2  FixtureManifest / FixtureRedactor / FixtureStore
  Task 3  fixtures:capture --from=file|url, fixtures:verify, manifest guard test
  Task 4  fixtures:capture --from=db|live (spend gate)      ← haiku pre-read of the scraper first
  Task 5  FixtureMutator
  Task 11 seed the corpus (free arms) — billed arms STOP AND ASK (see gates)

LANE B — matrices (worktree pa-lane-b), each task ONE agent, may run concurrently with Lane A
  Task 6  B1 catalog classification sweep + probe URLs + known-invisible ratchet + RESULTS report
  Task 7  B2 LinkRouter gate × account matrix
  Task 8  B2 signup pairing matrix
  Task 9  B3 sector fold table
  Task 10 B4 handle/subdomain property test
  (6–10 are independent of each other; run at most TWO Lane-B implementers at once —
   Task 6 is the long one, start it first)

MERGE   — you: merge feat/pipeline-assurance-b-matrices-… into feat/pipeline-assurance-ab-…
Task 12 — docs + full `composer test` + `pint --test` + PR   (Lane A worktree, after merge)
```

**File ownership (an agent may only create/modify paths in its own lane):**

| Lane A owns | Lane B owns |
|---|---|
| `tests/fixtures/recorded/**`, `tests/fixtures/shop/**` (moved) | `tests/fixtures/catalog/**` |
| `tests/Support/Fixtures/**` | `tests/Support/Catalog/**` |
| `app/Support/Fixtures/**`, `app/Console/Commands/Fixtures*Command.php` | — |
| `tests/Unit/Support/{RecordedLoader,FixtureManifest,FixtureRedactor,FixtureMutator}Test.php` | `tests/Unit/Profile/SectorFoldTableTest.php` |
| `tests/Feature/Console/FixturesCaptureCommandTest.php` | `tests/Feature/Platforms/{CatalogClassificationSweep,LinkRouterGateMatrix}Test.php` |
| `tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php` | `tests/Feature/PreAccount/{SignupPairingMatrix,HandleSubdomainProperty}Test.php` |
| the three shop-test path edits (Task 1) | `docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md` |

Anything not listed → the agent stops and asks you. Nobody edits `app/Services/**`,
`app/Catalog/**`, `app/Models/**`, `config/**`, `supabase/**`, `bootstrap/catalog/**`.

## Blocker gates — STOP and ask Josh, do not decide

1. **Spend.** Task 11 Step 3 (`fixtures:capture --from=live --confirm-spend` for
   `instagram` ×2 and `places` ×1) bills Apify/Places. Run Steps 1–2 (free), then
   STOP, report what was captured, and ask Josh to confirm the three billed
   captures. If he is not there, leave Step 3 undone and say so in the handoff.
2. **A matrix cell disagrees with the plan.** If Task 7's gate matrix, Task 8's
   pairing table, or Task 10's fixed-point property goes red on a cell the plan
   says should pass, that is a *finding*. The implementer records it (test name,
   input, actual vs expected) and stops. You put it in the report and the handoff.
   Nobody edits `app/`, nobody edits the expected value.
3. **Task 6 wants to fix a detector.** It doesn't. Invisible surfaces go in
   `known-invisible.php` and the report. Fixing detectors is a separate decision.
4. **Anything touching auth, money, a migration, or the public wire.** Not in
   scope; if an implementer thinks it must, stop.

## Verification rules the implementers must follow (put these in every implementer prompt)

- Run tests **by path**: `php artisan test tests/Feature/Platforms/FooTest.php`.
  `--filter` is broken alongside Pint in this repo.
- Red before green: run the new test file BEFORE implementing and paste the
  failure; then implement; then paste the pass. A test that was never red proves
  nothing.
- No cross-file global helper functions in test files (parallel-suite rule);
  file-local Pest globals must carry a file-unique prefix (`sweep*`, `gate*`).
- Never negated `toContain`; don't chain `expect()` when proving non-vacuity.
- New Feature test files go in EXISTING dirs only (`tests/Feature/{Platforms,PreAccount,Console,Architecture}`) —
  a new subdir turns `AuditPipelineIntegrityTest` red.
- Commit after every task with the plan's commit message; verify with
  `git log --oneline -1` and `git status --short` (must be clean).
- Feature suite runs SQLite; anything that reads a constraint belongs in
  `tests/Postgres/` — not this plan.

## Task-specific notes for your implementer prompts

- **Task 1:** the manifest hashes must be computed from the files AS MOVED
  (`shasum -a 256`); the guard test in Task 3 will catch a wrong hash — that is
  the guard working, fix the manifest not the test.
- **Task 3:** `FixturesCaptureCommand` injects `SafeUrlFetcher` — run
  `tests/Feature/Architecture/OutboundHttpGuardTest.php` after, it must stay green.
- **Task 4:** BEFORE the implementer writes the `Http::fake` sequence for the
  live test, dispatch `haiku` to read `app/Services/Platforms/InstagramScraper.php:52-200`
  and `app/Services/Platforms/Actors/*Adapter.php` and report: the real Apify
  host(s), the exact number of HTTP round-trips in `fetchProfileResult()`, and the
  config key the token is read from. The fake must mirror that; the expected file
  list (`live.acme.1.json`, `.2.json`, …) follows the round-trip count.
- **Task 6:** the sweep's first run is SUPPOSED to fail twice (missing probe URLs,
  then newly-invisible). The implementer fills `probe-urls.php` with realistic URLs
  that match each surface's detector (read `bootstrap/catalog/compiled.php` for
  `registrable_key`/`path_pattern`), re-runs, then copies the invisible list into
  `known-invisible.php`, re-runs to green, then prints the split with
  `CATALOG_SWEEP_REPORT=1` and writes the report. Headline count of invisible
  surfaces goes in your handoff.
- **Task 7:** the two end-to-end tests may need extra `setup*Table()` calls if
  `seedBooking` touches a table the list lacks — add the setup, never relax
  `->not->toBe('custom')`.
- **Task 9:** group-1 rows are corrected TO WHAT THE TAXONOMY MAP SAYS
  (`KEYWORD_SECTORS`, `INSTAGRAM_CATEGORY_SECTORS`); an obviously-trade word that
  maps to nothing moves to a KNOWN GAP dataset. `app/` untouched.
- **Task 10:** a fixed-point failure on any ugly name is a SIGNUP-1-class finding →
  gate 2.
- **Task 12:** after merging Lane B, run `composer test` (serial, ~15–20 min) and
  `php artisan pint --test`. Pint baseline is not perfectly clean repo-wide — only
  files this branch touched must be clean (`php artisan pint --test <paths>`).

## Merging Lane B into Lane A

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend/.worktrees/pa-lane-a"
git merge --no-ff feat/pipeline-assurance-b-matrices-2026-08-18 -m "merge: B1–B4 matrix tests into pipeline-assurance-ab"
php artisan test tests/Feature/Platforms/CatalogClassificationSweepTest.php tests/Feature/Platforms/LinkRouterGateMatrixTest.php tests/Feature/PreAccount/SignupPairingMatrixTest.php tests/Unit/Profile/SectorFoldTableTest.php tests/Feature/PreAccount/HandleSubdomainPropertyTest.php tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php
```

Conflicts should be impossible (disjoint files). If one appears, an agent broke
ownership — resolve by taking the owning lane's version and note it in the handoff.

## Deliverable

1. PR from `feat/pipeline-assurance-ab-2026-08-18` → `development`, title
   `test: pipeline assurance — recorded fixture corpus + registry-derived matrices (A1–A2, B1–B4)`.
   Body: sweep headline (connectable / link-only / invisible counts), any matrix
   findings (gate 2), whether Task 11 Step 3 ran, and the reviewer verdicts per task.
2. Update the spec's Status line (Task 12).
3. **Handoff message** in this session, ≤ 25 lines: what shipped, what is red and
   why (finding vs. incomplete), what Josh must decide (billed captures), and the
   one-line entry point for Plan 2 (`docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md` §5 B5, C1–C5).

Do not deploy anything. Do not touch dev or prod DBs except read-only in
Task 11 Step 2 (`--from=db` on dev, or `cloud tinker development` + `--from=file`).
