# Facet origin scope — design

**Status:** approved 2026-08-26, IMPLEMENTED 2026-08-26 — see §10 Results.
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

**The fold runs ONLY on the branch where the loser is actually deleted** — i.e. inside
`if (! $hasCuration)`, not before it. A loser carrying `section_items` or `manual_overrides` curation
is spared by `mergeInto()` and survives as a row that is still rendered wherever it is pinned.
Moving its photos to the survivor would strip a still-visible item bare. Nothing is at risk on that
branch anyway: the item is not deleted, so nothing cascades. Getting this wrong turns a data-loss
fix into a data-loss bug on exactly the items the owner cared about most.

Within that branch, and **before** the delete — otherwise the cascade has already taken them —
repoint the loser's rows in the four tables onto the survivor, **stamping `source_item_id` on any
moved row that has none.** An unstamped moved row would be clobbered by the survivor's next save
(§5.1), which is precisely the failure this design exists to prevent. Then:

- **Dedupe.** Drop a moved `item_media` row whose `(asset_id, role)` the survivor already carries —
  image *files* are already deduped by `content.media_assets`' `UNIQUE (user_id, fingerprint)`, so
  the duplicate is a second reference to one asset and would render twice. `offers`, `item_tags` and
  `item_variants` dedupe on their natural value tuple.
- **Renumber `position`** so moved rows sort after the survivor's existing ones, per `(item_id, role)`
  for media. `item_media`'s index is `(item_id, role, position)` and is not unique, so colliding
  positions are legal but produce unstable render order.
- **Cap at 8 media rows per item** (`PARTNA_CONTENT_MERGE_MEDIA_CAP`), applied **only to the fold**.
  Normal projection is untouched: a connector legitimately returning 50 images must not be
  truncated.

  **The cap drops only rows being MOVED IN; the survivor's existing rows are never removed.** Stated
  as a rule because the obvious implementation — trim the combined set to 8 — would truncate a
  connector item that already legitimately carries 20 images the moment anything merged into it,
  destroying live data to enforce a guard that exists to prevent growth. So: move rows while the
  total is under the cap, stop when it is reached, and if the survivor is already at or over it,
  move nothing. Log `user_id`, `item_id`, `kept`, `dropped` whenever any row is dropped. A silent cap
  is a defect in this codebase.

  8, not 24: the profile gallery caps at 6 (`PARTNA_GALLERY_IMAGE_MAX`), and a real product or menu
  item carries one to three images. 24 would require merging roughly a dozen items before it noticed
  anything, which is a cap that never fires — worse than none, because it reads as a guard while
  guarding nothing.

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
8. **Merge where the loser carries curation** (a `section_items` pin or a `manual_overrides` row) →
   the loser is spared, and **keeps its own photos**. Pins the `! $hasCuration` gate in §5.2. This
   is the case where a careless fold silently empties a still-rendered item.
9. **Survivor already at or above the cap** → nothing is moved in, and no existing row is removed.
   Pins that the cap can only ever drop incoming rows.

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
- **The cap of 8 is a judgement, not a measurement.** No real merged item has been observed near it.
  It is config-driven so it can be tuned without a deploy. It was 24 in the first draft and was cut
  on the reasoning that a cap which never fires is worse than none.
- **Whether the connector flag ever gets flipped on** is a follow-up decision that needs dev
  observation first. Landing it off is not a half-measure; it is the rollback the identity-scope work
  wished it had kept.

---

## 10. Results (implemented 2026-08-26)

