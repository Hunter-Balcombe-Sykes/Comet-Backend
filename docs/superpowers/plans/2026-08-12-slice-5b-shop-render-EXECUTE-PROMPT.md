# EXECUTE PROMPT — Slice 5b: implement, review, merge

**5b's design work is done.** The spec and a ten-task implementation plan already
exist. This prompt executes them subagent-driven through review and merge; it does
not re-open the design.

- Spec: `docs/superpowers/specs/2026-08-12-slice-5b-shop-render-design.md`
- Plan: `docs/superpowers/plans/2026-08-12-slice-5b-shop-render.md` (Tasks 1–10)
- Kickoff that produced them: `docs/superpowers/plans/2026-08-12-slice-5b-shop-render-KICKOFF-PROMPT.md`

**Prerequisite: slice 5a is merged and deployed** (`9e5bf3a6a`, 2026-08-13), and its
backfill has run. The spec's §1 is the re-derived entry gate — it passed on
2026-08-13. Re-run it anyway if more than a day has passed.

Runs concurrently with **slice 3** (parent §4.3 rule 1 — `service` vs `product` are
distinct kinds). See "Living beside slice 3" below; it is not optional reading.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-5b-shop-render`** so it is
identifiable in Remote Control instead of appearing as a machine name.

## The worktree

Work in `/Users/joshuahunter/Herd/Side Street/backend/.worktrees/slice-5-shop`, on
branch `feat/slice-5b-shop-render` (already created off `origin/development` at
`5bd607377`). **Run every command from that directory. Do not `cd` to the main
checkout**, which sits on `development` and is shared.

`git worktree list` shows three live worktrees. That is normal — this repo has
already lost uncommitted work to a concurrent merge (parent §4.3 rule 6).

**Never `git stash`.** The stash stack is shared across all three worktrees and a
peer session may pop your entry. Use a temporary WIP commit instead.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-12-slice-5b-shop-render-design.md` — the design of
   record. §1 carries the live measured values; §3.5 carries the URL composition and
   the four judgement calls inside it.
3. `docs/superpowers/plans/2026-08-12-slice-5b-shop-render.md` — **Global Constraints
   first**, then the tasks.
4. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — parent
   programme. §3 **Invariants**, §4.3 concurrency, §8.3 the `mergeInto()` hazard,
   §9.2 the three cache lanes, §10.

## Rule zero — the plan is a plan, not a fact

Parent invariant #5: **no slice may cite another slice's checkpoint as evidence for
its own claims.** Every figure in the plan was measured on 2026-08-13 against dev
(`glncumufgaqcmqhzwrxm`). If a task's premise disagrees with what you find in the
code or the database, **the code and the database win** — stop, say so, and correct
the plan in place rather than implementing around it.

Two premises worth re-checking with your own eyes before Task 6, because the whole
retirement rests on them:

- `PoolResolver` ships `frames: []` for every kind except `media`. If that is no
  longer true, Task 6's frames change needs rewriting, not extending.
- `PoolResolver` hardcodes `'popularityRank' => null`. Dev holds 34 scored
  `shop_product` rows that the legacy wire published and the pool currently does
  not. If that count is now zero, say so — but still carry the field.

## Execution model — waves, not a queue

Dispatch **one fresh subagent per task** (`superpowers:subagent-driven-development`).
Tasks whose file sets are provably disjoint run **concurrently, in the same
worktree**. Review between waves; never inside one.

```
WAVE A  ──  Task 1  ·  Task 2  ·  Task 5          (3 concurrent)
WAVE B  ──  Task 3  ·  Task 4  ·  Task 6          (3 concurrent)
WAVE C  ──  Task 7                                 (alone — same file as Task 6)
WAVE D  ──  Task 8                                 (alone — needs 1,4,6,7)
WAVE E  ──  Task 9                                 (docs)
WAVE F  ──  Task 10                                (verify + merge)
```

Six waves instead of ten serial tasks.

**Why these groupings — the file sets, which is the whole basis for running them
together:**

| Wave | Task | Files it may touch |
|---|---|---|
| A | 1 | `app/Site/Pools/PoolRegistry.php`, `tests/Feature/Content/ShopPoolTest.php` |
| A | 2 | `app/Services/Shop/ShopContentWriter.php`, `tests/Feature/Content/ShopRetirementTest.php` |
| A | 5 | `app/Site/Pools/ShopOutboundUrl.php`, `tests/Unit/Site/Pools/ShopOutboundUrlTest.php` |
| B | 3 | `app/Services/Shop/ShopContentWriter.php`, `tests/Feature/Content/ShopRetirementTest.php` |
| B | 4 | `app/Console/Commands/ProvisionShopPinsCommand.php`, `tests/Feature/Content/ShopPinProvisioningTest.php` |
| B | 6 | `app/Site/Pools/PoolResolver.php`, `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`, `tests/Feature/Content/ShopPoolPayloadTest.php` |

