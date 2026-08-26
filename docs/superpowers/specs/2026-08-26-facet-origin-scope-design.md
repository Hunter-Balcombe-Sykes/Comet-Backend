# Facet origin scope — design

**Status:** approved 2026-08-26, not yet implemented.
**Branch:** `fix/manual-merge-facet-loss-2026-08-26`, off `development` @ `ce9fd4021`.
**Origin:** `docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope.md` §H.4, raised by
review during the identity-scope follow-up work and deliberately not bundled there.

**Goal:** a merge must not destroy the owner's hand-authored photos, prices, tags and variants — and
must not be able to destroy them later either.

---

## 1. The defect

Merging two hand-added items deletes one of them, and its collection facets go with it.

`mergeInto()` (`app/Ingest/Projection/ProjectionWriter.php:1452`) repoints the loser's anchors and
source items onto the survivor, moves `item_links` and `item_slugs` by hand, then hard-deletes the
loser when it carries no `section_items` / `manual_overrides` curation. Every facet table
foreign-keys `content.items(id) ON DELETE CASCADE` (`supabase/migrations/20260727140000_content_schema.sql`
— `f_text`:189, `f_link`:199, `item_media`:372, same pattern throughout), so the delete takes the
loser's whole facet footprint.

**The hard delete is correct and stays.** Sparing the row leaves an item with no source items behind
it — a ghost that `PoolResolver`'s library query (filtering on `user_id + kind + removed_at` only)
lists forever. `moveLinks()`'s docblock already records that this was considered and rejected.

**Why the connector lane is immune and the manual lane is not.** Facets are derived, so on the
connector lane the cascade is a cache invalidation: `ReprojectSourcesJob` replays `ingest:project`
and `writeFacets()` rewrites them under the kept id from `ingest.record_versions`. A manual coord has
no landed records — `writeManualItem()` wrote its facets once from an HTTP payload that is persisted
nowhere. There is nothing to replay, so the cascade is terminal.

## 2. The root cause is not the merge

Moving the rows at merge time is **not sufficient**, and finding out why is what shaped this design.

`replaceCollections()` deletes by `(item_id, source_id)`:

```php
DB::table("content.{$table}")
    ->whereIn('item_id', $itemIds)
    ->where('source_id', $contentSourceId)
    ->delete();
```

and the batch it iterates is `array_chunk(array_keys($mediaByItem), $chunk)` where
`$mediaByItem[$itemId] = $media;` is assigned **unconditionally**, empty array included
(`ProjectionWriter.php:1868`). So *any* write to an item deletes **all** of that item's collection
rows for that source and reinserts only what the current projection carried.

There is exactly **one manual `content.sources` row per user** (`ensureManualSource()`, priority
`MANUAL_SOURCE_PRIORITY` = 200). So two hand-added items bound to one item share a `source_id` and
are indistinguishable at the grain the replace uses. Moved rows would be wiped by the survivor's
next save.

**The same bug exists latently on the connector lane.** It is masked because a full projection run
writes every coord of a source together, so the replace deletes once and reinserts the union. A run
that touches only some of an item's coords has the same clobbering behaviour.

**Therefore the fix is to give collection rows an origin identity, not to patch the merge.**

## 3. Decisions taken (attended session, 2026-08-26)

1. **Merge semantics: union the sets, survivor wins single-value fields.** Photos, prices, tags and
   variants from both sides are kept. Single-value facets (`f_text`, `f_link`, …) keep the
   survivor's, because their PK is `(item_id, source_id)` and only one row can exist per source
   anyway. Consistent with the offers schema comment: *"two platforms selling the same dish at
   different prices are both true."*
2. **Approach: tag each row with its origin.** Rejected alternatives — per-coord manual sources
   (breaks the one-manual-source-per-user rule, its priority-200 semantics and its tests, and
   multiplies `content.sources`), and move-rows-only (durability would depend on every hand-add edit
   screen round-tripping everything it displays, which is per-kind and partly frontend).