Shipped on `fix/manual-merge-facet-loss-2026-08-26`, rebased onto `development` @ `1151b0b6e`
(PR #311, the audit tranche) **before** Task 1 rather than at merge time — that tranche had
rewritten `recordCandidates()`, grown `resolveItemsLocked()` and moved the `'content' => [`
config block, so every line reference in the plan was stale.

### Measured

| Lane | Before | After |
|---|---|---|
| Fast suite (`php artisan test --parallel`) | 3 skipped, 9358 passed, **2 failed** | 3 skipped, **9362 passed, 0 failed** |
| PG lane (`phpunit.pg.xml`) | 2 failed, 3 skipped, **252 passed** | 2 failed, 3 skipped, **259 passed** |
| `tests/Feature/Ingest/` with the flag OFF | 543 passed | 545 passed (543 pre-existing + 2 new), 0 failed |

The 2 PG-lane failures are pre-existing and untouched: `LanderFoldAtomicityTest` dies in
`beforeEach` on `ingest.record_state`. The 2 fast-suite failures were CAUSED by this work and are
fixed within it — see "Guards this tripped" below. The baseline of 252 was re-measured on the
rebased commit; the plan's recorded 244 predated the tranche.

Seven new PG-lane tests (3 scoping + 4 fold) and 2 new fast-lane tests.

### Spec §6 coverage

| Case | Where | Note |
|---|---|---|
| 1 merge keeps both photos | `MergeFacetFoldTest` | red first: 1 row, not 2 |
| 2 then save one — both survive | `FacetOriginScopeTest` | red first: 1 row, not 2 |
| 3 shared photo deduped | `MergeFacetFoldTest` | |
| 4 fold exceeds cap, log fires | `MergeFacetFoldTest` | red first: 5 rows, not 3 |
| 5 connector, flag off, unchanged | `FacetOriginConnectorScopeTest` + whole `Feature/Ingest/` | |
| 6 connector, flag on, partial run | `FacetOriginConnectorScopeTest` | see below |
| 7 NULL row still replaced | `FacetOriginScopeTest` | green before AND after, by design |
| 8 curated loser keeps its media | `MergeFacetFoldTest` | green before AND after, by design |
| 9 survivor already at cap | `MergeFacetFoldTest` | folded into case 4's fixture |

**Case 6 was not in the plan's task list.** The plan's self-review claimed Tasks 2–4 covered it, but
every test there exercises the MANUAL lane, where scoping is unconditional and the flag is therefore
unobservable. It is now `tests/Feature/Ingest/FacetOriginConnectorScopeTest.php`, which runs one
fixture twice and asserts the flag actually gates: **2 rows on, 1 row off.** The flag-off half is a
pin, not an aspiration — the connector lane ships on today's behaviour.

That test is in the FAST lane deliberately. It is a delete-PREDICATE test: nothing is deleted from
`content.items`, no FK fires, and SQLite evaluates the same `WHERE` clause Postgres would. The
cascade half genuinely cannot live there, and did not.

Producing a partial connector run is the whole difficulty, and the first fixture got it wrong:
clearing `is_current` on the record VERSION changes nothing, because `projectStream()` joins
`ingest.record_state` to its `current_version_id` and filters `rs.tombstoned_at IS NULL`. The run
stayed whole, and the pair passed for the wrong reason in one direction while failing in the other.
Tombstoning the `record_state` row is the real mechanism.

### What the plan did not anticipate

Three consumers had to move with `$byItem`'s shape change; the plan named one.

1. **`writeFacets()` reads `$byItem` twice.** The singleton-facet fold loop below the
   `replaceCollections()` call iterates the same array. Left alone it would have fatalled on
   `$projection['facets']`.
2. **`resolveMediaAssets()` fingerprints the media ENTRY.** Handed the origin wrapper it finds no
   `'url'`, mints no asset, and leaves every `item_media.asset_id` null — silently. It now gets an
   unwrapped view at the call site.
3. **`createTenant()` is unusable in `tests/Postgres/`.** The plan's test snippets called it; it is
   used in none of the files there, because it inserts `handle`/`auth_user_id`/`status` into
   `core.users` and that lane's stand-in is a one-column table.

### Guards this tripped

- **`CheckpointSuppressionStalenessTest`.** Binding the DELETE to `$delete` before `->delete()`
  changed the source line of a suppressed SQL-injection finding, so its content-addressed hash went
  dead and the finding silently REOPENED. Re-vetted (`$table` still comes from the same closed
  literal `$tables` map) and re-hashed `66f9a31cbb50` → `19f4c8354118`.
- **`NoLocalCanonicalTableDdlTest`.** The shared PG stand-in DDL was first written to
  `tests/Helpers/`, which the guard scans. It excludes `tests/Postgres/` by path precisely because
  PG-lane files legitimately hand-roll PG-flavoured DDL, and that exclusion is earned by
  `uses(PostgresTestCase::class)->in(__FILE__)` — something a helper file can never do. Resolved by
  following the convention the sibling PG files already use (DDL inline, `fos_`/`mff_` prefixed)
  rather than widening the guard's baseline.

### On §9's uncertainties

- **The cap of 8 is still a judgement, not a measurement.** Nothing here observed a real merged item
  near it. It remains config-driven so it can be tuned without a deploy.
- **The backfill is still untested against production volume**, and now certainly always will be:
  production has no `content` schema at all, so it has only ever run on dev.
- **`position` renumbering (§5.2) is deliberately NOT implemented.** `item_media`'s
  `(item_id, role, position)` index is not unique, colliding positions are legal, and no case in §6
  can observe the difference — it would be untested code. If render order turns out to matter it is a
  follow-up with its own failing test, not a line added here on faith.

### The connector flag: do NOT flip it yet

`PARTNA_CONTENT_FACET_ORIGIN_SCOPE` stays `false`. The evidence for scoping is real, but it is all
from the manual lane plus one synthetic connector fixture; no dev connector traffic has run against
it. Flipping it needs dev observation first — and note the `config:cache` caveat the identity-scope
flag documents: a redeploy is required before a change to this value is observed at all.