Within each wave, no two tasks name the same file. Task 3 follows Task 2 because
they share both files; Task 7 follows Task 6 for the same reason.

Dependencies the waves satisfy: Task 3 needs 2 · Task 4 needs 1 · Task 6 needs 1
and 5 · Task 7 needs 6 · Task 8 needs 1, 4, 6, 7.

### Concurrency rules — give these to EVERY subagent, verbatim

Three subagents sharing one working tree will corrupt each other's work unless all
three of these hold. They are not style preferences.

(The hazard is your own concurrent subagents, which share this worktree's working
directory and index. The slice-3 worktree has its own of both and cannot be staged
into your commits — its collision surface is `PoolRegistry.php` at rebase time,
covered further down.)

1. **`git add <explicit paths>` only. Never `git add -A`, never `git add .`** — it
   would stage a peer's half-written file into your commit.
2. **`php artisan pint <your paths>`. Never `pint --dirty`** — `--dirty` formats
   every dirty file in the tree, including a peer's in-progress edit, and you would
   then commit or clobber it.
3. **Run only your own test files: `./vendor/bin/pest <path>`. Never `composer
   test` inside a wave** — it clears the config cache, which races every peer.
   Full-suite runs happen between waves, by the orchestrator.

Also: **do not invent new Pest global helper names.** Pest helpers are global and
collide fatally if declared twice. The plan pre-names every helper each task may
add (`shopCollection`, `shopBlob`, `storefront`, `productIn`, `shopStore`,
`shopProduct`); grep `tests/` for the name before declaring it, and if it already
exists, reuse it.

If a commit fails on `index.lock`, wait two seconds and retry once. If it fails
twice, stop and report — do not delete the lock file.

### Review protocol

After each wave, before dispatching the next:

1. **Read the diff yourself** — `git diff <wave-start-sha>..HEAD`.
2. **Dispatch an independent reviewer subagent** per task in the wave, given the
   task's section of the plan and the diff, asked specifically: does the
   implementation match the plan's stated intent, are the tests real assertions
   rather than vacuous ones, and does anything violate the Global Constraints?
3. **Run the full suite once** for the wave: `composer test`.
4. **STOP — report to the user** with what landed, what the reviewers said, and
   what the next wave will do. Wait for sign-off.

Vacuous tests are the specific failure mode to hunt: a negated `toContain`, a
`toThrow` on an interface, an outcome-only assertion on a concurrency test that
would still pass with the lock deleted. This repo has been bitten by all three.

## Living beside slice 3

**Slice 3a is live in `.worktrees/slice-3-services` and is at its final gate**
(full suite, PG lane, PHPStan, schema lane) as of 2026-08-13.

**A worktree isolates files. It does not isolate the dev database.** Both branches
share `glncumufgaqcmqhzwrxm`.

- **Data is safe** — parent §4.3 rule 1: `resolveItems()` is kind-scoped, 3a writes
  `service`, you write `product`. Neither can destroy the other's rows.
- **`PoolRegistry.php` is the one file you will both edit.** 3a adds `services` to
  `POOLS` / `PAGE_KEYS` / `PAGE_LABELS` / `SECTION_SHAPE` and rewrites the docblock
  sentence to *"Sell / Menu are NOT here"*. You delete "Menu" from that same
  sentence and add `shop`. It is a **union merge, not a design conflict** — 3a's
  `SECTION_SHAPE['services']` is byte-identical to what 5a decided for products.
- **3a will merge first.** Rebase onto `development` at Task 10 and resolve there.
  **Read both hunks; take neither side wholesale.** A union merge that silently
  drops half a const array still passes every test written by the branch that added
  the other half. Re-run `PoolRegistryTest`, `ShopPoolTest` and
  `ShopPinProvisioningTest` **after** resolving, not before.
- **Do not edit anything in the slice-3 worktree**, and do not "fix" a slice-3 file
  you happen to notice. Check `git worktree list` plus the sibling's `git status`
  before assuming any file is free.
