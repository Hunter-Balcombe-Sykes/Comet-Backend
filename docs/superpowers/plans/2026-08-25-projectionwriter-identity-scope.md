# ProjectionWriter — Identity Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make identity resolution and cache refresh scale with the size of the change instead of the size of the user's catalogue, without weakening the cross-source merge invariant.

**Architecture:** A new pure class `App\Content\Identity\IdentityScope` computes the *connected component* of touched coords over the identity-signature graph. `resolveItemsLocked()` runs the existing `Resolver` over that component instead of the whole kind, and `refreshItemCaches()` is given only touched item ids. A config flag keeps today's whole-kind path one env change away. No schema change.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase). Fast lane = SQLite; identity behaviour is proven in `tests/Postgres/` (`composer test:pg`).

**Spec:** This plan is its own design record. Its source brief is
`docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope-PLAN-PROMPT.md`
(§2 verified facts, §3 the options, §4 the constraints). Findings:
`#CACHE-2` (≡ `#SCALE-7`), `#CACHE-4`, `#SCALE-8`, `#SCALE-9` (≡ `#API-7`), `#SCALE-12`,
all from the 2026-08-25 overnight-run sweep, deferred out of
`audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE.md` as unit 1.

---

## Decisions taken in the planning session (2026-08-25, attended)

The PLAN-PROMPT §3 asked three questions. All are answered; the reasoning is §A below.

1. **Boundary: option B (per-key closure) AND option C (amplification fixes), one branch.**
   Josh overrode the prompt's "C first, B behind it" recommendation and asked for all five
   findings in one pass. Consequence recorded honestly: the risky half and the safe half ship
   together, so the safe half can no longer be kept by reverting the risky one. **Task 2's
   config flag exists to restore that option.**
2. **Closure is TRANSITIVE to fixpoint, not one hop.** Proof in §A.1. This is a correctness
   condition, not thoroughness.
3. **When the cap bites: fall back to a FULL whole-kind resolve and log loudly.** Never
   truncate-and-proceed. Rationale in §A.2.
4. **Computed in PHP, no migration.** Signature equality is `KeyClass::canonicalise()`, and
   `content.identity_keys.key_value` stores a MIX of raw and pre-canonicalised values, so no
   SQL predicate can express it today. See §A.3 and §E.
5. **`#SCALE-12` is measure-first, disposition-later.** It may close WONTFIX. See Task 7.
6. **AMENDED 2026-08-25 during execution (§A.4):** the closure seeds from the user's `same`
   rulings as well as the touched coords, and the flag-off path must be byte-for-byte. Both came
   out of a Task 2 BLOCKED report — see §A.4.
7. **This plan does NOT wait for `EXECUTE-PART-3.md` (13g)** — revised 2026-08-25 after reading
   13g in full. No functional dependency either way; the only interaction is 13g's measurement,
   which `PARTNA_CONTENT_IDENTITY_SCOPE=false` protects. See §D.

---

## Global Constraints

- **No Laravel migration files.** Composer guard rejects them. This plan needs **no** schema
  change at all; if one becomes necessary it is a separate `supabase/migrations/` unit with its
  own review (PLAN-PROMPT §4).
- **`composer test:pg` is MANDATORY for every task that touches
  `app/Ingest/Projection/ProjectionWriter.php`**, not optional. The PG stand-in DDL is
  hand-written and drifts silently from writer changes; slice 5a turned it red for 7 tests and
  two reviews missed it on a green SQLite run (`CLAUDE.md` §Workflow).
- **The advisory lock stays `identity:{user_id}:{kind}`.** Narrowing the *computation* does not
  narrow the *lock*: the protected set is still "every live source item of this (user, kind)",
  which a racing writer may GROW mid-computation (`ProjectionWriter.php` `withIdentityLock()`
  docblock). Do not key the lock on the component.
- **Callers must not wrap `withIdentityLock()` in `DB::transaction()`** — it degrades to a
  SAVEPOINT and silently takes the outer transaction's lifetime. A `LogicException` guard
  enforces this; do not remove it.
- **No try/catch that RECOVERS inside `$work`** — poisons the transaction with 25P02. This repo
  has shipped that defect three times (`ItemSlugAllocatorSavepointTest`).
- **Do not touch `recordCandidates()`'s insert loop.** Its N+1 (`#CACHE-1` ≡ `#SCALE-11`) belongs
  to unit 13g. This plan changes only what is PASSED to it.
- **Do not "fix" `bumpSite()` into calling `SiteCacheLanes::bust()`** — it is lane-1-only by
  design (`CLAUDE.md` §Content pools).
- **Record ordering is an invariant.** Records are read `orderBy('rv.first_seen_at')` THEN
  `orderBy('rs.key')`; the `rs.key` tiebreak is mandatory (LIMIT/OFFSET paging over a non-unique
  order silently skips and duplicates rows). `writeFacets()`'s per-column `array_replace` fold
  lets the LAST-processed record win each column. Reordering the read silently changes which
  record's headline a page shows.
- **A silent cap is a defect.** Every cap in this plan logs `user_id`, `kind`, `touched_count`
  and `component_size` when it bites.
- **Test helper names must be file-unique.** `tests/Unit/Content/ResolverTest.php` already
  defines global `idItem()` / `idKey()`. Redefining either name in a new Pest file breaks the
  parallel runner. New files use `scopeItem()` / `scopeKey()`.
- **Every cache key carries a TTL.** Never `Cache::forever()` (instance-wide `volatile-lru`).
  This plan adds no cache keys, but the constraint binds any that appear.

---

## §A — The design, and why it is correct

### A.1 The closure rule and its proof

Build a graph over the user's live source items of one kind:

- **Node** = one live `content.source_items` row (identified by its `coord`).
- **Edge** A—B when A and B share **any** canonicalised key signature
  `key_class . '|' . KeyClass::canonicalise(key_value)` — **all** tiers, with **no**
  `minLength()` / `appliesTo()` / poison filter applied — **or** when a stored
  `content.identity_decisions` row with `verdict = 'same'` names both.
- **Component** = the connected component containing the touched coords, taken to **fixpoint**.

The unfiltered breadth is deliberate and mirrors `Resolver::poisonedKeys()`, whose own comment
says: *"Deliberately no tier()/minLength()/appliesTo() filter here, unlike keyIndex() below —
this poisons more broadly than the index matches, which can only SUPPRESS merges, never create
a false one. Do not 'symmetrise' this into a weaker guard."* A closure narrower than the poison
scope would miss a poisoning sibling. **This is the single most important line in the plan.**

**Claim.** Resolving the component in isolation yields, for every coord in the component, the
same group as resolving the whole kind.

**Proof.** Let X be a live source item of this kind that is OUTSIDE the component.

1. *X cannot union with anything inside.* `Resolver::unionAll()` only ever unions coords that
   appear together in a `keyIndex()` bucket, i.e. that share a signature. Sharing a signature
   with an inside coord makes X inside. Contradiction.
2. *X cannot change poisoning inside.* `poisonedKeys()` poisons a signature S when one source
   contributes ≥2 items carrying S. If X carries a signature S that also appears inside, X is
   inside. So X carries no signature present inside, and cannot poison one.
3. *X cannot become a candidate with anything inside.* `Resolver` step 5 emits a `Candidate`
   only for two coords in the same evidential `keyIndex()` bucket — again a shared signature.
4. *X's user decisions cannot change the inside grouping.* A `same` decision naming X and an
   inside coord is itself a closure edge, so X would be inside. For a `different` decision
   `(X, Y)`: `DisjointSet::wouldViolateCut()` blocks a union edge (a,b) only when
   `{root(X), root(Y)} == {root(a), root(b)}`. For that to block a union between two INSIDE
   coords, X or Y would have to share a component with an inside coord — which by (1) makes it
   inside. Contradiction. And `wouldViolateCut()` deliberately does not vivify unknown
   elements, so an omitted outside coord cannot auto-create a phantom singleton.

Therefore the inside grouping is independent of every outside item. ∎

**Why one hop fails — CORRECTED 2026-08-25 after the Task 1 review.** The original draft of this
plan justified transitivity with a poisoning example, and that example was WRONG. It said: A shares
title T with B; B's source carries a second copy of T on C; a one-hop closure misses C, so T looks
unpoisoned and A merges with B. **That refutes itself.** Poisoning a signature S can only change
A's outcome if A itself carries S — otherwise S never unions A with anything. And if A carries S,
then every item carrying S, including the poisoning sibling, shares a signature with A and is
therefore ONE HOP from it. **Poison-relevant items are always at distance 1.**

The requirement is real; the reason is different, and the two guards are separate properties:

**Guard 1 — TRANSITIVITY, which exists for GROUP MEMBERSHIP, not poisoning.** The resolver is a
union-find, so grouping is transitive: A—B on signature S1 and B—C on a DIFFERENT signature S2 put
A, B and C in ONE group, and `bindGroup()` binds that whole group to a single item id. C is two
hops from A and shares nothing with it directly. A one-hop closure sees {A, B}, binds them, and
leaves C bound elsewhere — **splitting a group the full resolve keeps whole.** That is a missed
merge (a visible duplicate), and it can also flip WHICH item survives, because `bindGroup()` picks
the winner from the oldest anchor across the whole group — a group missing members can pick a
different winner than the full resolve would. Each further hop can extend the chain again, so the
walk must run to fixpoint. Pinned by the `follows the chain transitively to fixpoint` test
(a→b→c→d via ISRC → title → URL), which is the genuine counter-example.

**Guard 2 — UNFILTERED BREADTH, which is what poisoning actually requires.** `poisonedKeys()`
applies no `tier()` / `minLength()` / `appliesTo()` filter, deliberately, and poisons more broadly
than `keyIndex()` matches. So the closure must index signatures with those filters absent too:
a key too weak for `keyIndex()` to union on can still be the key that poisons. Those siblings are
one hop away, but they are only IN the set if the closure indexes the weak signature at all.
Pinned by the `includes weak keys the resolver itself would filter out` test.

**Net effect on the design: unchanged.** Transitive closure over unfiltered shared signatures is
still exactly the right rule — it satisfies both guards. Only the stated reason for the transitive
half was wrong, and a wrong reason in a design doc is how the next person "optimises" the walk back
down to one hop. The failure mode is also milder than the draft claimed: a too-narrow closure
splits groups (duplicates the user can see) rather than fabricating merges that hard-delete. The
proof in the four numbered points above is unaffected — it never relied on the poisoning story.

### A.4 Amendment, 2026-08-25 — what a BLOCKED Task 2 taught us

Task 2's first attempt came back BLOCKED: with the narrowing on,
`tests/Postgres/ProjectionWriterIdentityRaceTest.php` went from 8 passed to 6 failed. The
implementer's reading was that the test is unrealistic — it inserts an `identity_decisions` row
directly and then drives a race through `writeManualItem()` on a coord with no identity
relationship to the decided pair, relying on the old "any write resolves the whole (user, kind)"
behaviour to sweep them in. It proposed fixing the test's fixtures.

**Verified, and that reading is half right. The test was accidentally modelling a REAL hole.**

`IdentityDecisionController::store()` does reproject after recording a ruling — but it finds the
sources to reproject with:

```php
->join('ingest.sources as isrc', 'isrc.connection_id', '=', 'cs.connection_id')
```

an INNER join on `connection_id`. A **manual** source has no `connection_id` at all
(`ProjectionWriter::ensureManualSource()`: *"a manual source has none"*), so for two owner-added
items that query returns nothing, `$sourceIds` is empty, and **no reprojection is dispatched**.
Under the old whole-kind resolve that did not matter — the next write of any kind applied the
pending ruling. Under a touched-only seed, the owner's "these are the same" ruling on two
hand-added items would **silently never take effect.**

That is a real behaviour regression, narrow but real, and it is not the test's fault.

**Ruling — fix the invariant, not the fixture.** `IdentityScope::component()` seeds from the
touched coords PLUS every coord named by a live `same` ruling. Rationale:

- It closes the manual-item hole at the place the invariant lives, rather than patching one
  controller's join and hoping no other path records a ruling.
- Cost is bounded by the RULING count — owner-authored and small — not by catalogue size, so the
  narrowing's win survives.
- It cannot break the §A.1 proof: seeding more only ever GROWS the component, and the proof shows
  items outside a component cannot affect it. A larger component costs performance, never
  correctness; the limit case is the whole kind, which is exactly today's behaviour.
- `different` rulings are NOT seeded. A cut only ever suppresses a union, so it can only matter
  between coords that share a signature — already in the component whenever either side is
  reached.

**Second, separate defect from the same report — the flag-off path was NOT byte-for-byte.** The
attempt rewrote the closing re-read's query shape unconditionally, which broke three PG-lane tests
*even with `PARTNA_CONTENT_IDENTITY_SCOPE=false`*, because they hook that statement through a
`DB::listen` text match. The flag is this change's primary rollback, so "off" must mean the
original statement, unchanged. Task 2's Step 4 now carries both branches explicitly.

