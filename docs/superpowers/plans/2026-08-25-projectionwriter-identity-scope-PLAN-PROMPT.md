# PLAN PROMPT — `ProjectionWriter`: scope identity resolution to what changed

**Run this by pasting:** *"Work `docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope-PLAN-PROMPT.md`."*

> **This is an ATTENDED planning session. Josh is present and available to answer.**
> It is the opposite of the overnight audit-fix prompts: **ask questions freely, stop at genuine
> forks, and do not implement anything.** The deliverable is a written plan and one settled design
> decision — not a diff.

**Why this exists.** These five findings were pulled out of the overnight pre-launch hardening tranche
on 2026-08-25 (`audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE.md`, unit 1) because they
are one architectural change to a 2,456-line file, and the hard part is a correctness decision that
should not be made unattended. Everything below was gathered in that session so you do not have to
re-derive it.

---

## 1. Scope

**Five defects across six IDs, one change** (all from the overnight-run sweep — ⚠️ key them by ID **plus** source
file; `#SCALE-8`, `#SCALE-9`, `#SCALE-11` and `#SCALE-12` all have same-ID twins in the remainder sweep
that are *different findings* and are **not** in scope here):

| ID | Symptom |
|---|---|
| `#CACHE-2` | The same over-resolution reached from the owner's manual write path |
| `#CACHE-4` | Cache amplification from the same shape |
| `#SCALE-8` | Whole stream accumulated in memory |
| `#SCALE-9` ≡ `#API-7` | Per-item `ensureCurrent()` inside the batch loop |
| `#SCALE-12` | One mirror job dispatched per asset, synchronously, inside the projection loop |

⚠️ **`#API-7` is an inferred pairing, not a certain one.** The undivided tranche's §3 listed
`SCALE-9` ≡ `#API-7` as a duplicate pair but assigned `#API-7` to no unit, while `#SCALE-9` appears
only in unit 1. Same-ID twins exist across sweeps, so **confirm the pairing against
`BACKLOG-TRIAGE.md` before ticking `#API-7`** — if it turns out to be a distinct defect, hand it back
as its own item rather than closing it here.

**Deliverable:** a plan file at `docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope.md`
(drop the `-PLAN-PROMPT` suffix) that a later `execute` session can follow. **Do not write code in this
session** beyond throwaway probes.

**Skills:** invoke `superpowers:brainstorming` before any design work and `superpowers:writing-plans`
before writing the plan file. This is exactly the "creative work / multi-step task" they exist for.

---

## 2. What the code actually does — verified 2026-08-25, do not re-derive

**File:** `app/Ingest/Projection/ProjectionWriter.php` (2,456 lines).

`projectStream()` ends like this:

```php
$itemByCoord = $this->resolveItems($userId, $projector::kind());              // WHOLE KIND
$this->writeFacets($contentSourceId, $userId, $projections, $itemByCoord);    // already touched-scoped
$this->refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))));  // WHOLE KIND
```

**Note the middle line is already scoped to what changed** (`$projections` holds only this run's
coords). It is the **first and third** that run over the user's entire catalogue for that kind. That
asymmetry is the single most useful fact for scoping this work.

Other load-bearing facts, each with its source:

- **The `user_id + kind` scope is deliberate and is the point of the system.** `ProjectionWriter.php:205-206`:
  *"resolveItems() below reads `content.identity_keys` scoped by `user_id + kind`, NOT `stream_id` —
  that cross-source union is the whole point of the identity system."* Any narrowing must preserve it.
- **`resolveItems()` holds a Postgres advisory xact lock** `identity:{user_id}:{kind}` across the whole
  read → resolve → `bindGroup()` → `recordCandidates()` → closing UPDATE cycle (`#LIFE-1`). Its docblock
  is unusually good — **read it in full before planning.** Two constraints from it:
  - It is advisory rather than `lockForUpdate()` *because* the protected set is "every live source item
    of this (user, kind)", which a racing writer may **grow** mid-computation — "you cannot lock rows
    that do not exist yet."
  - Callers must **not** wrap it in `DB::transaction()` — that degrades the lock to a SAVEPOINT and
    silently gives it the outer transaction's lifetime.
- **Four identity mutators do NOT take that lock** and are listed in the same docblock under
  "WHAT THIS DOES NOT COVER": `writeFacets()`/`refreshItemCaches()` (run after the commit),
  `ItemMerger::merge()` (currently unreachable), `StaffServiceManagementController::forceDestroy()`,
  and `ContentRetireChannelKindCommand`. The plan should say whether it makes any of these worse.
- **Record ordering is an invariant, not a detail.** `ProjectionWriter.php:158-169`: records are read
  `orderBy('rv.first_seen_at')` **then** `orderBy('rs.key')`, and the `rs.key` tiebreak is mandatory —
  LIMIT/OFFSET paging over a non-unique order silently skips and duplicates rows. `writeFacets()`'s
  per-column `array_replace` fold then lets the **last-processed record win each column**. Reordering
  the read silently changes which record's headline a page shows.
- **`->cursor()` does not bound memory under `pdo_pgsql`** — libpq buffers the whole result set
  client-side regardless of PHP-level iteration. The file already uses `lazy(500)` for this reason
  (`:155-157`). Relevant to `#SCALE-8`.
- **One transaction per record**, spanning the source-item upsert and its identity-key replace-set,
  deliberately *not* around the whole loop (that would pin an old xmin for the length of the stream).

---

## 3. The decision this session exists to make

**How much must be rebuilt when one record changes?** Three candidate boundaries, worked through on
2026-08-25:

### Option A — per-coord
Resolve only the coords this run wrote.

- **Pro:** maximum saving; work scales with change size, not catalogue size.
- **Con:** **breaks the cross-source union outright.** A new Spotify track cannot discover its Apple
  Music twin if the twin was not touched, so `createItem()` mints a duplicate instead of joining the
  group. This is precisely what `:205-206` says the scope exists to prevent.
- **Assessment: rejected.** Recorded here so the plan does not rediscover it.

### Option B — per-key closure
Load touched coords → collect their identity keys → pull in every other live source item of that kind
sharing those keys → repeat transitively until closed.

- **Pro:** preserves the merge invariant exactly. Work scales with the size of the **affected identity
  groups** rather than the whole kind. This is the genuinely correct architecture.
- **Con:** the closure can degenerate to the whole kind when one generic key (a common title) links
  everything, so it needs a cap **and** a defined fallback. The advisory lock must still be
  `(user, kind)` regardless, per the docblock reason above. Most code, most branches, most test surface.
- **The open question this session must settle:** what invariant guarantees correctness when a
  *neighbouring* item's identity depends on a changed one — i.e. is one hop enough, and if not, what
  bounds the transitive closure? **Settle this in the plan, not in the implementation.**

### Option C — keep the resolve, cut the downstream amplification
Leave identity resolution untouched. Fix only what it fans out into: refresh caches for touched items
instead of all of them, hoist the per-item `ensureCurrent()` out of the batch loop, batch the mirror-job
dispatch out of the projection loop, bound the stream accumulation.

- **Pro:** **zero correctness risk** — the identity computation is untouched, so no merge invariant can
  break. Takes `#SCALE-9`, `#SCALE-12`, `#SCALE-8` and most of `#CACHE-2`/`#CACHE-4`'s amplification.
  Reviewable as a normal diff, provable with a query count.
- **Con:** the O(catalogue) resolve read itself remains. Closes four of five findings, not five.

**Recommendation carried in from 2026-08-25: split the work — C first as its own shippable unit, B
planned properly behind it.** That is a recommendation, not a decision. **Put the options to Josh and
let him choose** — that is why this session is attended.

**Ask Josh at minimum:**
1. C-then-B, or straight to B?
2. If B: is a **one-hop** closure acceptable, or must it be transitive? (This is the crux.)
3. What should happen when the closure cap bites — fall back to a full resolve, or proceed and log?

---

## 4. Constraints the plan must honour

- **`composer test:pg` is mandatory for any change here**, not optional. `CLAUDE.md`: the PG stand-in
  DDL is hand-written and drifts silently from writer changes; a green SQLite run says nothing. Slice 5a
  turned it red for 7 tests and two reviews missed it on a green SQLite run.
  Local recipe: `CREATE DATABASE partna_pg_lane_scratch` on `127.0.0.1:54322`, then
  `PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable ./vendor/bin/pest -c phpunit.pg.xml <path>`,
  then drop it. **Never** override `PG_LANE_DISPOSABLE` against the `postgres` database itself.
- **`tests/Postgres/ProjectionWriterIdentityRaceTest.php` already reproduces the race** the advisory
  lock closes. The plan must say how that test survives, and what new PG-lane test proves the new
  boundary.
- **Measurements are part of the deliverable**, not a nice-to-have: query count and peak memory for a
  representative stream, before and after. `CLAUDE.md`'s rule — "should be faster" is not a result.
- **The three-lane cache contract still applies.** `ProjectionWriter::bumpSite()` fires lane 1 **only,
  by design** (a per-item primitive; batch callers discharge lanes 2+3 once at the request boundary).
  `projectStream()` calls `invalidateSiteLanes($userId)` for all three. Do not "fix" `bumpSite()` into
  calling `SiteCacheLanes::bust()`.
- **No Laravel migrations.** If the plan needs an index or a schema change, that is a separate
  `supabase/migrations/` unit with its own review — call it out explicitly rather than folding it in.
- **A silent cap is a defect.** If the design caps anything — closure size, candidate count, batch size
  — the cap must be observable when it bites, with user and kind. Items silently ceasing to merge is
  invisible on a green test run and is the worst available failure mode here.

---

## 5. Related work already deferred or done

- **`13g` in `EXECUTE-PART-3.md`** touches the adjacent `recordCandidates()` path (`#CACHE-1` ≡
  `#SCALE-11` overnight, `#SCALE-10` ≡ `#CACHE-6`) and is **explicitly forbidden** from changing the
  resolution scope, the lock, the transaction boundary, or which candidates are considered. **Check
  whether PART 3 ran and what it changed** before planning — `git log -- app/Ingest/Projection/ProjectionWriter.php`.
- Prod carries **none** of this: `content`, `ingest`, `routing` and `catalog` do not exist there, prod
  is stopped and `core.users` = 0 (verified 2026-08-25). The blast radius today is dev-only. That lowers
  the risk of shipping, and it also means **no production measurement is available** — plan around
  local/dev evidence only.

## 6. Definition of done for THIS session

1. Josh has chosen a boundary (A/B/C or a variant), and the reasoning is written down.
2. `docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope.md` exists and contains: the
   chosen boundary and the invariant that makes it correct; the file-by-file change list; the PG-lane
   tests that prove it; the measurement method; the rollback story; and anything split out into its own
   unit (migrations, `13g` overlap).
3. **No production code changed.** `git status` clean apart from the plan file.
4. If the session runs out of road, the plan file says exactly where and what is still open — a
   half-finished plan that is honest about it is worth more than a complete-looking one that guessed.