3. **Scope: fix both lanes, connector side behind a flag.** The manual lane gets the new scoping
   immediately; the connector lane gets it behind config, default off, flipped once dev is clean.
4. **Dedupe on fold, and cap it.** A photo both sides share must not render twice, and the fold must
   not be able to grow an item without bound.

**What is NOT a growth risk, stated because it was asked and the answer is load-bearing:** repeated
syncing does not accumulate. Each run replaces its own source's rows, so the ceiling is
(coords bound to the item) × (what each currently declares), not elapsed time or run count. The
union adds rows once per merge.

## 4. Schema

One migration in `supabase/migrations/`, raw SQL, no Laravel migration file (composer guard rejects
them). Four tables:

```sql
ALTER TABLE content.item_media    ADD COLUMN source_item_id uuid NULL
    REFERENCES content.source_items (id) ON DELETE CASCADE;
ALTER TABLE content.offers        ADD COLUMN source_item_id uuid NULL ... ;
ALTER TABLE content.item_tags     ADD COLUMN source_item_id uuid NULL ... ;
ALTER TABLE content.item_variants ADD COLUMN source_item_id uuid NULL ... ;
```

Plus one index per table on `(item_id, source_item_id)` to serve the scoped delete. **One
`CONCURRENTLY` statement per file** if created concurrently — the CLI pipelines multi-statement
files and `CREATE INDEX CONCURRENTLY` fails with `25001` inside one (`supabase/migrations/CONVENTIONS.md` §1).

**Why `source_items.id` is the right anchor:** `mergeInto()` repoints `source_items.item_id`; it does
not delete the row. So a facet row keyed on `source_item_id` keeps its origin across a merge, and
still cascades correctly when the source item itself is really deleted.

**Two tables deliberately excluded:**

- **`collection_items`** — PK is `(collection_id, item_id)` with `source_id` outside it, so an item
  can be in a collection only once regardless of origin. Membership is per-item by design; there is
  no per-coord set to preserve. Its existing source-scoped replace is already correct.
- **`f_action`** — has **no writer anywhere in `app/`** (verified: only `SectionRule`,
  `SectionCandidates`, `KindRegistry`, `FacetRegistry` and a column list reference it). Same posture
  as `content.f_file`. No rows exist, nothing to lose.

## 5. Behaviour

### 5.1 Origin-scoped replace

`replaceCollections()`'s delete gains an origin predicate:

```sql
AND (source_item_id IN (<source_items.id for the coords this write covers>)
     OR source_item_id IS NULL)
```

A write for coord A stops touching coord B's *attributed* rows.

**The `IS NULL` half is load-bearing and must not be dropped as redundant.** Scoping to
`source_item_id IN (...)` alone would never delete un-attributed rows, so every pre-backfill and
every ambiguous row (§5.3) would survive forever as an orphan that nothing replaces — turning a
data-loss bug into a data-duplication bug. `NULL` must keep meaning "unscoped, replaced exactly as
today". That is also what makes the flag-off path byte-for-byte on a table whose column is entirely
NULL.

Consequence to state plainly: a row is protected only once it *has* an origin. Rows the backfill
could not attribute stay clobberable until their next write stamps them.

Gated by `config('partna.content.facet_origin_scope')` for the connector lane; unconditional for the
manual source. **Flag off must produce the original statement unchanged** — the identity-scope work
learned this the hard way when a rewritten query shape broke PG-lane tests that hook statement text
through `DB::listen`.

### 5.2 The merge fold

After `mergeInto()` repoints `source_items` and **before** it deletes the loser — otherwise the
cascade has already taken them — repoint the loser's rows in the four tables onto the survivor,
**stamping `source_item_id` on any moved row that has none.** An unstamped moved row would be
clobbered by the survivor's next save (§5.1), which is precisely the failure this design exists to
prevent. Then:

- **Dedupe.** Drop a moved `item_media` row whose `(asset_id, role)` the survivor already carries —
  image *files* are already deduped by `content.media_assets`' `UNIQUE (user_id, fingerprint)`, so
  the duplicate is a second reference to one asset and would render twice. `offers`, `item_tags` and
  `item_variants` dedupe on their natural value tuple.
- **Renumber `position`** so moved rows sort after the survivor's existing ones, per `(item_id, role)`
  for media. `item_media`'s index is `(item_id, role, position)` and is not unique, so colliding
  positions are legal but produce unstable render order.
- **Cap at 24 media rows per item**, applied **only to the fold** — a connector legitimately
  returning 50 images must not be truncated, so normal projection is untouched. When it bites, log
  `user_id`, `item_id`, `kept`, `dropped`. A silent cap is a defect in this codebase.

Runs inside `mergeInto()`, which is already under the identity advisory lock and inside
`resolveItemsLocked()`'s transaction. No new lock, no new transaction. **No try/catch that recovers
inside the transaction** — this repo has shipped 25P02 three times that way.

### 5.3 Backfill

Set `source_item_id` where it is unambiguous: the item has exactly one live source item on that
source. Ambiguous rows stay NULL and behave exactly as today, healing on their next write. The
backfill therefore cannot corrupt anything and needs no down-migration beyond dropping the columns.

## 6. Testing

**`tests/Postgres/` is the primary lane** — this is FK, cascade and delete-scoping behaviour, and
SQLite does not reproduce cascades (the review that found this defect could only infer them).
`composer test:pg` is mandatory for any `ProjectionWriter` change regardless.

Cases that must exist:

1. Merge two hand-added items each with a photo → survivor carries both. *(Fails today.)*
2. Then save one of them → both photos still present. **This is the case that proves origin scoping
   and that a move-only fix would have failed.**
3. Shared photo on both sides → one row, not two.
4. Fold exceeding the cap → capped, and the log line fires.
5. Connector run with the flag **off** → statement text and row outcome byte-identical to current.
6. Connector run with the flag **on**, one coord of a two-coord item touched → the untouched coord's
   rows survive. *(The latent bug.)*
7. A row left NULL by the backfill is still replaced on the next write, not orphaned — the `IS NULL`
   half of §5.1. Without this test the predicate can be "simplified" into a duplication bug that no
   other case catches.

Each new test must be shown red before the fix. Shared helpers go in `tests/Helpers/`, required from
`tests/Pest.php` — a helper declared in one test file and called from another breaks `--parallel`
(`CrossFileTestHelperGuardTest`).

## 7. Constraints

- No Laravel migration files; raw SQL in `supabase/migrations/` only.
- `composer test:pg` mandatory. `composer test:schema` cannot run locally — CI only.
- The identity advisory lock stays `identity:{user_id}:{kind}`.
- Callers must not wrap `withIdentityLock()` in `DB::transaction()`.
- `mergeInto()` is shared by both lanes: every change to it is a connector-lane change too.

## 8. Out of scope

- Fixing single-value facet loss on merge. Only one row can exist per `(item_id, source_id)`, so a
  winner must be picked; that is inherent to merging, not a defect.
- `ItemMerger` — no production caller (asserted in `PoolCacheLaneSeamTest`/`PoolCacheLanesTest`).
- The dashboard's edit screens. Origin scoping removes the dependency on what they round-trip; it
  does not change them.

## 9. Where this is uncertain

- **The backfill is untested against production volume**, because production has no `content` schema
  at all (`CLAUDE.md` §Content pools) and prod reconciliation is separate, deferred work. It will
  only ever have run on dev when it merges.
- **The 24 cap is a judgement, not a measurement.** No real merged item has been observed near it.
  It is config-driven so it can be tuned without a deploy.
- **Whether the connector flag ever gets flipped on** is a follow-up decision that needs dev
  observation first. Landing it off is not a half-measure; it is the rollback the identity-scope work
  wished it had kept.