**Third seed source — UNBOUND source items (amendment 2, after Task 2's second BLOCKED report).**

The remaining two race-test failures were not instrumentation. `pgirScenario()` builds a live
`content.source_items` row with **`item_id IS NULL` against a live `content.item_anchors` row** — a
row that exists but has never been bound to an item. The whole-kind resolve REPAIRED that
incidentally on any run, because it rebound every live source item of the kind. A touched-only seed
never revisits it, so the row stays unbound forever.

**This is the same shape as the ruling hole above: the old resolve was silently acting as a repair
sweep, and narrowing removes the sweep.** Finding it twice is the signal — the seed's job is not
"what changed", it is **"everything that needs attention"**, which is three things:

1. what this run wrote (`$touchedCoords`),
2. what carries a pending `same` ruling (seeded inside `IdentityScope`),
3. **what is not yet resolved at all (`item_id IS NULL`).**

**Ruling: seed from unbound coords too, and take them from the read that already happens.**
`resolveItemsLocked()` already reads every live source item for the (user, kind) into `$rows` — that
read is one of the two the narrowing does not remove. Add `si.item_id` to its select and derive the
unbound coords in PHP. **Zero extra queries**, and in steady state the unbound set is EMPTY, so the
seed is unchanged and the narrowing's win is untouched. A user with unbound rows pays to repair
them, which is the correct trade.

Safety is the same argument as before: seeding more only ever GROWS the component, and §A.1 shows
items outside a component cannot affect items inside it. The limit case is the whole kind — today's
behaviour.

**Fourth finding — a merge orphans anchors OUTSIDE the component (amendment 3).**

Found by the Task 2 implementer as a 70-vs-71 assertion gap and verified against the schema. The
mechanism:

1. `content.item_anchors.item_id` REFERENCES `content.items (id)` **ON DELETE CASCADE**.
2. `mergeInto()` sets `superseded_by = kept` on the loser's anchors but leaves their `item_id`
   pointing at the loser, then repoints `content.source_items.item_id` with an **unscoped** UPDATE
   (`where('item_id', $discardedItemId)` — no component filter, by design), then hard-deletes the
   discarded item.
3. That delete cascades away **every anchor** that pointed at the loser.

Under the whole-kind resolve this was invisible: every affected coord was in the same pass, so
`bindGroup()` re-minted its anchor through `insertOrIgnore` before the run ended. **With a narrowed
component, a coord outside the component is repointed by that unscoped UPDATE but never
re-anchored.** It is left with a valid `item_id` and NO anchor — and the next touch on it reads an
empty anchor set, so `bindGroup()` mints a fresh item via `createItem()` and duplicates the row.

That is a genuine regression introduced by the narrowing, and it is the exact failure mode this
whole change exists to avoid. **It is fixed, not parked.**

**Ruling: re-mint the anchors where the merge destroys them.** After `mergeInto()` repoints
`source_items` and deletes the discarded item, insert an anchor for every coord now pointing at the
kept item that lacks one. `insertOrIgnore` makes it a no-op for coords that already have one. The
set is bounded by the KEPT ITEM's source items — a handful — not by the catalogue, so this is O(1)
in practice and runs only when a merge actually happens (rare in steady state).

Fixing it inside `mergeInto()` rather than by widening the seed is deliberate: the coords needing
repair are exactly the ones that merge repointed, which `mergeInto()` already knows. Widening the
seed to find them would mean reading anchors for the whole kind — reinstating the read the
narrowing removes.

**Note this is NOT one of the three "Standalone — do NOT bundle" hard-delete paths** (`ItemMerger::merge()`,
`StaffServiceManagementController::forceDestroy()`, `ContentRetireChannelKindCommand`). Those are
the UNLOCKED mutators. `mergeInto()` runs inside `resolveItemsLocked()`, under the advisory lock and
inside its transaction, and repairing an invariant this branch breaks is in scope for this branch.

**What this cost:** one BLOCKED task and one plan amendment. What it bought: a production hole
that no test in the plan would have caught, found before merge rather than by an owner whose
de-duplication ruling stopped working.

### A.2 The cap

`IdentityScope::MAX_COMPONENT = 2000`. On exceed: return the full coord list with
`capped => true`; the caller logs a warning and resolves whole-kind, exactly as today. The cap is
therefore a **performance** guard that degrades to slow-and-correct, never a correctness guard.
Truncate-and-proceed is forbidden by §A.1's proof — a truncated component can miss a poisoning
sibling, which is the false-merge path.

2000 is chosen as "far above any real catalogue, far below a pathological one". It is a config
value, not a magic number, so it can be tuned without a deploy.

### A.3 What does NOT get faster (state this honestly in the results)

Two whole-kind reads REMAIN, unchanged:

- the `content.source_items` ⋈ `content.sources` read (needed to know what exists and to map
  coord ↔ id), and
- the `content.identity_keys` read (needed to build the graph).

Both are indexed, return narrow rows, and were already tuned by `#SCALE-7` (the bind list became
a subquery rather than a materialised `whereIn`). **Removing them requires option B1** — a
`key_canonical` column plus a backfill, which is a separate migration unit (§E).

What DOES narrow is everything downstream, which is where the cost is:

| Stage | Today | After |
|---|---|---|
| `source_items` read | whole kind | unchanged |
| `identity_keys` read | whole kind | unchanged |
| `Resolver::resolve()` | O(n²) per key bucket | O(c²) over the component |
| `anchorSnapshot()` | every group | component groups |
| `bindGroup()` loop | every group | component groups |
| `recordCandidates()` | all candidates | component candidates |
| closing `source_items` re-read + UPDATEs | whole kind | component |
| `refreshItemCaches()` | **whole kind**, ~18 queries per 500 | **touched items only** |

Do not report this work as "X% faster" without the before/after query count and peak memory from
Task 8.

---

## §B — File structure

**Create**

- `app/Content/Identity/IdentityScope.php` — the pure closure. No DB, no clock, no network,
  matching `Resolver`'s contract so it stays re-runnable and unit-testable in the fast lane.
- `tests/Unit/Content/IdentityScopeTest.php` — the closure's behaviour, including the one-hop
  counter-example.
- `tests/Postgres/ProjectionWriterScopedResolveTest.php` — the differential: whole-kind vs scoped
  resolve must produce identical `itemByCoord`.

**Modify**

- `app/Ingest/Projection/ProjectionWriter.php`
  - `resolveItemsLocked()` (`:788`) — accept touched coords, narrow via `IdentityScope`.
  - `resolveItems()` (`:646`) — pass touched coords through.
  - `projectStream()` (`:281-283`) — pass `array_keys($projections)`; refresh touched only.
  - `writeManualItem()` (`:449-476`) — pass `[$coord]`; refresh touched only; **rewrite the
    `:468` comment** (see Task 3).
  - `refreshItemCaches()` (`:2304`) — batch the slug read (`#SCALE-9`).
  - the projection loop (`:186`) — bound `$projections` (`#SCALE-8`).
- `config/partna.php` — `content.identity_scope` block (flag + cap).
- `.env.example` — the two new keys, documented.

**Do not modify**

- `app/Content/Identity/Resolver.php`, `DisjointSet.php`, `KeyClass.php` — the resolver stays
  pure and unchanged. If a task wants to edit one of these, the design has drifted; stop.
- `recordCandidates()`'s body — 13g owns it.

---

## §C — Interfaces

```php
namespace App\Content\Identity;

final class IdentityScope
{
    /**
     * Coords whose resolution can differ when $touched changed.
     *
     * @param  list<SourceItem>  $items      every live source item of one (user, kind)
     * @param  list<Decision>    $decisions  the user's stored rulings
     * @param  list<string>      $touched    coords this run wrote
     * @return array{coords: list<string>, capped: bool}
     *         coords: the connected component, or ALL coords when capped
     *         capped: true when the component exceeded $max and the caller must resolve whole-kind
     */
    public function component(array $items, array $decisions, array $touched, int $max = self::MAX_COMPONENT): array;

    public const MAX_COMPONENT = 2000;
}
```

`ProjectionWriter` gains one private helper and two changed signatures:

```php
private function resolveItems(string $userId, string $kind, array $touchedCoords): array;   // was (string, string)
private function resolveItemsLocked(string $userId, string $kind, array $touchedCoords): array;
```

Both still return `array<string, string> coord => item id`. **The returned map now covers only
the component** (or the whole kind when capped or when the flag is off) — every consumer must
therefore stop treating it as "the user's whole catalogue for this kind". The two consumers are
`writeFacets()` (already scoped to `$projections`, unaffected) and `refreshItemCaches()` (Task 3
changes it).

---

## §D — Sequencing and the 13g overlap

`EXECUTE-PART-3.md` unit 13g (`:299-323`) does two things: batch the per-candidate
`insertOrIgnore` in `recordCandidates()` into one call (`#CACHE-1` ≡ `#SCALE-11`), and cap the
O(m²) candidate generation (`#SCALE-10` ≡ `#CACHE-6`). It is explicitly forbidden from changing
the resolution scope, the lock, the transaction boundary, or which candidates are considered.

**Verified 2026-08-25: NONE of the four chained runs have started** — no `RESULT-*.md`, no
`audit-fix/pre-launch-hardening-2026-08-25` or pre-pilot branch, tree clean on `development`.
PART 3 is the LAST of four strictly-sequential runs, so waiting for it means waiting for the
whole chain.

**There is no functional dependency in either direction.** This plan changes what is PASSED to
`recordCandidates()`; 13g changes how it WRITES and how many candidates are generated. Different
lines, complementary guards.

**The one real interaction is 13g's MEASUREMENT, and the flag resolves it.** 13g must "show the
candidate-count growth curve at two or three input sizes to show the quadratic is actually
capped". Measured against an already-narrowed input that curve looks flat, and 13g could be
closed via PART 3 §5's `WONTFIX — premise refuted` path on a false premise.

**Decision (2026-08-25, revised after reading 13g): this plan does NOT wait for PART 3.**
It runs on its own branch off `development` now. To protect 13g:

> **If this plan has already landed when 13g runs, 13g MUST take its candidate-growth
> measurement with `PARTNA_CONTENT_IDENTITY_SCOPE=false`.** That restores the un-narrowed
> resolve byte-for-byte, so the quadratic it is capping is the real one. Add this line to
> 13g's measurement step before running PART 3.

Residual risk: an ordinary merge conflict in one region of `ProjectionWriter.php`. Whichever
branch lands second rebases. When rebasing onto 13g, re-check that its cap did not change WHICH
candidates are considered — if it did, §A.1's proof needs re-reading before this ships.

## §E — Split out into its own unit (do NOT fold in)

- **Option B1 — the `key_canonical` column.** `ALTER TABLE content.identity_keys ADD COLUMN
  key_canonical text` + `CREATE INDEX (key_class, key_canonical)` + a backfill of every existing
  row, which would let the closure be a recursive SQL query and remove the two surviving
  whole-kind reads. This is a `supabase/migrations/` unit with its own review. **Its risk is
  specific and must be written into that unit:** the writer and the backfill must agree on
  `canonicalise()` exactly, or the closure under-reaches SILENTLY — which is the false-merge
  path from §A.1. Only open it if Task 8's measurement shows those two reads dominate.
- **The three unlocked hard-delete paths** named in `withIdentityLock()`'s "WHAT THIS DOES NOT
  COVER": `ItemMerger::merge()` (unreachable), `StaffServiceManagementController::forceDestroy()`,
  `ContentRetireChannelKindCommand`. `fix-flow.md`'s "Standalone — do NOT bundle" rule applies.
  **This plan does not make any of them worse:** they are unaffected by which coords the resolve
  considers, because they can already collide with a resolve that has computed a target on an id
  they delete. Narrowing the component reduces the number of ids a given resolve holds, so the
  collision window shrinks or stays equal. State this in the PR body; change nothing.
- **`#CACHE-2` ≡ `#SCALE-7` provenance.** `BACKLOG-TRIAGE.md:84` lists them as a duplicate pair,
  and `ProjectionWriter.php:813` shows `#SCALE-7`'s bind-list half is ALREADY implemented.
  Task 3 must verify what remains before claiming new work — `CLAUDE.md` records a measured ~40%
  already-fixed rate in this backlog tier.

---

## §F — Rollback

1. **Flag off** — `PARTNA_CONTENT_IDENTITY_SCOPE=false` restores the whole-kind path byte-for-byte,
   no deploy. This is the primary rollback and the reason the flag exists.
2. **Revert the branch** — no schema change, so a revert is complete. Nothing to un-migrate.
3. **Repair, if a false merge somehow ships** — `mergeInto()` hard-deletes, so a bad merge is only
   partially reversible. This is why Task 4's differential test gates the merge, and why the cap
   falls back rather than truncates. If it happens: flag off, then `content:refresh-item-caches`
   heals caches; the deleted `content.items` rows are NOT recoverable and the affected coords
   re-mint on the next projection run.

---

## Task 0: Branch, and check whether 13g has landed

**Files:** none (verification only)

**Interfaces:**
- Consumes: nothing
- Produces: the branch Tasks 1-8 commit to

**This is NOT a blocking gate** (§D). It records which side of 13g this branch is on, because
that determines who rebases and whether 13g's measurement needs the flag note.

- [ ] **Step 1: Record whether PART 3 has run**

```bash
ls audits/consolidation/2026-08-25-pre-launch-hardening/RESULT-PART-3.md 2>/dev/null || echo "PART 3 has NOT run"
git log --oneline -20 -- app/Ingest/Projection/ProjectionWriter.php
```

- **If PART 3 has NOT run** (expected as of 2026-08-25): proceed. Before PART 3 is launched, add
  the flag line from §D to 13g's measurement step in `EXECUTE-PART-3.md`, so its candidate-growth
  curve is measured against the un-narrowed resolve.
- **If PART 3 HAS run:** read what 13g changed before proceeding —

```bash
git log -p --oneline -- app/Ingest/Projection/ProjectionWriter.php app/Content/Identity/Resolver.php | head -300
```

  Confirm 13g did not change WHICH candidates are considered. If it did, re-read §A.1's proof
  against the new behaviour before writing any code.

- [ ] **Step 2: Cut the branch**

```bash
git checkout development && git pull
git checkout -b audit-fix/projectionwriter-identity-scope-2026-08-25
```

---

## Task 1: `IdentityScope` — the pure closure

**Files:**
- Create: `app/Content/Identity/IdentityScope.php`
- Test: `tests/Unit/Content/IdentityScopeTest.php`

**Interfaces:**
- Consumes: `App\Content\Identity\{SourceItem, IdentityKey, KeyClass, Decision}` (all existing,
  unchanged)
- Produces: `IdentityScope::component(array $items, array $decisions, array $touched, int $max = self::MAX_COMPONENT): array{coords: list<string>, capped: bool}`
  and `IdentityScope::MAX_COMPONENT = 2000`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Content/IdentityScopeTest.php`. Helper names are `scopeItem`/`scopeKey`, NOT
`idItem`/`idKey` — `tests/Unit/Content/ResolverTest.php` already defines those globally and a
redefinition breaks the parallel runner.

```php
<?php

use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\IdentityScope;
use App\Content\Identity\KeyClass;
use App\Content\Identity\SourceItem;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// IdentityScope is pure: no database, no clock, no network. Every test here is
// "these records with these keys, touching THIS one, must pull in THESE".

function scopeItem(string $coord, string $sourceId, string $kind, array $keys = []): SourceItem
{
    return new SourceItem($coord, $sourceId, $kind, $keys);
}

function scopeKey(KeyClass $class, string $value): IdentityKey
{
    return new IdentityKey($class, $value);
}

it('returns just the touched coord when it shares nothing', function () {
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toBe(['sp:acct:t1'])
        ->and($result['capped'])->toBeFalse();
});

it('pulls in an item sharing a signature, canonicalisation included', function () {
    // Punctuation differs; canonicalise() folds them to one signature.
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::Isrc, 'us-rc1-76-07839')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toHaveCount(2)
        ->and($result['coords'])->toContain('ap:acct:t9');
});

// THE ONE-HOP COUNTER-EXAMPLE (plan §A.1). A pulls in B via a shared title.
// B's OWN source carries a second copy of that title on C, which is what
// POISONS the key in Resolver::poisonedKeys(). A one-hop closure omits C, the
// key looks clean, and A merges with B — a merge the full resolve would never
// make, whose loser mergeInto() then HARD-DELETES.
it('pulls in a same-source sibling that poisons a shared key — one hop is not enough', function () {
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::TitleOnly, 'Wandering Star')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::TitleOnly, 'Wandering Star')]),
        scopeItem('ap:acct:t8', 'src-ap', 'track', [scopeKey(KeyClass::TitleOnly, 'wandering star')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toHaveCount(3)
        ->and($result['coords'])->toContain('ap:acct:t8');
});

it('follows the chain transitively to fixpoint', function () {
    // A—B on ISRC, B—C on title, C—D on url: touching A must reach D.
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [
            scopeKey(KeyClass::Isrc, 'USRC17607839'),
            scopeKey(KeyClass::TitleOnly, 'Wandering Star'),
        ]),
        scopeItem('c', 'src-c', 'track', [
            scopeKey(KeyClass::TitleOnly, 'Wandering Star'),
            scopeKey(KeyClass::CanonicalUrl, 'https://x.test/w'),
        ]),
        scopeItem('d', 'src-d', 'track', [scopeKey(KeyClass::CanonicalUrl, 'https://x.test/w')]),
    ], [], ['a']);

    expect($result['coords'])->toHaveCount(4);
});