- **Task 10 step 3 runs `content:provision-shop-pins` against dev.** Both slices
  write `site.sections` / `site.section_items`, under different keys
  (`pool:services` vs `pool:shop`), so the writes are insert-only and cannot
  collide. But if 3a is mid-backfill your counts will be hard to read — ask before
  running, and note in the checkpoint whether 3a's backfill was in flight.

## Non-negotiables

- **No DDL.** This slice needs none. The pre-assigned block
  `20260813110000`–`20260813119999` goes **unused**; say so rather than filling it.
- **Cache invalidation is three lanes** — `BuildState::bump()`, touch
  `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check enforces
  this despite the docblock claiming one. Assert all three directly.
- **Task 8 must be last of the code tasks.** It deletes the legacy shop keys. If it
  lands before Tasks 6 and 7, the retirement drops 271 gallery images, 50
  descriptions, 49 vendors, every variant and 34 live popularity ranks.
- **Tests run SQLite, production is Postgres.** Run `composer test:pg` at Task 10
  even though CLAUDE.md does not mandate it here — 5a was bitten twice in this exact
  code, and Task 3 rewrites a subquery into `NOT EXISTS`.
- **`site.sites` has no `deleted_at`.** Filtering on it is a silent no-op on SQLite
  and a 42703 on Postgres. Slice 2 shipped that bug.
- **Never push to `production`.** The prod deploy is gated on partna-monorepo
  landing its side of the wire retirement.

## The one thing that can go badly wrong

**Task 8 retires a live public wire, and the repo that renders Shop pages is not on
this machine and not visible to `gh`.** Every Shop page renders empty from the
moment this deploys until partna-monorepo reads `profile.pools.shop`.

That is the owner's accepted trade (2026-08-13), taken with slice 2's precedent in
view — *"Backend-only execution; the frontends are told, not designed around"*. It
is not yours to re-decide. But it **is** yours to state plainly in the wire manifest
and the checkpoint, as a required consumer action rather than a suggestion.

If at Task 8 you find evidence the monorepo has not moved and the owner is not
expecting the blank page, **stop and raise it** rather than shipping and noting it.

## If reality diverges, update the downstream prompts — do not just note it

A checkpoint is not a communication channel: parent invariant #5 forbids any slice
citing another's checkpoint, so a discovery recorded only in yours is one the next
session will never act on. **Edit their prompt.**

Task 9 already owes edits to `slice-4-menus`, `slice-6-reviews` and
`slice-7-teardown`, plus two in-place corrections to the parent spec (§9.8's
narrowing, §16.9's false "inert" claim about `site.shop_brands`). If you discover
anything further, add it there in the same pass. Edit in place; say the fact, not
the story.

If you find something that invalidates another slice's *approach* rather than a
detail — stop and raise it rather than rewriting their scope unilaterally.

## Process — stop at every gate

1. **Recon.** Read the four documents. Re-run the spec's §1 entry gate if more than
   a day has passed. Confirm the worktree and branch. **STOP — sign-off.**
2. **Wave A** (Tasks 1, 2, 5 concurrent) → review protocol → **STOP.**
3. **Wave B** (Tasks 3, 4, 6 concurrent) → review protocol → **STOP.**
4. **Wave C** (Task 7) → review protocol → **STOP.**
5. **Wave D** (Task 8) → review protocol → **STOP.** This is the wire change; review
   it harder than the rest.
6. **Wave E** (Task 9) — manifest, parent-spec corrections, downstream prompts,
   checkpoint. **STOP.**
7. **Wave F** (Task 10) — full suite, `composer test:pg`, PHPStan, Pint, rebase and
   resolve `PoolRegistry`, run the provisioning command on dev, live SQL assertions,
   a real `PoolResolver::resolve()` call with output pasted, log + Nightwatch scans.
   **STOP — explicit sign-off**, then merge and push to `development`.

## Definition of done

A dev account's Shop page renders from `profile.pools.shop` with its products in the
owner's chosen order, grouped into store cards rebuilt from the `collections` map;
the outbound URL is composed by the backend and a `referral_query` + `checkout`
combination is proven correct **by test**, since dev data carries neither; the pool
payload has an enforcement point equivalent to `SHOP_PRODUCT_ALLOWLIST`; every
`pool:shop` section carries the corrected rule; a re-added product returns; the
coverage gate returns 0; checkpoint and wire manifest committed.

The legacy `/integrations` shop keys **are** retired here. If at merge that turns
out to be unshippable, mark the criterion **unmet** rather than ticking it.