it('follows a user "same" ruling even with no shared key', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'same')], ['a']);

    expect($result['coords'])->toHaveCount(2);
});

it('ignores a "different" ruling — a cut never widens the component', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'different')], ['a']);

    expect($result['coords'])->toBe(['a']);
});

it('includes weak keys the resolver itself would filter out', function () {
    // 'abc' is under TitleOnly::minLength() (12), so keyIndex() drops it — but
    // poisonedKeys() does NOT filter, so the closure must not either (plan §A.1).
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::TitleOnly, 'abc')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::TitleOnly, 'abc')]),
    ], [], ['a']);

    expect($result['coords'])->toHaveCount(2);
});

it('returns every coord and flags capped when the component is too big', function () {
    $items = [];
    for ($i = 0; $i < 12; $i++) {
        $items[] = scopeItem("c{$i}", 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]);
    }

    $result = (new IdentityScope)->component($items, [], ['c0'], max: 5);

    expect($result['capped'])->toBeTrue()
        ->and($result['coords'])->toHaveCount(12);
});

it('seeds from a "same" ruling even when neither side was touched', function () {
    // A ruling on two coords that nothing touched must still be applied — under
    // the old whole-kind resolve every write applied every pending ruling, and
    // for two MANUAL items nothing else ever reprojects them (see the seeding
    // comment in IdentityScope). Touching an unrelated coord must therefore
    // still pull the ruled pair in.
    $result = (new IdentityScope)->component([
        scopeItem('unrelated', 'src-z', 'track', [scopeKey(KeyClass::Isrc, 'ZZRC17607839')]),
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'same')], ['unrelated']);

    expect($result['coords'])->toContain('a')
        ->and($result['coords'])->toContain('b')
        ->and($result['coords'])->toContain('unrelated');
});

it('does NOT seed from a "different" ruling', function () {
    // A cut only ever suppresses a union, so it can only matter between coords
    // that share a signature — already in the component whenever either side is
    // reached. Seeding from cuts would drag unrelated groups in for nothing.
    $result = (new IdentityScope)->component([
        scopeItem('unrelated', 'src-z', 'track', [scopeKey(KeyClass::Isrc, 'ZZRC17607839')]),
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'different')], ['unrelated']);

    expect($result['coords'])->toBe(['unrelated']);
});

it('returns nothing when nothing was touched', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
    ], [], []);

    expect($result['coords'])->toBe([])
        ->and($result['capped'])->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Content/IdentityScopeTest.php`
Expected: FAIL — `Class "App\Content\Identity\IdentityScope" not found`

- [ ] **Step 3: Write the implementation**

Create `app/Content/Identity/IdentityScope.php`:

```php
<?php

namespace App\Content\Identity;

/**
 * Which coords can possibly resolve differently when a given set changed.
 *
 * PURE, exactly like Resolver: no database, no clock, no vendor. That is what
 * lets the differential test (tests/Postgres/ProjectionWriterScopedResolveTest)
 * assert "scoped == whole-kind" rather than trust it.
 *
 * The rule: two coords are adjacent when they share ANY canonicalised key
 * signature, or when the user ruled them 'same'. The component containing the
 * touched coords, taken to FIXPOINT, is the answer.
 *
 * THE BREADTH IS DELIBERATE and mirrors Resolver::poisonedKeys(): no tier(),
 * no minLength(), no appliesTo() filter. poisonedKeys() disables a signature
 * when ONE SOURCE carries it twice, and it deliberately poisons more broadly
 * than keyIndex() matches. A closure narrower than the poison scope would omit
 * the sibling that does the poisoning, so a key that the full resolve treats
 * as unreliable would look clean here — and the pair would MERGE. bindGroup()
 * -> mergeInto() hard-deletes the loser, so that is data loss, not a missed
 * optimisation. Do not "tidy" the missing filters in: they are the guard.
 *
 * ONE HOP IS NOT ENOUGH, for the same reason. The poisoning sibling is two
 * hops from the touched coord in the common case (touched -> match -> the
 * match's same-source duplicate). Pinned by IdentityScopeTest.
 *
 * A 'different' ruling is NOT an edge. Cuts only ever SEPARATE, and
 * DisjointSet::wouldViolateCut() cannot block a union between two in-component
 * coords using an out-of-component coord (that coord would have to share a
 * component with one of them, which would put it in the component). See the
 * plan's §A.1 proof.
 */
final class IdentityScope
{
    /**
     * Far above any real catalogue, far below a pathological one. When it
     * bites, the CALLER resolves whole-kind — this never truncates, because a
     * truncated component is the false-merge path above.
     */
    public const MAX_COMPONENT = 2000;

    /**
     * @param  list<SourceItem>  $items
     * @param  list<Decision>  $decisions
     * @param  list<string>  $touched
     * @return array{coords: list<string>, capped: bool}
     */
    public function component(array $items, array $decisions, array $touched, int $max = self::MAX_COMPONENT): array
    {
        if ($touched === [] || $items === []) {
            return ['coords' => [], 'capped' => false];
        }

        $known = [];
        foreach ($items as $item) {
            $known[$item->coord] = true;
        }

        // Adjacency, built once: signature => coords, and coord => signatures.
        $coordsBySignature = [];
        $signaturesByCoord = [];
        foreach ($items as $item) {
            foreach ($item->keys as $key) {
                $signature = $key->class->value.'|'.$key->class->canonicalise($key->value);
                $coordsBySignature[$signature][] = $item->coord;
                $signaturesByCoord[$item->coord][] = $signature;
            }
        }

        // A 'same' ruling joins two coords carrying no common key at all, so it
        // is a first-class edge. Only rulings naming coords present this run
        // count — a stale one must not vivify a coord that no longer exists.
        $sameEdges = [];
        foreach ($decisions as $decision) {
            if ($decision->verdict !== 'same') {
                continue;
            }
            if (! isset($known[$decision->left], $known[$decision->right])) {
                continue;
            }
            $sameEdges[$decision->left][] = $decision->right;
            $sameEdges[$decision->right][] = $decision->left;
        }

        // SEED = the touched coords PLUS every coord named by a live 'same'
        // ruling (amendment, 2026-08-25 — see the plan's §A.4).
        //
        // A 'same' ruling CREATES a merge, so it only takes effect if both its
        // coords are in the resolved set. Under the old whole-kind resolve any
        // write applied every pending ruling; under a touched-only seed, a
        // ruling whose coords nothing touched would never be applied at all.
        // Production reprojects the decided coords via
        // IdentityDecisionController -> ReprojectSourcesJob, but that lookup
        // INNER JOINs ingest.sources on connection_id, and a MANUAL source has
        // no connection_id (ProjectionWriter::ensureManualSource) — so a ruling
        // on two owner-added items dispatches nothing and would silently never
        // apply. Seeding from the rulings closes that hole here, where the
        // invariant lives, rather than in one controller.
        //
        // 'different' rulings are deliberately NOT seeded: a cut only ever
        // SUPPRESSES a union, so it can only matter between coords that share a
        // signature — and those are already in the component whenever either
        // side is reached.
        //
        // Cost is bounded by the RULING count (owner-authored, small), not by
        // catalogue size, so the narrowing's win is preserved.
        $seed = $touched;
        foreach ($sameEdges as $coord => $_) {
            $seed[] = (string) $coord;
        }

        $seen = [];
        $queue = [];
        foreach ($seed as $coord) {
            if (isset($known[$coord]) && ! isset($seen[$coord])) {
                $seen[$coord] = true;
                $queue[] = $coord;
            }
        }

        // Breadth-first to fixpoint. Each signature is expanded at most once —
        // without that, a signature shared by k coords costs O(k^2) walks.
        $expanded = [];
        for ($head = 0; $head < count($queue); $head++) {
            $coord = $queue[$head];

            foreach ($signaturesByCoord[$coord] ?? [] as $signature) {
                if (isset($expanded[$signature])) {
                    continue;
                }
                $expanded[$signature] = true;

                foreach ($coordsBySignature[$signature] as $neighbour) {
                    if (! isset($seen[$neighbour])) {
                        $seen[$neighbour] = true;
                        $queue[] = $neighbour;
                    }
                }
            }

            foreach ($sameEdges[$coord] ?? [] as $neighbour) {
                if (! isset($seen[$neighbour])) {
                    $seen[$neighbour] = true;
                    $queue[] = $neighbour;
                }
            }

            if (count($queue) > $max) {
                return ['coords' => array_keys($known), 'capped' => true];
            }
        }

        return ['coords' => $queue, 'capped' => false];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Content/IdentityScopeTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Lint and commit**

```bash
./vendor/bin/pint app/Content/Identity/IdentityScope.php tests/Unit/Content/IdentityScopeTest.php
./vendor/bin/pint --test
git add app/Content/Identity/IdentityScope.php tests/Unit/Content/IdentityScopeTest.php
git commit -m "feat(identity): IdentityScope — the transitive closure of a change

Pure closure over the identity-signature graph: which coords can possibly
resolve differently when a given set changed. Unwired; ProjectionWriter
adopts it next.

The breadth (no tier/minLength/appliesTo filter) mirrors poisonedKeys()
deliberately — a narrower closure omits the same-source sibling that
poisons a shared key, which turns a suppressed merge into a real one, and
mergeInto() hard-deletes the loser. One hop is not enough for the same
reason; the counter-example is the third test."
```

---

## Task 2: Wire the closure into `resolveItemsLocked`, behind a flag

**Files:**
- Modify: `config/partna.php` (the `content` block, `:1207-1211`)
- Modify: `.env.example`
- Modify: `app/Ingest/Projection/ProjectionWriter.php` — `resolveItems()` `:646`,
  `resolveItemsLocked()` `:788`, and the two call sites `:281` and `:462`
- Test: `tests/Postgres/ProjectionWriterScopedResolveTest.php` (created in Task 4; this task is
  covered by the EXISTING suites staying green)

**Interfaces:**
- Consumes: `IdentityScope::component()` from Task 1
- Produces: `resolveItems(string $userId, string $kind, array $touchedCoords): array<string,string>`
  and `resolveItemsLocked(string $userId, string $kind, array $touchedCoords): array<string,string>`

- [ ] **Step 1: Add the config**

In `config/partna.php`, replace the `'content' => [...]` block at `:1207` with:

```php
    'content' => [
        // BackfillContentItemSlugs: chunkById() page size for the
        // content.items walk.
        'slug_backfill_chunk' => (int) env('PARTNA_CONTENT_SLUG_BACKFILL_CHUNK', 500),

        // Identity-scope narrowing (#CACHE-2, #CACHE-4). When on,
        // resolveItemsLocked() resolves only the CONNECTED COMPONENT of the
        // coords a run touched instead of the user's whole catalogue for the
        // kind. The kill switch, not a tuning knob: off restores the
        // whole-kind path byte-for-byte without a deploy, which is the
        // primary rollback for this change.
        'identity_scope' => (bool) env('PARTNA_CONTENT_IDENTITY_SCOPE', true),

        // Component size past which the narrowing gives up and resolves
        // whole-kind anyway. A PERFORMANCE guard only: it must never
        // truncate, because a truncated component can miss the same-source
        // sibling that poisons a key and so produce a merge the full resolve
        // would not make — and mergeInto() hard-deletes the loser.
        'identity_scope_max' => (int) env('PARTNA_CONTENT_IDENTITY_SCOPE_MAX', 2000),
    ],
```

- [ ] **Step 2: Document the env keys**

Append to `.env.example`, near the other `PARTNA_CONTENT_*` entries:

```
# Identity-scope narrowing (ProjectionWriter). false = resolve the user's whole
# catalogue per kind on every run, the pre-2026-08-25 behaviour. This is the
# kill switch for the change; leave it true.
PARTNA_CONTENT_IDENTITY_SCOPE=true
# Component size past which narrowing falls back to a full whole-kind resolve
# and logs. Never truncates.
PARTNA_CONTENT_IDENTITY_SCOPE_MAX=2000
```

- [ ] **Step 3: Run the existing suites to establish the green baseline**

```bash
./vendor/bin/pest tests/Unit/Content tests/Feature/Ingest
```
Expected: PASS. Record the count — Step 7 must match it.

- [ ] **Step 4: Change the signatures and narrow the resolve**

In `ProjectionWriter.php`, add the import and constructor dependency:

```php
use App\Content\Identity\IdentityScope;
use Illuminate\Support\Facades\Log;
```

```php
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ValueResolver $values,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly IdentityKeyDeriver $identityKeys,
        private readonly IdentityScope $scope,
    ) {}
```

Change `resolveItems()`:

```php
    /**
     * Run the pure resolver over the user's live source-items of this kind
     * and bind every group to a stable item id.
     *
     * $touchedCoords is what this run WROTE. The resolve narrows to the
     * connected component of those coords (plan 2026-08-25 §A.1); the LOCK
     * stays (user, kind) regardless — see withIdentityLock()'s docblock for
     * why the protected set cannot be narrowed even when the computation is.
     *
     * @param  list<string>  $touchedCoords
     * @return array<string, string> coord => item id
     */
    private function resolveItems(string $userId, string $kind, array $touchedCoords): array
    {
        return $this->withIdentityLock($userId, $kind, fn (): array => $this->resolveItemsLocked($userId, $kind, $touchedCoords));
    }
```

In `resolveItemsLocked()`, change the signature to
`private function resolveItemsLocked(string $userId, string $kind, array $touchedCoords): array`
and insert the narrowing immediately AFTER `$sourceItems` and `$decisions` are built and BEFORE
`$this->resolver->resolve(...)`:

```php
        // Narrow to what can actually change (#CACHE-2/#CACHE-4, plan
        // 2026-08-25 §A.1). Everything above this line still reads the whole
        // (user, kind): $sourceItems is the graph the closure walks, and the
        // identity_keys read is a subquery on the same predicate, so the DB
        // touches source_items either way. The saving is everything BELOW —
        // the O(n^2) resolve, the anchor snapshot, the bind loop, the
        // candidate writes and the closing re-read.
        //
        // The lock is NOT narrowed with it: the protected set is still every
        // live source item of this (user, kind), which a racing writer may
        // GROW mid-computation. See withIdentityLock().
        $capped = false;
        if (config('partna.content.identity_scope') && $touchedCoords !== []) {
            $component = $this->scope->component(
                $sourceItems,
                $decisions,
                $touchedCoords,
                (int) config('partna.content.identity_scope_max', IdentityScope::MAX_COMPONENT),
            );
            $capped = $component['capped'];

            if (! $capped) {
                $inComponent = array_flip($component['coords']);
                $sourceItems = array_values(array_filter(
                    $sourceItems,
                    fn (SourceItem $item) => isset($inComponent[$item->coord]),
                ));
                $decisions = array_values(array_filter(
                    $decisions,
                    fn (Decision $d) => isset($inComponent[$d->left]) || isset($inComponent[$d->right]),
                ));
            }
        }

        if ($capped) {
            // NOT silent: an invisible fallback reads as "narrowing works" while
            // every run pays the full cost. user + kind so it is attributable.
            Log::warning('identity scope cap hit — resolving whole kind', [
                'user_id' => $userId,
                'kind' => $kind,
                'touched_count' => count($touchedCoords),
                // NOT 'component_size' (controller ruling, pre-flight scan): the
                // walk ABORTED at the cap, so the true component size is unknown.
                // What this counts is the whole-kind set we fell back to. Naming
                // it component_size would log a number that is not the thing its
                // key claims.
                'resolving_count' => count($sourceItems),
            ]);
        }
```

Then narrow the CLOSING re-read at the end of the same method. Replace the
`$bySourceItemId = DB::table('content.source_items')->whereIn('id', function ($sub) ...` block's
trailing `->get(['id', 'coord', 'item_id']);` chain with a `coord`-scoped read when narrowed:

```php
⚠️ **PRESERVE THIS STATEMENT'S SHAPE. Narrow INSIDE the existing subquery — do not restructure
the query into a join.** (Controller ruling, 2026-08-25, after two BLOCKED Task 2 attempts.)

The first attempt rewrote this read as `content.source_items as si` + join + `whereIn('si.coord')`.
That is semantically fine and it broke four PG-lane tests, because
`tests/Postgres/ProjectionWriterIdentityRaceTest.php` widens its read→write window by hooking THIS
statement through `DB::listen` and matching its **text**:

```php
if (! str_contains($sql, 'from "content"."source_items" where "id" in (select')) { return; }
if (! str_contains($sql, '"item_id"')) { return; }
```

A join changes that text, the hook never fires, no `pg_sleep` runs, the race is never staged, and
four tests fail for a reason that has nothing to do with identity resolution. **The hook's position
is load-bearing and deliberate:** the file's header records that an earlier version hooked the
`item_anchors` INSERT instead, and a review found that made every test in the file pass *with the
advisory lock deleted* — a false-pass trap. It was deliberately moved to this closing re-read.

**So keep the outer statement exactly as it is and put the narrowing in the subquery**, which needs
no test change and keeps flag-off identical for free:

```php
        // Narrowed INSIDE the subquery, deliberately: the outer statement's
        // shape is unchanged (`... from "content"."source_items" where "id" in
        // (select ...)`) because ProjectionWriterIdentityRaceTest hooks this
        // exact text through DB::listen to widen its read->write window, and
        // that hook's position is what makes the file prove the advisory lock
        // rather than an item_anchors unique-index collision (see that file's
        // header). Rewriting this into a join breaks four race tests for a
        // reason unrelated to identity.
        //
        // Rows this resolve did not consider cannot have a new target, so
        // re-reading them only to compare equal is pure cost. When the scope is
        // off or capped, $componentCoords is null and the predicate is
        // byte-for-byte what it was before this branch.
        $bySourceItemId = DB::table('content.source_items')
            ->whereIn('id', function ($sub) use ($userId, $kind, $liveSource, $componentCoords) {
                $sub->select('si.id')->from('content.source_items as si')
                    ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                    ->where('cs.user_id', $userId)
                    ->where('si.kind', $kind)
                    ->tap($liveSource)
                    ->whereNull('si.removed_at');

                if ($componentCoords !== null) {
                    $sub->whereIn('si.coord', $componentCoords);
                }
            })
            ->get(['id', 'coord', 'item_id']);
```

Set `$componentCoords` to `array_keys($itemByCoord)` when the narrowing actually applied (flag on,
component computed, cap did NOT bite), and to `null` otherwise. With `null` the closure adds
nothing and the emitted SQL is identical to the pre-branch statement — which is what makes
`PARTNA_CONTENT_IDENTITY_SCOPE=false` a true byte-for-byte rollback.

**Do NOT chunk this read.** The pre-branch statement was a single subquery with no chunking, and
the bind list stays a subquery (server-side) rather than a materialised array, so there is no
65,535-parameter risk to chunk around — that was the whole point of `#SCALE-7`'s original fix.
Chunking would also emit N statements and fire the fire-once hook on the first, changing timing.

```

- [ ] **Step 5: Update the two call sites**

**Before the two call sites — build the unbound seed.** In `resolveItemsLocked()`, add `si.item_id`
to the `$rows` select (it currently selects `si.id`, `si.coord`, `si.source_id`, `si.kind`,
`si.first_seen_at`), then derive:

```php
        // Seed source 3 (plan §A.4): rows that are not resolved AT ALL. The
        // whole-kind resolve rebound every live source item on every run, which
        // silently repaired an `item_id IS NULL` row; a touched-only seed would
        // never revisit one, leaving it unbound forever. Taken from $rows, which
        // is already read in full, so this costs no extra query — and in steady
        // state the set is EMPTY, so the narrowing's saving is unchanged.
        $unboundCoords = $rows
            ->filter(fn (object $row) => $row->item_id === null)
            ->pluck('coord')
            ->map(fn ($coord) => (string) $coord)
            ->all();
```

Pass `array_values(array_unique([...$touchedCoords, ...$unboundCoords]))` as `component()`'s
`$touched` argument. (`IdentityScope` adds the `same`-ruling coords itself — do not add them here.)
Adding a column to that select is harmless: `$sourceItems` maps rows into `SourceItem` DTOs, which
do not carry `item_id`.

`projectStream()` at `:281`:

**Accumulate `$touchedCoords` separately from `$projections`, starting now.** Inside the record
loop, beside `$projections[$coord] = $projection;`, add:

```php
            $touchedCoords[] = $coord;
```

(initialise `$touchedCoords = [];` beside `$projections = [];`), then:

```php
            $itemByCoord = $this->resolveItems($userId, $projector::kind(), $touchedCoords);
```

**Why a separate list rather than `array_keys($projections)`** (controller ruling, pre-flight scan):
Task 6 bounds `$projections` by FLUSHING it per batch, at which point `array_keys($projections)`
stops naming the whole run's touched set and starts naming only the last slice. Coords are short
strings and are kept for the whole run regardless, so taking the separate list from the start means
Tasks 3 and 6 never have to rewrite this line. Cost if wrong: a few bytes per record.

`writeManualItem()` at `:462`:

```php
            return $this->resolveItemsLocked($userId, $kind, [$coord]);
```

- [ ] **Step 6: Rewrite the `writeManualItem()` guard comment**

The guard at `:466` currently justifies itself with *"resolveItems() reads every live source item
for (user, kind)"* — which this task just made false. The guard stays correct for a DIFFERENT
reason. Replace the comment:

```php
        if (! isset($itemByCoord[$coord])) {
            // Unreachable: the row was written live inside the lock above, and
            // IdentityScope::component() seeds its walk FROM $coord, so the
            // component always contains it. (Before 2026-08-25 the reason was
            // that the resolve covered every live source item of the kind —
            // that is no longer true, but the guarantee is stronger, not
            // weaker: it now holds by construction rather than by breadth.)
            // Loud rather than a null return, because a silent miss here would
            // hand a caller an id for the wrong item.
            throw new \RuntimeException("Manual coord {$coord} did not resolve to an item.");
        }
```

- [ ] **Step 7: Run the suites — SQLite then Postgres**

```bash
./vendor/bin/pest tests/Unit/Content tests/Feature/Ingest
```
Expected: PASS, same count as Step 3.

```bash
docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "CREATE DATABASE partna_pg_lane_scratch"
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres/ProjectionWriterIdentityRaceTest.php
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres/ProjectionWriterBatchingTest.php
docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "DROP DATABASE partna_pg_lane_scratch"
```

Expected: PASS, **and `ProjectionWriterIdentityRaceTest.php` must pass UNTOUCHED.** If it needs an
edit, the change went further than the design intended — STOP and report.

**NEVER** point `PG_LANE_DISPOSABLE` at the `postgres` database itself — the lane's refusal
(*"Refusing to provision core.*/site.* on 'postgres'"*) is the guard working, and overriding it
provisions the lane's tables over your local dev stack. A separate DATABASE on the same container
is fine: tables are per-database, so local dev's `postgres` DB is untouched.

⚠️ **`PG_LANE_REQUIRED=1` is mandatory, not decorative.** Without it the lane SKIPS whenever a
precondition is missing and **reports green having asserted nothing** — which on Task 4's
differential test would mean the one check that can catch a false merge silently proved nothing.
If you see a suspiciously fast green with a high skip count, that is this.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Ingest/Projection/ProjectionWriter.php config/partna.php
./vendor/bin/pint --test
git add app/Ingest/Projection/ProjectionWriter.php config/partna.php .env.example
git commit -m "perf(ingest): resolve only the component a run can change — #CACHE-2

resolveItemsLocked() now runs the resolver over IdentityScope's connected
component of the touched coords rather than the user's whole catalogue for
the kind. The two whole-kind READS remain (they build the graph); the
O(n^2) resolve, anchor snapshot, bind loop, candidate writes and closing
re-read all narrow.

The advisory lock stays (user, kind) — the protected set can still GROW
mid-computation, which is why it is advisory in the first place.

PARTNA_CONTENT_IDENTITY_SCOPE=false restores the old path byte-for-byte.
The cap falls back to a full resolve and logs; it never truncates."
```

---

## Task 3: Refresh caches for touched items only

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` — `projectStream()` `:283`,
  `writeManualItem()` `:475`
- Test: `tests/Feature/Ingest/` — existing suites must stay green

**Interfaces:**
- Consumes: `resolveItems()` from Task 2 (now returns the component's map)
- Produces: no signature change; `refreshItemCaches()` receives fewer ids

- [ ] **Step 1: Verify what `#CACHE-2` actually still needs**

`BACKLOG-TRIAGE.md:84` pairs `#CACHE-2` with `#SCALE-7`, and `ProjectionWriter.php:813` shows
`#SCALE-7`'s bind-list half already shipped. Confirm before claiming new work:

```bash
grep -n "SCALE-7" app/Ingest/Projection/ProjectionWriter.php
grep -rn "CACHE-2" audits/ --include=CONSOLIDATED.md | head
```

Record in the commit body which half was already done. `CLAUDE.md` records a measured ~40%
already-fixed rate in this backlog tier — a finding that is already closed should be ticked with
that reason, not re-implemented.

- [ ] **Step 2: Narrow both refresh call sites**

`projectStream()` — replace `:283`:

```php
            // Touched items only (#CACHE-4). $itemByCoord is now the component,
            // but even within it only the coords this run wrote can have new
            // facets — writeFacets() above is already scoped to $projections,
            // so anything else would be a no-op refresh at ~18 queries per 500.
            $touchedItemIds = array_values(array_unique(array_filter(array_map(
                fn (string $coord): ?string => $itemByCoord[$coord] ?? null,
                array_keys($projections),
            ))));
            $this->refreshItemCaches($userId, $touchedItemIds);
```

`writeManualItem()` — replace `:475`:

```php
        // The one item the caller wrote, not every item of the kind (#CACHE-2).
        $this->refreshItemCaches($userId, [$itemByCoord[$coord]]);
```

- [ ] **Step 3: Guard the narrowing with a test**

`writeFacets()` folds per-column with `array_replace`, so a merge can move an item's headline
even when only ONE of its coords was touched. The refresh must still cover the merge target —
which it does, because the target id comes from `$itemByCoord`. Add to
`tests/Feature/Ingest/` (append to the nearest existing projection test file rather than
creating a new one):

```php
it('refreshes the merged item when only one of its coords was touched', function () {
    // Two sources carrying the SAME release url merge into one content.items
    // row via the CanonicalUrl joining key. Projecting only the SECOND stream
    // must still leave that merged item's headline_cache populated: the
    // refresh list is derived from $itemByCoord, so it names the SURVIVING
    // item rather than the touched coord's own pre-merge singleton. If the
    // narrowing had keyed off the touched coords' old item ids instead, this
    // is the test that would go red.
    $url = 'https://side.bandcamp.com/album/shared-release';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Shared Release', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Shared Release', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $items = DB::table('content.items')->where('user_id', $userId)->get(['id', 'headline_cache']);

    expect($items)->toHaveCount(1)
        ->and($items->first()->headline_cache)->toBe('Shared Release');
});
```

Add it to `tests/Feature/Ingest/ProjectionWriterTest.php`, which already provides
`projectableBandcamp()` (returns `[$userId, $connection, $source, $streamId]`, stream name
`releases`) and `bandcampDoc(string $title, string $url)`. Do NOT create a new file and do NOT
redefine either helper — they are global Pest functions and a redefinition breaks the parallel
runner.

- [ ] **Step 4: Run the suites**

```bash
./vendor/bin/pest tests/Feature/Ingest tests/Feature/Content
```
Expected: PASS.

Then the PG lane, with the same scratch-DB recipe as Task 2 Step 7, over
`tests/Postgres/ProjectionWriterBatchingTest.php` and
`tests/Postgres/ProjectionWriterIdentityRaceTest.php`.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Ingest/Projection/ProjectionWriter.php
./vendor/bin/pint --test
git add -A
git commit -m "perf(ingest): refresh caches for touched items only — #CACHE-4, #CACHE-2

refreshItemCaches() ran over every item of the kind on both the connector
path and the owner's manual write — ~18 DISTINCT queries per 500 items to
rebuild caches that could not have changed. Both call sites now pass only
the items this run actually wrote.

The merge target is still covered: the id list is derived from
\$itemByCoord, so a coord whose item was merged names the surviving item."
```

---

## Task 4: The differential PG-lane test — scoped must equal whole-kind

**Files:**
- Create: `tests/Postgres/ProjectionWriterScopedResolveTest.php`

**Interfaces:**
- Consumes: `ProjectionWriter::projectStream()`, `config('partna.content.identity_scope')`
- Produces: the executable form of §A.1's invariant

This is the test that can catch a false merge. Nothing else in the plan can.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ingest\Projection\ProjectionWriter;

// The invariant this whole change rests on (plan 2026-08-25 §A.1): resolving
// the touched coords' connected component must produce the SAME coord => item
// mapping as resolving the user's whole catalogue for the kind.
//
// PG lane, not Feature: SQLite has neither pg_advisory_xact_lock nor hashtext,
// so the Feature lane cannot exercise the locked path this runs inside, and a
// green `composer test` says nothing about it.

it('produces the same item mapping scoped as it does whole-kind', function () {
    // Build a catalogue with all four shapes that stress the closure:
    //   1. a singleton that shares nothing
    //   2. a cross-source pair joined by an Isrc
    //   3. a same-source duplicate title that POISONS a corroborating key
    //   4. a chain a -> b -> c that only a transitive walk reaches
    // then project ONE stream and compare the two configurations.
    [$userId, $source, $streamId, $streamName] = $this->seedIdentityFixture();

    config(['partna.content.identity_scope' => false]);
    $whole = app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);
    $wholeMapping = $this->itemIdByCoord($userId);

    $this->resetProjection($userId);

    config(['partna.content.identity_scope' => true]);
    $scoped = app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);
    $scopedMapping = $this->itemIdByCoord($userId);

    // Item IDs are freshly minted UUIDs in each pass, so compare the SHAPE:
    // which coords share an item, not which uuid they share.
    expect($this->groupShape($scopedMapping))->toBe($this->groupShape($wholeMapping))
        ->and($scoped['items'])->toBe($whole['items'])
        ->and($scoped['projected'])->toBe($whole['projected']);
});

it('does not merge a pair whose corroborating key a same-source duplicate poisons', function () {
    // Guard 2 end to end (plan §A.1): the UNFILTERED breadth of the closure.
    // Spotify has one "Wandering Star"; Apple has two. The duplicate poisons
    // the title key, so the pair must NOT merge — and must not merge under the
    // scoped path either, which requires the closure to have indexed that
    // signature and pulled Apple's second copy in.
    //
    // NOT a transitivity test: the poisoning sibling shares the title with the
    // touched coord, so it is ONE hop away. Transitivity is proven separately,
    // by the chained-union case. Do not relabel this as a one-hop
    // counter-example — an earlier draft of the plan did, and it was wrong.
    [$userId, $source, $streamId, $streamName] = $this->seedPoisonedTitleFixture();

    config(['partna.content.identity_scope' => true]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);

    $mapping = $this->itemIdByCoord($userId);

    expect($mapping['spotify:acct:wandering'])->not->toBe($mapping['apple:acct:wandering-a']);
});

it('falls back to a whole-kind resolve and logs when the cap bites', function () {
    [$userId, $source, $streamId, $streamName] = $this->seedIdentityFixture();

    Log::spy();
    config(['partna.content.identity_scope' => true, 'partna.content.identity_scope_max' => 1]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'identity scope cap')
            && $context['user_id'] === $userId
            && isset($context['kind'], $context['resolving_count']));
});
```

**Note for the implementer:** `seedIdentityFixture()`, `seedPoisonedTitleFixture()`,
`resetProjection()`, `itemIdByCoord()` and `groupShape()` are helpers you write as **private
methods on this test class**, not as global Pest functions —
`tests/Unit/Content/ResolverTest.php` already puts global helpers in this namespace and cross-file
helper collisions break the parallel runner. Build the fixtures from
`tests/fixtures/recorded/` via `Tests\Support\Fixtures\Recorded` (`CLAUDE.md` §Workflow: never
hand-type an upstream payload). `groupShape()` should return a sorted list of sorted coord groups.

- [ ] **Step 2: Run it to verify it fails, then passes**

```bash
docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "CREATE DATABASE partna_pg_lane_scratch"
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres/ProjectionWriterScopedResolveTest.php
docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "DROP DATABASE partna_pg_lane_scratch"
```

Expected on first run: FAIL on the missing fixture helpers. Write them, then PASS on all three.

**If the differential test fails on the mapping comparison, STOP.** That is §A.1's invariant
breaking, and it means the closure rule is wrong — not that the test needs adjusting.

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint tests/Postgres/ProjectionWriterScopedResolveTest.php
./vendor/bin/pint --test
git add tests/Postgres/ProjectionWriterScopedResolveTest.php
git commit -m "test(pg): differential — scoped resolve must equal whole-kind

The executable form of the plan's correctness argument. Also pins the
one-hop counter-example end to end (a same-source duplicate poisoning a
title key must still suppress the merge) and the cap's logged fallback.

PG lane because SQLite has neither pg_advisory_xact_lock nor hashtext, so
the Feature lane cannot exercise the locked path this runs inside."
```

---

## Task 5: Batch the slug read out of the refresh loop — `#SCALE-9` ≡ `#API-7`

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` — `refreshItemCaches()` `:2395-2410`
- Modify: `app/Services/Content/ContentItemSlugAllocator.php` (add a batch read; keep
  `ensureCurrent()` for the single-item callers)
- Test: `tests/Feature/Ingest/` existing slug coverage + `tests/Postgres/ItemSlugAllocatorRegressionTest.php`

**Interfaces:**
- Consumes: nothing new
- Produces: `ContentItemSlugAllocator::currentSlugs(string $userId, array $itemIds): array<string,string>`
  — item id => live slug, for the items that have one

- [ ] **Step 1: Confirm the N+1 and its shape**

```bash
sed -n '2395,2412p' app/Ingest/Projection/ProjectionWriter.php
grep -n "public function ensureCurrent" -A 30 app/Services/Content/ContentItemSlugAllocator.php
```

`ensureCurrent()` is a no-op when the live slug already matches — "the common path costs one
SELECT". That SELECT is the N+1: one per item per batch.

- [ ] **Step 2: Write the failing test**

Add to the existing slug test file:

```php
it('reads existing slugs once per batch, not once per item', function () {
    // Three EVENT items (SLUGGED_KINDS is ['event', 'menu_item'] — a track or
    // release never enters the slug branch at all, so seeding one of those
    // would make this test vacuously green) whose headlines already match
    // their slugs: ensureCurrent() would issue three SELECTs and write
    // nothing. The batched read issues one.
    [$userId, $itemIds] = $this->seedSluggedItems(3);

    DB::enableQueryLog();
    app(ProjectionWriter::class)->refreshCachesFor($userId, $itemIds);
    $slugReads = collect(DB::getQueryLog())
        ->filter(fn (array $q) => str_contains($q['query'], 'item_slugs'))
        ->count();
    DB::disableQueryLog();

    expect($slugReads)->toBe(1);
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest --filter="reads existing slugs once per batch"`
Expected: FAIL — `expect(3)->toBe(1)`.

Note `CLAUDE.md`/memory: `composer test --filter` is broken in this repo; call `./vendor/bin/pest`
directly as above.

- [ ] **Step 4: Add the batch read**

⚠️ **The live predicate is `is_current = true`, NOT `retired_at IS NULL`.** `ContentItemSlugAllocator`'s
own class header records why: migration `20260731210000` added `retired_at` with no backfill, so a
stranded row can carry `is_current = false` AND `retired_at IS NULL`. Getting this wrong returns
retired slugs as live and makes the skip-gate below skip a rename it should have performed.

In `ContentItemSlugAllocator`, add two public methods:

```php
    /**
     * The LIVE slug per item for a whole batch (#SCALE-9/#API-7).
     *
     * ensureCurrent() short-circuits when the live slug already equals the
     * base, but still pays one currentRow() SELECT to discover that — inside
     * refreshItemCaches()'s per-item loop that was one round trip per item on
     * every projection run. This answers it once for the chunk.
     *
     * `is_current = true`, NOT `retired_at IS NULL`: 20260731210000 added
     * retired_at without a backfill, so a stranded row carries is_current =
     * false AND retired_at NULL. See this class's header note 2.
     *
     * Leaner than lookupCurrent() on purpose — that one also assembles the
     * 301 alias window, which a refresh pass has no use for.
     *
     * @param  list<string>  $itemIds
     * @return array<string, string> item id => live slug
     */
    public function currentSlugs(string $userId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        /** @var array<string, string> */
        return DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->whereIn('item_id', $itemIds)
            ->where('is_current', true)
            ->pluck('slug', 'item_id')
            ->all();
    }

    /**
     * The slug this name would take before collision suffixing.
     *
     * Public only so a batch caller can skip the guaranteed no-op case without
     * reimplementing what a slug is — this class stays the one owner of that.
     */
    public function baseSlug(string $name, string $itemId): string
    {
        return $this->base($name, $itemId);
    }
```

In `refreshItemCaches()`, hoist the read above the per-item loop (after `$rowsById` is built):

```php
            // One read for the batch instead of one per item (#SCALE-9/#API-7).
            // ensureCurrent() is still the writer — it owns collision
            // suffixing, rename-back and retirement — it just no longer has to
            // ask whether there is anything to do.
            $liveSlugs = $this->slugs->currentSlugs($userId, $batch);
```

and gate the call:

```php
                if ($headline !== null && $headline !== ''
                    && in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)
                    && ($liveSlugs[$itemId] ?? null) !== $this->slugs->baseSlug((string) $headline, (string) $itemId)) {
                    try {
                        $this->slugs->ensureCurrent((string) $row->user_id, (string) $itemId, (string) $headline);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
```

**The gate is deliberately conservative.** It skips ONLY the exact `live === base` case.
An item legitimately holding `grant-writing-2` (the bare base was taken by another item) does not
match, so `ensureCurrent()` is still called — and returns early via its own collision-suffix arm.
That is correct and must stay: `ensureCurrent()`'s docblock warns that re-minting on a collided
item walks `-3`, `-4`, … and changes the public URL every run. Do not "improve" the gate into
replicating `collisionSuffix()`/`canonicalSlug()` out here.

**Scope note for the commit body — state it, do not inflate the finding.**
`ContentItemSlugAllocator::SLUGGED_KINDS` is `['event', 'menu_item']`, so this N+1 only ever fired
for those two kinds. A track/release/product refresh never entered the branch. The fix is real but
its blast radius is events and menu items, not every projection run.

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Ingest tests/Feature/Content
```
Expected: PASS, including the new one.

Then the PG lane over `tests/Postgres/ItemSlugAllocatorRegressionTest.php` and
`tests/Postgres/ItemSlugAllocatorSavepointTest.php` with the Task 2 Step 7 recipe. The savepoint
test matters: the `try/catch` around `ensureCurrent()` stays, and this repo has shipped a 25P02
poisoning three times.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Ingest/Projection/ProjectionWriter.php app/Services/Content/ContentItemSlugAllocator.php
./vendor/bin/pint --test
git add -A
git commit -m "perf(ingest): batch the slug read out of the refresh loop — #SCALE-9, #API-7

ensureCurrent() is a no-op when the slug matches, but still cost one SELECT
per item to find out — inside refreshItemCaches()'s per-item loop that was
one round trip per item on every run. One batched read now answers it for
the whole chunk; ensureCurrent() stays the writer.

#SCALE-9 and #API-7 are the same defect (BACKLOG-TRIAGE.md:84, 'fix once,
tick both')."
```

---

## Task 6: Bound the projection accumulator — `#SCALE-8`

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` — the `foreach ($records as $record)` loop
  `:186`, and `projectStream()`'s tail `:279-284`
- Test: `tests/Postgres/ProjectionWriterBatchingTest.php`

**Interfaces:**
- Consumes: `resolveItems()` from Task 2
- Produces: no signature change

- [ ] **Step 1: Understand the constraint before touching anything**

`$projections` is accumulated across the whole stream because `writeFacets()` needs it AFTER the
resolve. Two invariants bind any change:

1. **`lazy(500)` already bounds the READ.** The unbounded thing is the PHP array. Do not swap in
   `->cursor()` — `:155-157` records that it does not bound memory under `pdo_pgsql` because
   libpq buffers the whole result client-side.
2. **`writeFacets()`'s per-column `array_replace` fold lets the LAST-processed record win each
   column.** Any flush MUST preserve `first_seen_at` then `rs.key` order, or the headline a page
   shows changes silently.

- [ ] **Step 2: Write the failing test**

Add to `tests/Postgres/ProjectionWriterBatchingTest.php`:

```php
it('does not hold the whole stream in memory', function () {
    // 3,000 records through a stream whose projector emits a fat facet blob.
    // Peak memory must stay flat-ish rather than tracking record count.
    [$source, $streamId, $streamName] = $this->seedWideStream(3000);

    $before = memory_get_peak_usage(true);
    app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);
    $growth = memory_get_peak_usage(true) - $before;

    // Generous ceiling: the point is "does not scale with the stream", not a
    // precise byte count. Tighten only if it proves stable in CI.
    expect($growth)->toBeLessThan(64 * 1024 * 1024);
})->skip('memory_get_peak_usage is process-monotonic — see the structural assertion below');

it('never holds more than one batch of projections at once', function () {
    // THE load-bearing assertion (controller ruling, pre-flight scan). The peak-
    // memory test above is vacuous-prone: memory_get_peak_usage() is a
    // PROCESS-wide high-water mark, so any earlier test in the same worker that
    // allocated more makes $growth 0 and the test passes without measuring
    // anything. Assert the bound STRUCTURALLY instead — the accumulator's size
    // is what the fix actually changes.
    //
    // Implementer: expose the high-water mark of the accumulator through a
    // test-visible seam (a protected property the test subclasses, or a counter
    // the writer records), and assert it never exceeds self::BATCH_SIZE. Do NOT
    // assert on memory here. Report the memory figure in Task 8 as a
    // MEASUREMENT, which is what the plan asks for — not as a pass/fail gate.
    [$source, $streamId, $streamName] = $this->seedWideStream(3000);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, $streamName);

    expect($writer->peakAccumulatedProjections())->toBeLessThanOrEqual(500);
});

it('keeps last-processed-record-wins after the flush change', function () {
    // Two records, same coord family, differing headlines, deliberately
    // ordered by first_seen_at. The LATER one must win the headline column —
    // the invariant writeFacets()'s array_replace fold provides and any
    // flushing scheme must preserve.
    [$source, $streamId, $streamName] = $this->seedOrderedHeadlines();

    app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);

    expect($this->headlineFor('later-record-coord'))->toBe('The later headline');
});
```

- [ ] **Step 3: Run it to verify it fails**

Use the Task 2 Step 7 PG recipe against `tests/Postgres/ProjectionWriterBatchingTest.php`.
Expected: the memory test FAILs.

- [ ] **Step 4: Implement the bound**

Flush `$projections` in `self::BATCH_SIZE` slices once the loop has filled one, resolving and
writing facets per slice. **The resolve must still see the whole touched set**, so accumulate the
touched COORDS (cheap strings) for the whole stream and keep only the current slice's full
projections:

```php
        // #SCALE-8: lazy(500) bounds the READ, but $projections accumulated the
        // whole stream's projection payloads in PHP. Coords are kept for the
        // whole run (they are short strings, and the resolve needs the full
        // touched set); the fat payloads are flushed per BATCH_SIZE slice.
        //
        // Order is preserved across the flush: slices are written in the order
        // records arrive (first_seen_at, then rs.key), so writeFacets()'s
        // per-column array_replace fold still lets the LAST-processed record
        // win each column. Flushing OUT of order would silently change which
        // record's headline a page shows.
```

Resolve once, after the loop, using the accumulated coords; then replay the slices through
`writeFacets()` in order. **If replaying requires re-reading the records, that is a second pass
over the stream and it is the wrong trade — in that case keep the accumulator and instead cap
what is stored per projection to the columns `writeFacets()` reads.** Choose whichever the
measurement in Task 8 supports, and write the reason into the commit body.

- [ ] **Step 5: Run the tests**

PG lane over `tests/Postgres/ProjectionWriterBatchingTest.php` and
`tests/Postgres/IngestProjectChunkingTest.php`, plus
`./vendor/bin/pest tests/Feature/Ingest`.
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Ingest/Projection/ProjectionWriter.php
./vendor/bin/pint --test
git add -A
git commit -m "perf(ingest): bound the projection accumulator — #SCALE-8

lazy(500) bounded the read; the PHP array holding every projection did not,
so a long stream's peak memory tracked its record count. Coords are kept for
the whole run (the resolve needs the full touched set); the payloads are
flushed per batch, in arrival order so writeFacets()'s last-record-wins fold
is unchanged."
```

---

## Task 7: `#SCALE-12` — measure, then dispose

**Files:**
- Modify: none unless the measurement justifies it
- Modify: `audits/.../CONSOLIDATED.md` — tick with the disposition either way

**Interfaces:** none

**This task may legitimately end in WONTFIX.** `CLAUDE.md`: *"A ticked box means 'resolved as an
open question', not 'the code changed.' Closing a finding WONTFIX with a stated reason is a
legitimate outcome."* Josh decided on 2026-08-25 to measure first.

- [ ] **Step 1: Establish what the fan-out actually costs**

`dispatchMirrors()` (`:2101`) already chunks its DB reads; what is per-asset is
`MirrorMediaAssetJob::dispatch()` — one queue push plus one `ShouldBeUnique` lock acquire each.

```bash
grep -rn "dispatchMirrors" app/Ingest/Projection/ProjectionWriter.php
```

Measure a realistic run (an Instagram refresh is the fattest known case — the F14 note at `:2025`
cites 88 frames):

```php
// tinker, dev
DB::table('content.media_assets')->whereNull('storage_path')->count();
```

Record: assets per typical run, and the Redis round-trips that implies (2 per asset).

- [ ] **Step 2: Apply the disposition rule**

- **If a typical run dispatches < ~200 assets → WONTFIX.** Write the reason: the per-job dispatch
  is required by `ShouldBeUnique`, which `Bus::batch()` / `Queue::bulk()` bypass because the
  uniqueness check lives in the dispatcher pipeline. The comment at `:2189` already records that
  `Bus::dispatch(new …)` silently drops it. Trading that guard for round-trips at this volume is
  a bad trade, and a duplicate mirror job costs an R2 write plus an outbound fetch.
- **If runs routinely dispatch thousands → promote it to its own unit**, do NOT fix it here. A
  correct fix means a uniqueness scheme that survives bulk push, which is a design change to the
  media-mirror lane and falls under `fix-flow.md`'s "Standalone — do NOT bundle" rule (it touches
  a live third-party surface).

- [ ] **Step 3: Record the disposition**

```bash
git add audits/
git commit -m "docs(audit): #SCALE-12 disposition — <WONTFIX|promoted>, with the measurement

Per-asset MirrorMediaAssetJob::dispatch() is per-asset because ShouldBeUnique
lives in the dispatcher pipeline, which Bus::batch()/Queue::bulk() bypass.
Measured <N> assets on a typical run = <2N> Redis round-trips.
<Reason for the disposition.>"
```

---

## Task 7b: End-to-end sync test — the duplicate-shape edge cases

**Files:**
- Create: `tests/Feature/Ingest/ProjectionSyncShapesTest.php`

**Interfaces:**
- Consumes: `ProjectionWriter::projectStream()`, `projectableBandcamp()` / `bandcampDoc()` from
  `tests/Feature/Ingest/ProjectionWriterTest.php`
- Produces: nothing — this is a guard, not a component

**Why this task exists.** Tasks 1 and 4 prove the closure rule in isolation and prove old-equals-new
on one fixture. Neither answers "does a REAL sync still behave correctly across the shapes a real
catalogue actually contains?" These five shapes are where identity narrowing breaks, and each one
is a different failure:

| Shape | What must happen | What breaks if the closure is wrong |
|---|---|---|
| **One item, nothing like it** | stays one item | over-eager merge |
| **Two that are genuinely the same** (shared joining key across sources) | merge to one | the cross-source union is lost — the whole point of the system |
| **Two that are merely SIMILAR** (same-ish title, different song) | stay separate | a false merge, and the loser is hard-deleted |
| **A few sharing one key** (3+ sources, one song) | all collapse to one | partial merge — worse than none, because the duplicate is now sticky |
| **Multiple copies of the SAME thing in ONE source** | the key is POISONED; no merge happens at all | this is the exact case a one-hop closure gets wrong |

The last row is the one that matters most: it is §A.1's Guard 2 (unfiltered breadth) arriving
through a real projection run rather than a unit fixture. Guard 1 (transitivity) is proven by the
chained-union tests in Tasks 1 and 4, not here.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// End-to-end sync shapes (plan 2026-08-25 Task 7b). Tasks 1 and 4 prove the
// closure rule and old-equals-new; this file asks the blunter question — does a
// real projection run still group a realistic catalogue correctly with the
// narrowing on? Each test is one duplicate shape.
//
// Run with the narrowing ON deliberately: these are the cases it could break.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake([RunSourceJob::class]);
    config(['partna.content.identity_scope' => true]);
});

/** Every content.items row for the user, with the coords bound to it. */
function coordsByItem(string $userId): array
{
    $rows = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->whereNull('si.removed_at')
        ->get(['si.coord', 'si.item_id']);

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row->item_id][] = (string) $row->coord;
    }
    foreach ($out as $itemId => $coords) {
        sort($coords);
        $out[$itemId] = $coords;
    }

    return $out;
}

it('leaves a lone item as one item', function () {
    [$userId, , $source, $stream] = projectableBandcamp([
        'r1' => bandcampDoc('Only Release', 'https://a.bandcamp.com/album/only'),
    ]);

    app(ProjectionWriter::class)->projectStream($source, $stream, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);
});

it('merges two sources carrying the same release into one item', function () {
    $url = 'https://a.bandcamp.com/album/shared';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Shared', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Shared', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $byItem = coordsByItem($userId);

    expect($byItem)->toHaveCount(1)
        ->and(reset($byItem))->toHaveCount(2);
});

it('keeps two merely SIMILAR releases apart', function () {
    // Different urls, different titles that share words but are not the same
    // release. Nothing joins them, so nothing may merge them.
    [$userId, , $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc('Wandering Star', 'https://a.bandcamp.com/album/wandering-star'),
    ]);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc('Wandering Stars Live', 'https://b.bandcamp.com/album/wandering-stars-live'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);
});

it('collapses a few sources sharing one release into a single item', function () {
    $url = 'https://a.bandcamp.com/album/everywhere';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Everywhere', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Everywhere', $url)], $userId);
    [, , $sourceC, $streamC] = projectableBandcamp(['r1' => bandcampDoc('Everywhere', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');
    $writer->projectStream($sourceC, $streamC, 'releases');

    $byItem = coordsByItem($userId);

    expect($byItem)->toHaveCount(1)
        ->and(reset($byItem))->toHaveCount(3);
});

it('does NOT merge when one source lists the same thing twice — the poisoned key', function () {
    // Guard 2 through a real sync (plan §A.1): the closure indexes signatures
    // UNFILTERED, so B's second copy is present for poisonedKeys() to see.
    // Source B carries the release TWICE under two record keys; that duplicate
    // poisons the corroborating key, so A and B must stay apart. If the closure
    // dropped the weak signature, the key would look clean and these would
    // merge — and mergeInto() hard-deletes the loser.
    //
    // The poisoning sibling is ONE hop from the touched coord (it shares the
    // key), so this is not the transitivity case; the chained-union tests cover
    // that. Keeping the two guards distinct matters — conflating them is the
    // error the plan's own first draft made.
    [$userId, , $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc('Twice Listed', 'https://a.bandcamp.com/album/twice-listed'),
    ]);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc('Twice Listed', 'https://b.bandcamp.com/album/twice-listed-single'),
        'r2' => bandcampDoc('Twice Listed', 'https://b.bandcamp.com/album/twice-listed-album'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $byItem = coordsByItem($userId);

    // Three coords, and A must not share an item with either of B's.
    expect(array_sum(array_map('count', $byItem)))->toBe(3)
        ->and($byItem)->toHaveCount(3);
});

it('gives the same grouping with the narrowing off', function () {
    // The whole file, re-run the old way. Any shape above that depends on the
    // narrowing rather than surviving it shows up here as a difference.
    $url = 'https://a.bandcamp.com/album/parity';

    $run = function (bool $scoped) use ($url) {
        config(['partna.content.identity_scope' => $scoped]);

        [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Parity', $url)]);
        [, , $sourceB, $streamB] = projectableBandcamp([
            'r1' => bandcampDoc('Parity', $url),
            'r2' => bandcampDoc('Parity Other', 'https://b.bandcamp.com/album/parity-other'),
        ], $userId);

        $writer = app(ProjectionWriter::class);
        $writer->projectStream($sourceA, $streamA, 'releases');
        $writer->projectStream($sourceB, $streamB, 'releases');

        // Shape only — item ids are freshly minted uuids in each run.
        $shape = array_values(coordsByItem($userId));
        sort($shape);

        return $shape;
    };

    expect($run(true))->toBe($run(false));
});
```

**Implementer notes:**
- `projectableBandcamp()` and `bandcampDoc()` are global Pest helpers already defined in
  `tests/Feature/Ingest/ProjectionWriterTest.php`. **Use them; do not redefine either** — a
  duplicate global helper breaks the parallel runner. `coordsByItem()` is new and unique to this
  file; if `./vendor/bin/pest --parallel` reports a redeclaration, rename it, do not delete it.
- The similar-but-different and poisoned-key cases depend on what `IdentityKeyDeriver` actually
  emits for a bandcamp release. **Verify the emitted keys before asserting** —
  `./vendor/bin/pest tests/Unit/Ingest/IdentityKeyEmissionTest.php` shows the shape, and
  `dd((new IdentityKeyDeriver)->derive($coord, $projection))` in a scratch test confirms it. If
  bandcamp releases do not emit a corroborating title key at all, the poisoned-key test is
  vacuous — say so in the report and switch the fixture to a source kind that does
  (`projectableEventbrite()` is the other ready-made helper).
- **A vacuously-passing test here is worse than no test**, because this file is the thing that
  would catch a false merge in a real sync. If a shape cannot be built honestly, report it rather
  than asserting something trivially true.

- [ ] **Step 1b: The second wave — the cases that threaten the narrowing specifically**

The six shapes above are about duplicate detection. These six are about the things the closure
could quietly drop. Each maps to a distinct mechanism, and three of them are regressions this repo
has already shipped once.

| Case | Why it threatens THIS change |
|---|---|
| Owner ruled "these are the same" | A `same` ruling is a closure EDGE with no shared key behind it. Drop the edge and the pair stops merging. |
| Owner ruled "these are different" | A cut must beat a joining key. If the narrowed decision list drops it, two things the owner separated silently re-merge. |
| A disconnected source | `disconnect = hide` — a removed connection's items must not vote. This exact bug shipped: an old Apple connection's five compilation copies poisoned a title key and kept the live pair apart. |
| Running the same sync twice | Idempotence. The narrowing changes what a second run considers; a second run must still mint nothing and move nothing. |
| Owner adds an item by hand that matches a scraped one | `writeManualItem()`'s own path — this is `#CACHE-2`'s actual surface, and it now resolves a component seeded from ONE coord. |
| A merged pair whose keys stop matching | Anchors are sticky, so the pair does NOT auto-split. Pin that it still doesn't — a narrowing that accidentally "fixed" this would be a silent behaviour change. |

```php
it('follows an owner "same" ruling that no shared key would have found', function () {
    // A decision edge is the one closure edge with no key behind it. If the
    // component walk ignores decisions, these two stop merging.
    [$userId, , $sourceA, $streamA] = projectableBandcamp([
        'r1' => bandcampDoc('Alpha', 'https://a.bandcamp.com/album/alpha'),
    ]);
    [, , $sourceB, $streamB] = projectableBandcamp([
        'r1' => bandcampDoc('Beta', 'https://b.bandcamp.com/album/beta'),
    ], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);

    // The owner overrules: same thing. Coords are stored sorted.
    $coords = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)->orderBy('si.coord')->pluck('si.coord')->all();

    DB::table('content.identity_decisions')->insert([
        'user_id' => $userId,
        'left_coord' => $coords[0],
        'right_coord' => $coords[1],
        'verdict' => 'same',
        'created_at' => now(),
    ]);

    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);
});

it('honours an owner "different" ruling over a joining key', function () {
    // A cut must beat a shared url. If the narrowed decision list drops the
    // cut, two things the owner deliberately separated silently re-merge.
    $url = 'https://a.bandcamp.com/album/contested';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Contested', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Contested', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);

    $coords = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)->orderBy('si.coord')->pluck('si.coord')->all();

    DB::table('content.identity_decisions')->insert([
        'user_id' => $userId,
        'left_coord' => $coords[0],
        'right_coord' => $coords[1],
        'verdict' => 'different',
        'created_at' => now(),
    ]);

    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(2);
});

it('ignores a source whose connection the owner removed', function () {
    // disconnect = hide. A removed connection's items must not vote on
    // identity — this shipped as a live bug once: an old Apple connection's
    // compilation copies poisoned a title key and kept the live pair apart.
    $url = 'https://a.bandcamp.com/album/legacy';

    [$userId, $connA, $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Legacy', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Legacy', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);

    $connA->delete(); // soft delete — deleted_at set

    $writer->projectStream($sourceB, $streamB, 'releases');

    // A's coord no longer participates, so B stands alone. Assert on the LIVE
    // set only: the retired row may still exist in content.source_items.
    $live = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->leftJoin('site.platform_connections as pc', 'pc.id', '=', 'cs.connection_id')
        ->where('cs.user_id', $userId)
        ->whereNull('si.removed_at')
        ->where(fn ($q) => $q->whereNull('cs.connection_id')->orWhereNull('pc.deleted_at'))
        ->count();

    expect($live)->toBe(1);
});

it('is idempotent — a second identical sync mints nothing and moves nothing', function () {
    $url = 'https://a.bandcamp.com/album/stable';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Stable', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Stable', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    $first = coordsByItem($userId);
    $itemIds = DB::table('content.items')->where('user_id', $userId)->orderBy('id')->pluck('id')->all();

    // Run both streams again, unchanged.
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toBe($first)
        ->and(DB::table('content.items')->where('user_id', $userId)->orderBy('id')->pluck('id')->all())
        ->toBe($itemIds);
});

it('merges an owner-added item into the scraped one it matches', function () {
    // writeManualItem()'s own path — #CACHE-2's actual surface. It now resolves
    // a component seeded from a SINGLE coord, so if the seed does not reach the
    // scraped twin, the owner sees a duplicate of their own item.
    $url = 'https://a.bandcamp.com/album/handmade';

    [$userId, , $source, $stream] = projectableBandcamp(['r1' => bandcampDoc('Handmade', $url)]);
    app(ProjectionWriter::class)->projectStream($source, $stream, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);

    // The owner adds the same release by hand. Build the manual projection the
    // way the file's existing manual-path tests do (manualSourceFor() /
    // projectOne() in ProjectionWriterTest.php) — reuse that shape rather than
    // constructing a projection array by hand.
    // The assertion is the point: still ONE item, now with two coords.
    $byItem = coordsByItem($userId);

    expect($byItem)->toHaveCount(1)
        ->and(reset($byItem))->toHaveCount(2);
})->skip('fill in using ProjectionWriterTest.php manual-path helpers — see implementer notes');

it('does not auto-split a merged pair whose keys stop matching', function () {
    // Anchors are STICKY: once bound, a pair does not un-merge just because the
    // evidence changed. That is pre-existing behaviour, and this pins that the
    // narrowing did not accidentally change it in either direction.
    $url = 'https://a.bandcamp.com/album/drift';

    [$userId, , $sourceA, $streamA] = projectableBandcamp(['r1' => bandcampDoc('Drift', $url)]);
    [, , $sourceB, $streamB] = projectableBandcamp(['r1' => bandcampDoc('Drift', $url)], $userId);

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($sourceA, $streamA, 'releases');
    $writer->projectStream($sourceB, $streamB, 'releases');

    expect(coordsByItem($userId))->toHaveCount(1);

    // B's release moves to a different url — the joining key no longer matches.
    DB::table('ingest.record_versions')->where('stream_id', $streamB)->delete();
    DB::table('ingest.record_state')->where('stream_id', $streamB)->delete();
    landCurrentRecord($streamB, 'r1', bandcampDoc('Drift', 'https://b.bandcamp.com/album/drift-moved'));

    $writer->projectStream($sourceB, $streamB, 'releases');

    // Still one item. If this ever becomes two, that is a deliberate product
    // change, not a passing test to update quietly.
    expect(coordsByItem($userId))->toHaveCount(1);
});
```

**Implementer notes for this wave:**
- **The manual-path test is `skip()`ped on purpose** — filling it in needs
  `ProjectionWriterTest.php`'s `manualSourceFor()` / `projectOne()` helpers, and guessing at a
  projection array shape would produce a test that passes for the wrong reason. Remove the
  `skip()` and fill it in using those helpers. **If you cannot make it assert something real,
  leave the `skip()` with a one-line reason and say so in your report** — do not delete the test
  and do not replace it with a trivially-true assertion.
- `content.identity_decisions` column names must be verified against
  `supabase/migrations/20260727140000_content_schema.sql` before running — `left_coord` /
  `right_coord` / `verdict` are what `resolveItemsLocked()` reads, but confirm the insert shape
  (nullable columns, any `id` default) rather than assuming.
- The disconnected-source test depends on `IntegrationConnection` soft-deleting. Confirm
  `$connA->delete()` sets `deleted_at` rather than hard-deleting; if the model does not use
  `SoftDeletes`, set `deleted_at` directly and note it.
- Several of these run `projectStream()` two or three times. That is deliberate — a single-run
  test cannot catch a narrowing bug that only appears once anchors exist.

- [ ] **Step 2: Run them to verify they fail, then pass**

```bash
./vendor/bin/pest tests/Feature/Ingest/ProjectionSyncShapesTest.php
```

Expected first run: the parity and poisoned-key tests are the ones most likely to fail. **A
failure in the poisoned-key or parity test is a REAL defect in the closure, not a test to
adjust** — report it and stop.

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint tests/Feature/Ingest/ProjectionSyncShapesTest.php
./vendor/bin/pint --test
git commit -m "test(ingest): end-to-end sync shapes — one, same, similar, several, duplicated

Six shapes through a real projection run with the narrowing on: a lone item,
a genuine cross-source merge, two merely similar releases, three sources
collapsing to one, and the case a one-hop closure gets wrong — one source
listing the same release twice, which poisons the key and must suppress the
merge entirely.

The last test re-runs the same catalogue with the narrowing off and demands
an identical grouping." -- tests/Feature/Ingest/ProjectionSyncShapesTest.php
```

---

## Task 8: Measure, verify, tick

**Files:**
- Modify: the source sweep's `CONSOLIDATED.md` checkboxes
- Create: a `RESULT` section appended to this plan file

**Interfaces:** none

**Measurements are part of the deliverable** (PLAN-PROMPT §4). "Should be faster" is not a result.

- [ ] **Step 1: Capture before/after**

Check out the merge-base to get the "before", run the same stream twice, and record **query
count** and **peak memory** for each:

```bash
git stash list   # confirm clean
php artisan tinker --execute="
  DB::enableQueryLog();
  \$m = memory_get_peak_usage(true);
  // project a representative stream — use the widest dev fixture available
  // app(App\Ingest\Projection\ProjectionWriter::class)->projectStream(...);
  echo count(DB::getQueryLog()).' queries, '.((memory_get_peak_usage(true)-\$m)/1048576).' MB peak'.PHP_EOL;
"
```

Run it with `PARTNA_CONTENT_IDENTITY_SCOPE=false` (the before) and `=true` (the after) so the two
numbers come from the same checkout and the same fixture. That is cleaner than a git checkout
dance and it exercises the flag at the same time.

**No production measurement is available** — prod carries none of `content`, `ingest`, `routing`
or `catalog`, and `core.users` = 0 (verified 2026-08-25). Local/dev evidence only; say so.

- [ ] **Step 2: Full regression — every lane, compared against the recorded baseline**

**The point of this step is "did we break anything anywhere", not "do my new tests pass".** A
green targeted run proves nothing about the other ~3,000 tests, and this change edits a file that
sits under the connector pipeline, the owner's dashboard writes and the public payload.

A pre-change baseline was captured at branch creation and lives at
`.superpowers/sdd/2026-08-25-projectionwriter-identity-scope/baseline-full-suite.txt`.
**Compare against it by number.** "All green" is not a result if the test COUNT dropped — a
deleted or silently-skipped test reads as green.

```bash
# 1. Fast lane, whole suite, parallel (~4x faster than serial).
php artisan test --parallel 2>&1 | tail -25
```
Expected: PASS, and the passed-count **>= the baseline count**. If the count is lower, find the
missing tests before continuing — a dropped test is a regression this step exists to catch.

```bash
# 2. Postgres lane, WHOLE directory — not just the files this branch touched.
docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "CREATE DATABASE partna_pg_lane_scratch"
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres
docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "DROP DATABASE partna_pg_lane_scratch"
```
Expected: PASS. **This lane is mandatory** — its stand-in DDL is hand-written and drifts silently
from writer changes; slice 5a turned it red for 7 tests on a green SQLite run and two reviews
missed it.

```bash
# 3. Applied-schema lane — SKIP THIS LOCALLY. Read the note before running anything.
```
⚠️ **`composer test:schema` does NOT run in a local checkout and must NOT be treated as a gate
here.** Its `.env` `DB_HOST` is a dead Supabase ref, and unlike the PG lane it asserts against the
REAL applied schema (it does not provision stand-in tables), so it needs the migrations AND
Supabase's `auth` schema already present. On a bare container it reports ~172 failed, every failure
`SQLSTATE[42P01] relation "auth.users" does not exist`. **That is an environment gap, not a
regression — do not bisect it and do not "fix" anything it reports.** CI is the only place this
lane has ever run.

This branch touches no schema, so the lane has nothing to say about it regardless. Record it as
"not run locally — CI gate" in the report.

```bash
# 4. Static analysis and formatting — the CI gates.
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=2G 2>&1 | tail -20
```
`pint --test` is the gate, not `pint`. Note that phpstan surfaces latent findings in files this
branch never touched — record any as pre-existing rather than "fixing" unrelated code.

**Both narrowing states must be exercised.** The suite runs with the flag at its config default
(on). Re-run the identity-sensitive suites with it OFF to prove the rollback path is not rotting:

```bash
PARTNA_CONTENT_IDENTITY_SCOPE=false ./vendor/bin/pest \
  tests/Unit/Content tests/Feature/Ingest tests/Feature/Content
```
Expected: PASS. A kill switch nothing tests is not a kill switch.

**Record every number in the report:** baseline count, post-change count, the PG-lane count, and
any pre-existing red carried in. `CLAUDE.md`: a green suite that was never compared to a baseline
is an assertion, not evidence.

- [ ] **Step 3: Write the results into this plan**

Append a `## RESULT` section: the two measurements, which findings closed and how
(`#SCALE-12` may be WONTFIX), what stayed open, and anything the implementation learned that
contradicts §A. **If §A's proof turned out wrong anywhere, say so plainly here** — a plan that
is honest about where it was wrong is worth more than one that reads clean.

- [ ] **Step 4: Tick the source findings — EXACT lines, verified 2026-08-26**

⚠️ **Every one of these IDs has a same-ID TWIN in another sweep that is a DIFFERENT defect.** Tick by
ID **plus file plus line**, never by a bare grep for the ID. The controller located and verified each
line; do not re-derive them.

**TICK — `audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md`:**

| Line | ID | Finding text (confirms it is ours) |
|---|---|---|
| 811 | `CACHE-2` | *"`writeManualItem` recomputes the whole user's identity graph on every single owner write"* |
| 860 | `CACHE-4` | *"a connector run touching one record rebuilds identity + caches for the user's entire kind"* |
| 1224 | `#SCALE-8` | *"Projection writer accumulates the entire stream's projections in PHP memory"* |
| 1245 | `#SCALE-9` | *"Slug maintenance runs `ensureCurrent()` once per item inside the … batch loop"* |
| 1311 | `#SCALE-12` | *"Media-mirror dispatch enqueues one job per asset"* — tick **WONTFIX**, see below |

Note the sweep is internally inconsistent: `CACHE-2`/`CACHE-4` carry **no** `#`, while
`#SCALE-8/9/12` do. Match both forms.

Also tick the per-lens file the consolidated one was built from:
`audits/sweeps/2026-08-18-overnight-run/audit-2026-08-18-scaling-antipatterns.md` — `CACHE-4` at
`:110`, and `CACHE-2` at its corresponding line. `AuditPipelineIntegrityTest` and
`archive-done.sh` read the consolidated file, but leaving the lens file red is how a later sweep
re-files a closed finding.

**DO NOT TICK — these are different defects that merely share an ID:**

| File | Line | ID | What it actually is |
|---|---|---|---|
| `sweeps/2026-08-24-unified-actions-delta/CONSOLIDATED.md` | 300 | `#API-7` | Public pool hydration runs a dashboard-only duplicate-detection query |
| `sweeps/2026-08-24-unified-actions-remainder/CONSOLIDATED.md` | 545 | `SCALE-8` | Suggestions inbox per-intent lookup on GET |
| `sweeps/2026-08-24-unified-actions-remainder/CONSOLIDATED.md` | 569 | `SCALE-9` | Public payload duplicate-detection join |
| `sweeps/2026-08-24-unified-actions-remainder/CONSOLIDATED.md` | 631 | `SCALE-12` | `ContentPopularityReader` loads a full score set with no cursor |
| `sweeps/2026-08-24-unified-actions-remainder/CONSOLIDATED.md` | 162 | `CACHE-4` | `IntegrationConnectionObserver::deleted()` inlines five cleanup paths |

**`#API-7` — CORRECTED, do NOT tick it.** The PLAN-PROMPT flagged the `#SCALE-9` ≡ `#API-7` pairing
as *inferred, not certain*, and told this session to confirm it before ticking. An early check
confirmed only that `BACKLOG-TRIAGE.md:84` contains the string `SCALE-9 ≡ #API-7` — **that was too
shallow.** Comparing the actual finding TEXTS settles it:

- our overnight `#SCALE-9` = *slug `ensureCurrent()` N+1 inside the projection cache-refresh loop*;
- `#API-7` = *public pool hydration runs a dashboard-only duplicate-detection query*;
- the REMAINDER sweep's `SCALE-9` = *public payload runs a dashboard-only duplicate-detection join*.

`#API-7` and the **remainder** `SCALE-9` are plainly the same defect. The triage line pairs `#API-7`
with that one — note it writes `SCALE-9` **without** the `#`, matching the remainder sweep's
convention, while ours is `#SCALE-9`. **Task 5 does not close `#API-7`. Leave it open and hand it
back as its own item**, exactly as the PLAN-PROMPT instructed for this case.

**`#SCALE-12` — tick as WONTFIX with the reason, not as fixed.** Record: no batching API preserves
`ShouldBeUnique`. `UniqueLock` is acquired only in `PendingDispatch` (`vendor/laravel/framework/src/Illuminate/Foundation/Bus/PendingDispatch.php:204-208`);
`Queue::bulk()` is a bare `foreach { push() }` (`Queue.php:97-102`); and `UniqueLock` appears nowhere
in `Illuminate/Bus/`, so `Bus::batch()` never honours it either. **State that the disposition does
not depend on the asset count** — a duplicate mirror job costs an R2 write plus an outbound fetch, so
batching is a bad trade at any volume. Written this way, a future sweep cannot reopen it by producing
a bigger count.

Then:

```bash
scripts/audit/archive-done.sh
```

- [ ] **Step 5: Request review and open the PR**

Use `superpowers:requesting-code-review`. The PR body must state:
- the closure rule and §A.1's proof in brief;
- that the advisory lock was NOT narrowed, and why;
- the before/after numbers;
- that `ProjectionWriterIdentityRaceTest.php` passed untouched;
- that the three unlocked hard-delete paths are unaffected (§E) and remain their own unit;
- the flag name for rollback.

---

## §G — Where this plan is uncertain

Stated rather than hidden, per the PLAN-PROMPT's §6.4.

1. **Task 6's flush strategy is not fully specified.** Two shapes are viable (replay slices vs.
   store fewer columns per projection) and which is right depends on whether replaying needs a
   second pass over the stream. The task says to pick on the measurement and record the reason.
   This is the one place the plan hands a real decision to the implementer.
2. **`MAX_COMPONENT = 2000` is a judgement, not a measurement.** No dev catalogue is near it. If
   Task 8 finds real components in the hundreds, revisit before pilot.
3. **Task 5's saving is narrower than `#SCALE-9` reads.** `SLUGGED_KINDS` is
   `['event', 'menu_item']`, so the per-item slug SELECT never fired for tracks, releases,
   products or media. The fix is real; the finding's framing overstates its reach. Report the
   measured delta, not the finding's wording.
4. **`#CACHE-2`'s remaining half is unverified** until Task 3 Step 1 runs. It may be partly closed
   already by `#SCALE-7`.

---

## RESULT

Captured 2026-08-26, Task 8. No application code was changed for this task — measurement,
regression, and audit bookkeeping only.

### Measurement (Step 1)

**No production measurement is available.** Prod carries none of `content`/`ingest`/`routing`/
`catalog` and `core.users = 0` (2026-08-25). Everything below is local/dev evidence against a
scratch Postgres database (`partna_pg_task8_measure`, disposable, dropped after use).

Rather than a git-checkout dance, the flag was flipped in-process against the SAME seeded
catalogue and the SAME `ProjectionWriter::projectStream()` call, per the task brief. Harness:
`.superpowers/sdd/2026-08-25-projectionwriter-identity-scope/scratch/measure.php` (outside
`tests/`, git-excluded via `.git/info/exclude`, deleted after this second pass — not part of any
commit).

**Fixture, both points:** one user, N pre-existing already-anchored `track` source items spread
across 3 sources (`apple_music`/`soundcloud`/`bandcamp`, N/3 each), every one a singleton with its
own unique title. One touched Spotify stream lands 6 new records: 5 unrelated singletons plus 1
that shares an ISRC with a single pre-existing `apple_music` item, so the touched component
genuinely crosses source boundaries. Two runs per point, freshly-seeded DB per run; N = 300 (the
original point) and N = 1,200 (added after review — see below):

| Metric | N=300, flag OFF | N=300, flag ON | N=1,200, flag OFF | N=1,200, flag ON |
|---|---|---|---|---|
| DB queries | 100 | 100 | **102** | **100** |
| `memory_get_peak_usage(true)` delta | 2 MB | 0 MB | **6 MB** | 0 MB |
| Wall clock (`hrtime`), 2 runs | 133 / 92 ms | 111 / 93 ms | 120 / 139 ms | 125 / 127 ms |
| Items in resolver's return set | 305 | 6 | 1,205 | 6 |

**Reviewer hypothesis, tested and CONFIRMED — with the mechanism corrected.** A reviewer flagged
that 300 items cannot show a query-count effect because both paths fit in one `BATCH_SIZE=500`
batch, and asked for a ~1,200-item point to force the whole-kind path past that boundary. Re-run at
N=1,200: **query count went from parity (100 vs 100) to a reproducible 2-query gap (102 vs 100),
identical across both repeats.** The hypothesis's shape is right; its named culprit was not.
`refreshItemCaches()` was the reviewer's guess, but it operates on `$touchedItemIds` (derived from
`$touchedCoords`, always 6 in this fixture) **regardless of the flag** — that scoping is Task 3's
separate, already-shipped change (#CACHE-4's cache half), and it stays at one batch at any
catalogue size. The actual site is `anchorSnapshot()` (`ProjectionWriter.php:1268`):
`array_chunk($coords, self::BATCH_SIZE)` over the union of `$resolution->groups`' coords. At
N=1,200 that union is whole-kind (~1,206 coords → `ceil(1206/500) = 3` chunks → 3 queries) when the
flag is off, versus the narrowed 6-coord component (1 chunk → 1 query) when it is on — a delta of
exactly 2, matching the measurement exactly. At N=300 (~306 coords) both paths fit in one chunk, so
the two whole-kind reads plus this now-identical anchor-snapshot chunk count produce true parity.

**Corrected, honest framing for the PR:** at realistic dev catalogue sizes (hundreds of items,
comfortably under `BATCH_SIZE=500`), **the query count is unchanged** — the two whole-kind reads
`§A.3` names, plus `anchorSnapshot()`'s single chunk either way, dominate and the win is invisible
in round-trip count. **The query win exists and is real, but only appears once a user's catalogue
for a kind exceeds ~500 live items** (crossing one more `BATCH_SIZE` boundary per extra 500), at
which point the narrowed path saves one query per 500 items the whole-kind path would otherwise
need to fetch anchors for. Memory is the reliable win at both sizes: peak (`true`) delta rose from
2 MB to 6 MB for the OFF path as the catalogue grew 4x, while the ON path stayed flat at 0 MB
(unresolvable at this granularity, i.e. small enough not to trigger a new page allocation) at both
sizes — showing the OFF path's memory cost scales with catalogue size and the ON path's does not.
Wall clock at both sizes was too noisy on this shared Docker setup to support a directional claim
either way (values overlapped run-to-run within each flag state) — not reported as a percentage win
in either direction. A finer-grained PHP-heap metric (`memory_get_usage(false)`) was tried in the
original pass and dropped from this revision: measuring both flag states sequentially in one PHP
process let the allocator reuse/retain blocks across runs, producing a negative "delta" for the ON
path at N=1,200 that is a measurement artifact, not a real reading — `memory_get_peak_usage(true)`
is the metric reported here because it does not have that failure mode.

**§A.3's prediction was right in substance (the two whole-kind reads are unconditional; the
downstream stages were the plan's stated saving) but its account was incomplete: it did not name
`anchorSnapshot()`'s own chunk boundary as a THIRD unconditional-until-N>500 read, which is why the
first pass of this measurement (N=300 only) read as flat "0% fewer queries" with no caveat about
scale. That reads as a genuine gap in §A.3, not a contradiction of it** — the mechanism was already
implemented correctly (`#SCALE-4`), just not called out as the reason a single small-catalogue
measurement would show query parity. Recorded here so nobody re-derives this by surprise a second
time.

### Full regression (Step 2)

**Fast lane, `php artisan test --parallel`** (same invocation as the recorded baseline, which was
also captured with `--parallel`, 10 processes):

| | Baseline (pre-branch) | This branch (HEAD `4407f68c4`) |
|---|---|---|
| Passed | 9,227 | **9,240** |
| Skipped | 3 | 3 |
| Failed | 0 | **13** |
| Assertions | 32,511 | 32,536–32,542 (small run-to-run variance, unrelated to the failures below) |

Passed count is higher than baseline, as expected (this branch adds tests). **But 13 failures are
new, and they are a real, confirmed regression this branch introduced — not flakiness, not
"unrelated to this work."**

**Root cause, fully diagnosed:** Task 7b's commit `35012611d` added
`tests/Feature/Ingest/ProjectionSyncShapesTest.php`, which calls three helper functions
(`projectableBandcamp`, `landCurrentRecord`, `projectOne`) declared in
`tests/Feature/Ingest/ProjectionWriterTest.php` — a different file. This is the exact
cross-file-test-helper hazard `tests/Feature/Architecture/CrossFileTestHelperGuardTest.php` exists
to catch (its own docblock: *"This has bitten twice"*, `poolTenant`/`shopStore` precedent) and it
IS catching it — that test is 1 of the 13 failures. The other 12 are every test in
`ProjectionSyncShapesTest.php`, which fatal with `Call to undefined function projectableBandcamp()`
whenever paratest assigns that file to a worker that does not also get `ProjectionWriterTest.php`.
Confirmed reproducible on demand:
- `./vendor/bin/pest tests/Feature/Ingest/ProjectionSyncShapesTest.php` (alone) → 12/12 fail,
  `Call to undefined function projectableBandcamp()`.
- The same file run together with `ProjectionWriterTest.php` → all pass (both files' tests
  discovered, helper resolves).
- `./vendor/bin/pest tests/Feature/Architecture/CrossFileTestHelperGuardTest.php` alone, **with no
  `--parallel` flag at all** → still fails. This is a static AST scan
  (`Tests\Support\Architecture\TestHelperScanner`), not a parallel-only flake — it fails under
  `composer test`/`composer test:ci` (serial `php artisan test`) too, so **this is a CI-breaking
  regression, not merely a local `--parallel` inconvenience.**
- Confirmed directly: a full serial `php artisan test` (no `--parallel`, 587s) produced **9,251
  passed, 1 failed, 1 warning, 3 skipped, 32,553 assertions** — exactly `CrossFileTestHelperGuardTest`
  failing alone, with all 12 `ProjectionSyncShapesTest` tests passing (serial discovery loads both
  files, so the helper resolves). This is the direct proof that `composer test`/`composer test:ci`
  fails on this branch today, independent of `--parallel` entirely.

Task 7b's own review recorded "review clean" (progress.md) — it evidently ran the new file
alongside others where the helper happened to resolve, or ran the whole suite serially, and never
exercised the isolated-file or `--parallel` case that exposes it. Per the guard test's own
docblock, the fix is mechanical (move the three helpers into `tests/Helpers/`, `require_once` from
`tests/Pest.php`, no call-site changes) — but **Task 8's brief forbids touching anything under
`tests/`, so this was diagnosed and reported, not fixed.** This must be closed before the branch
merges; it is not something Step 5's PR review should discover independently.

**Postgres lane, whole `tests/Postgres` directory** (`PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1`,
scratch DB `partna_pg_task8`, dropped after):

**244 passed, 3 skipped, 2 failed (1,169 assertions).** The 2 failures are exactly the
known-not-ours case named in the brief: `tests/Postgres/LanderFoldAtomicityTest.php`
(`SQLSTATE[42P01]: relation "ingest.record_state" does not exist` inside its own `beforeEach`) —
fails standalone, unrelated to any file this branch touches, not investigated per instruction.

`tests/Postgres/ProjectionWriterIdentityRaceTest.php` re-run in isolation: **8 passed, 71
assertions — untouched**, exactly as the plan's Step 5 PR-body requirement states.

**Applied-schema lane:** not run locally — CI gate (`composer test:schema` needs a dead
Supabase-host `.env` ref and the real `auth` schema; this branch touches no schema).

**Static analysis:** `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`.
`./vendor/bin/phpstan analyse --memory-limit=2G` → `[OK] No errors`, 1,413 files.

**Kill switch, `PARTNA_CONTENT_IDENTITY_SCOPE=false`** against
`tests/Unit/Content tests/Feature/Ingest tests/Feature/Content`: **1,024 passed, 1 skipped, 3,548
assertions.** (This run does not include `ProjectionSyncShapesTest.php`'s cross-file-helper
failures, since that hazard is independent of the flag and reproduces with the flag at either
value — confirmed above.) The rollback path is exercised and green.

### Audit ticks (Step 4)

Ticked, by ID + file + line, exactly as verified in the task brief's table:

- `audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md`: `CACHE-2` (:811) and `CACHE-4` (:860)
  ticked `[x]` — closed by commit `446357cf2` (Task 3, refresh caches for touched items only).
- Same file: `#SCALE-8` (:1224) ticked `[x]` — closed by commit `17e50e23f` (Task 6, bounded
  accumulator).
- Same file: `#SCALE-9` (:1245) ticked `[x]` — closed by commits `adaeb9ae2` + `4407f68c4` (Task 5 +
  its review follow-up gating `currentSlugs()` on batches actually containing a slugged kind).
- Same file: `#SCALE-12` (:1311) ticked `[x]` **as WONTFIX**, with the full disposition (no batching
  API preserves `ShouldBeUnique`; the live-dev measurement addendum — max 349/run, p95 120, zero
  runs over 500; the reopen trigger and its exact re-check query) written inline in the
  CONSOLIDATED.md finding itself, not just in this plan, so a future sweep reads the reasoning
  where it will actually look. **The disposition is written to not depend on the asset count** —
  the principled argument (no batching primitive in Laravel honours `ShouldBeUnique`) holds at any
  volume; the measurement only rules out an immediate reopen.
- `audits/sweeps/2026-08-18-overnight-run/audit-2026-08-18-scaling-antipatterns.md`: `CACHE-2`
  (:61) and `CACHE-4` (:110) ticked `[x]` to match the consolidated file.

**NOT ticked, correctly:** `#API-7` (`sweeps/2026-08-24-unified-actions-delta/CONSOLIDATED.md:300`)
remains open — confirmed a different defect (public pool hydration's dashboard-only
duplicate-detection query) that merely shares an ID with our `#SCALE-9`. Commit `adaeb9ae2`'s
message crediting `#API-7` is a known, already-recorded cosmetic error in the commit history — not
corrected by rewriting history. None of the remainder sweep's twin IDs (`CACHE-4` :162, `SCALE-8`
:545, `SCALE-9` :569, `SCALE-12` :631 in `sweeps/2026-08-24-unified-actions-remainder/CONSOLIDATED.md`)
were touched.

`scripts/audit/archive-done.sh` run (not `--dry-run` — its dry run and real run agreed): **"Nothing
to archive — no fully-completed audits found."** Correct — the overnight-run sweep still carries
other unticked findings (`#SCALE-13`, `#SCALE-14`, etc.) outside this plan's scope, so
`CONSOLIDATED.md` is not all-`[x]` and does not qualify for auto-archive.

### What stayed open

- `#API-7` — handed back as its own item, per the plan's own correction (§ task-8-brief.md, "the
  `#SCALE-9` ≡ `#API-7` pairing... was too shallow").
- `#SCALE-13`, `#SCALE-14`, and the rest of the overnight-run sweep's P2/P3 backlog — out of this
  plan's scope, untouched.
- **The cross-file test helper regression above** — new, found by this task, not previously known,
  blocking for merge, requires a `tests/`-only fix this task is not permitted to make.

### Where §A was right, where it needed correcting, and what this task learned beyond §A

- **§A.1's closure proof held.** `ProjectionWriterScopedResolveTest.php`'s differential test
  (scoped mapping == whole-kind mapping) passed throughout Task 8's regression runs, and this
  task's own measurement fixture — a cross-source ISRC match reaching outside the touched stream —
  exercised exactly the transitivity shape the proof is about, with no divergence.
- **§A.3's "what does NOT get faster" table was right in substance, and its own caveat ("do not
  report X% faster without both numbers") is exactly why this measurement was re-run at a second
  fixture size rather than left at one ambiguous parity figure.** The two-point measurement (300
  vs 1,200 pre-existing items) shows the query count is genuinely flat below `BATCH_SIZE=500` and
  genuinely narrows above it (100 vs 100 at N=300; 102 vs 100 at N=1,200) — both true, at different
  scales, which a single measurement point could not have distinguished from either "no win exists"
  or "the win exists but this fixture is too small to see it." §A.3's table did not name
  `anchorSnapshot()`'s own `BATCH_SIZE` chunk as a third unconditional-until-N>500 read alongside
  the two whole-kind reads it does name — that is a real, if minor, gap in §A.3's account, found
  only because a reviewer pushed back on a single-point measurement rather than accepting parity at
  face value. Memory (`memory_get_peak_usage(true)`) is the more reliable win across both sizes:
  flat at 0 MB for the scoped path regardless of catalogue size, rising with catalogue size (2→6 MB)
  for the whole-kind path.
- **§A.4 already recorded the plan's real reasoning failures during execution** (the original
  poisoning-based transitivity argument was wrong; the manual-item ruling hole, the unbound-anchor
  sweep, and the merge-orphans-anchors-outside-the-component bug were all found live, not
  predicted). Task 8 found nothing that contradicts §A.4's amendments — the differential and race
  tests built to pin them (`ProjectionWriterScopedResolveTest.php`,
  `ProjectionWriterIdentityRaceTest.php`, `ProjectionWriterMergeAnchorTest.php`) all pass.
- **What this task adds beyond §A: the plan's own quality process has a gap.** §A never claimed
  Task 7b's review would catch a cross-file test hazard — that is squarely
  `CrossFileTestHelperGuardTest`'s job, and it does catch it, on the very same commit, the moment
  the FULL suite runs it in the right shape (isolated file, or any file, under `--parallel`). The
  gap is that Task 7b's review ran a narrower slice, per this same programme's own recorded
  precedent in CLAUDE.md ("the PG stand-in DDL drifts silently... two reviews missed it") — a
  different lane, same shape of failure: **a review that does not run the guard/PG-lane in the
  configuration that actually exercises it reads green for the wrong reason.** This is not a defect
  in §A's design reasoning; it is evidence that "Task N: review clean" in `progress.md` needs a
  standing checklist item — run the new/changed test file BOTH alone and under `--parallel` — not
  just a green run of whatever was in front of the reviewer.
- **`#SCALE-8`'s real-world memory saving is ≈5%** on a realistic Bandcamp-shaped projection (481 →
  456 bytes, from Task 6's own measurement) — the 666× figure this plan and its commits cite
  elsewhere is a synthetic fixture with a 200 KB unused key, included to bound a FUTURE
  fat-projector risk, not to describe today's typical payload. Reported here exactly as Task 6's
  report recorded it, undiluted.
- **`#SCALE-9`'s blast radius is `ContentItemSlugAllocator::SLUGGED_KINDS = ['event', 'menu_item']`**
  — confirmed directly in `app/Services/Content/ContentItemSlugAllocator.php:54`. The original
  finding's framing ("every projection run for a slugged kind") overstates its reach: tracks,
  releases, products, media and reviews never triggered the per-item slug SELECT the finding
  describes, before or after the fix.
- **`MAX_COMPONENT = 2000` (§G.2) remains unexercised.** This task's largest fixture (300 items,
  1 shared cross-source key) produced a touched component of 6 — nowhere near the cap. §G.2's
  standing note (revisit before pilot if real components approach the hundreds) is unchanged by
  this measurement and should not be read as resolved by it.
