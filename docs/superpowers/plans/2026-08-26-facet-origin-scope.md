# Facet Origin Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop a merge destroying the owner's hand-authored photos, prices, tags and variants — and make it impossible for a later save to destroy them either.

**Architecture:** Give collection-facet rows an origin (`source_item_id`), scope `replaceCollections()`'s delete to that origin, and make `mergeInto()` fold the loser's rows onto the survivor instead of letting the cascade take them. A config flag keeps the connector lane on today's behaviour until dev proves the new scoping.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4. Fast lane = SQLite; this behaviour is proven in `tests/Postgres/` (`composer test:pg`).

**Spec:** `docs/superpowers/specs/2026-08-26-facet-origin-scope-design.md`

## Global Constraints

- **No Laravel migration files.** Composer guard rejects them. Schema changes are raw SQL in `supabase/migrations/`.
- **`composer test:pg` is MANDATORY** for every task touching `app/Ingest/Projection/ProjectionWriter.php`. The PG stand-in DDL is hand-written and drifts silently from writer changes.
- **Local PG lane recipe** (`.env`'s `DB_HOST` is dead, a bare `composer test:pg` reports green having asserted nothing):
  ```bash
  docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "CREATE DATABASE partna_pg_lane_facets"
  PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
    DB_DATABASE=partna_pg_lane_facets DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
    ./vendor/bin/pest -c phpunit.pg.xml
  docker exec supabase_db_Partna-Development psql -U postgres -d postgres -c "DROP DATABASE partna_pg_lane_facets"
  ```
  `PG_LANE_REQUIRED=1` is mandatory. **Known pre-existing baseline: `2 failed, 3 skipped, 244 passed`** — the 2 are `tests/Postgres/LanderFoldAtomicityTest.php` dying in `beforeEach` on `ingest.record_state`. Do not bisect over them.
- **`composer test:schema` cannot run locally** — CI only.
- **The identity advisory lock stays `identity:{user_id}:{kind}`.** Do not narrow it.
- **No try/catch that RECOVERS inside `$work`** — poisons the transaction with 25P02. This repo has shipped that defect three times.
- **Never `Cache::forever()`** — instance-wide `volatile-lru` means a key without a TTL can be evicted under memory pressure. This plan adds no cache keys.
- **Shared test helpers go in `tests/Helpers/`**, required from `tests/Pest.php`. A helper declared in one test file and called from another breaks `--parallel` (`CrossFileTestHelperGuardTest`). This plan's helpers are file-local and uniquely prefixed `fos`.
- **A silent cap is a defect.** Every cap logs `user_id`, `item_id`, `kept`, `dropped` when it bites.
- **Production has no `content` schema at all.** This migration lands on dev only; prod reconciliation is separate, deferred work.

## File Structure

| File | Responsibility |
|---|---|
| `supabase/migrations/20260826120000_facet_source_item_origin.sql` | **Create.** Adds `source_item_id` + index to 4 tables, backfills the unambiguous rows. |
| `config/partna.php` | **Modify** (`'content' => [` block, ~line 1207). Two new keys. |
| `app/Ingest/Projection/ProjectionWriter.php` | **Modify.** `writeFacets()` carries coords; `replaceCollections()` stamps and scopes; `mergeInto()` folds. |
| `tests/Postgres/FacetOriginScopeTest.php` | **Create.** Delete-scoping and backfill semantics. |
| `tests/Postgres/MergeFacetFoldTest.php` | **Create.** The fold, dedupe, curation gate, cap. |

Two test files rather than one: scoping and folding fail for different reasons and a reviewer can reject one without the other.

---

## Task 1: Migration — give collection facets an origin

**Files:**
- Create: `supabase/migrations/20260826120000_facet_source_item_origin.sql`
- Test: `tests/Postgres/FacetOriginScopeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: column `source_item_id uuid NULL` on `content.item_media`, `content.offers`, `content.item_tags`, `content.item_variants`, FK to `content.source_items(id) ON DELETE CASCADE`; index `idx_<table>_origin` on `(item_id, source_item_id)`.

- [ ] **Step 1: Write the migration**

Plain `CREATE INDEX`, not `CONCURRENTLY` — the Supabase CLI sends a multi-statement file as one libpq pipeline and `CONCURRENTLY` fails there with `SQLSTATE 25001` (`supabase/migrations/CONVENTIONS.md` §1). These tables are small on dev and this is dev-only, so a brief lock is the right trade against splitting into five files.

```sql
-- Give collection-facet rows an ORIGIN: which source item contributed them.
--
-- Without it, replaceCollections() can only scope its delete to
-- (item_id, source_id) — and there is exactly ONE manual content.sources row
-- per user, so two hand-added items bound to one item are indistinguishable and
-- each save wipes the other's rows. See
-- docs/superpowers/specs/2026-08-26-facet-origin-scope-design.md §2.
--
-- NULLABLE and NULL-by-default on purpose: NULL means "unscoped, replaced
-- exactly as today", which is what makes the backfill below unable to corrupt
-- anything and what keeps the flag-off path byte-for-byte.
--
-- collection_items is deliberately NOT included — its PK is
-- (collection_id, item_id), so membership is per-item by design and there is no
-- per-coord set to preserve. f_action is not included either: it has no writer
-- anywhere in app/.

ALTER TABLE "content"."item_media"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

ALTER TABLE "content"."offers"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

ALTER TABLE "content"."item_tags"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

ALTER TABLE "content"."item_variants"
    ADD COLUMN IF NOT EXISTS "source_item_id" uuid NULL
    REFERENCES "content"."source_items" ("id") ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "idx_item_media_origin"
    ON "content"."item_media" ("item_id", "source_item_id");
CREATE INDEX IF NOT EXISTS "idx_offers_origin"
    ON "content"."offers" ("item_id", "source_item_id");
CREATE INDEX IF NOT EXISTS "idx_item_tags_origin"
    ON "content"."item_tags" ("item_id", "source_item_id");
CREATE INDEX IF NOT EXISTS "idx_item_variants_origin"
    ON "content"."item_variants" ("item_id", "source_item_id");

-- Backfill ONLY where it is unambiguous: the item has exactly one LIVE source
-- item on that source. Anything ambiguous stays NULL and behaves as it does
-- today, healing on its next write. This is why the backfill cannot corrupt
-- data and needs no down-migration beyond dropping the columns.
UPDATE "content"."item_media" m
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = m."item_id"
  AND si."source_id" = m."source_id"
  AND si."removed_at" IS NULL
  AND m."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = m."item_id"
        AND si2."source_id" = m."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );

UPDATE "content"."offers" o
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = o."item_id"
  AND si."source_id" = o."source_id"
  AND si."removed_at" IS NULL
  AND o."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = o."item_id"
        AND si2."source_id" = o."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );

UPDATE "content"."item_tags" t
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = t."item_id"
  AND si."source_id" = t."source_id"
  AND si."removed_at" IS NULL
  AND t."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = t."item_id"
        AND si2."source_id" = t."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );

UPDATE "content"."item_variants" v
SET "source_item_id" = si."id"
FROM "content"."source_items" si
WHERE si."item_id" = v."item_id"
  AND si."source_id" = v."source_id"
  AND si."removed_at" IS NULL
  AND v."source_item_id" IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM "content"."source_items" si2
      WHERE si2."item_id" = v."item_id"
        AND si2."source_id" = v."source_id"
        AND si2."removed_at" IS NULL
        AND si2."id" <> si."id"
  );
```

- [ ] **Step 2: Add the column to the PG-lane stand-in DDL**

`tests/Postgres/` builds its own tables. Find where `content.item_media` is declared in the PG lane setup (`grep -rn "content.item_media" tests/Pest.php tests/Postgres/`) and add `source_item_id uuid NULL` to `item_media`, `offers`, `item_tags`, `item_variants` in **both** the PG stand-in and the SQLite stand-in (`setupContentTables()` in `tests/Pest.php`). Missing this is the exact "stand-in drifts from the writer" failure `CLAUDE.md` warns about.

- [ ] **Step 3: Apply to dev and verify**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Verify:
```sql
SELECT column_name FROM information_schema.columns
WHERE table_schema='content' AND column_name='source_item_id' ORDER BY table_name;
-- expect exactly 4 rows: item_media, item_tags, item_variants, offers
```

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260826120000_facet_source_item_origin.sql tests/Pest.php tests/Postgres
git commit -m "feat(content): give collection facets a source-item origin"
```

---

## Task 2: Scope the replace to that origin

**Files:**
- Modify: `config/partna.php` (`'content' => [` block, ~line 1207)
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (`writeFacets()`, `replaceCollections()`)
- Test: `tests/Postgres/FacetOriginScopeTest.php`

**Interfaces:**
- Consumes: the `source_item_id` column from Task 1.
- Produces: `config('partna.content.facet_origin_scope')` (bool), `config('partna.content.merge_media_cap')` (int, consumed by Task 5). `writeFacets()` passes `$byItem` as `array<string, list<array{coord: string, projection: array}>>`.

- [ ] **Step 1: Add the config keys**

In `config/partna.php`, inside `'content' => [`:

```php
        // Facet origin scoping (spec 2026-08-26). When ON, replaceCollections()
        // deletes only rows whose source_item_id is one of the coords the write
        // covers (plus NULL rows, which are un-attributed and must keep being
        // replaced as before). When OFF, the delete is byte-for-byte what it
        // always was: item_id IN (batch) AND source_id = ours.
        //
        // This gates the CONNECTOR lane only. The manual source is scoped
        // unconditionally, because that is the lane with the live data-loss bug
        // and it has no comparable traffic to risk.
        'facet_origin_scope' => (bool) env('PARTNA_CONTENT_FACET_ORIGIN_SCOPE', false),

        // Media rows a single merge fold may ADD to an item. Never removes rows
        // the survivor already has — see ProjectionWriter::foldCollections().
        'merge_media_cap' => (int) env('PARTNA_CONTENT_MERGE_MEDIA_CAP', 8),
```

- [ ] **Step 2: Write the failing test**

Create `tests/Postgres/FacetOriginScopeTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Origin scoping is FK + delete-predicate behaviour. SQLite does not enforce
// the cascade this relies on, so this lane is the only honest place for it.
// Helpers are prefixed `fos` — tests/Unit/Content/ResolverTest.php already owns
// idItem()/idKey() in the same global Pest function table.

function fosRelease(string $headline, string $url, array $media = []): array
{
    return [
        'kind' => 'release',
        'headline' => $headline,
        'facets' => ['f_link' => ['url' => $url]],
        'media' => $media,
    ];
}

it('does not wipe another coord rows when one coord is written', function () {
    $userId = createTenant('fos-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $coordA = 'manual:'.Str::uuid();
    $coordB = 'manual:'.Str::uuid();
    $itemA = $writer->writeManualItem($userId, $coordA, fosRelease('A', 'https://e.test/a', [
        ['role' => 'cover', 'url' => 'https://cdn.test/a.jpg'],
    ]));
    $writer->writeManualItem($userId, $coordB, fosRelease('B', 'https://e.test/b', [
        ['role' => 'cover', 'url' => 'https://cdn.test/b.jpg'],
    ]));

    // Bind both coords to ONE item, as a merge does.
    $sourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');
    DB::table('content.source_items')->where('source_id', $sourceId)->where('coord', $coordB)
        ->update(['item_id' => $itemA]);
    DB::table('content.item_media')->where('source_id', $sourceId)
        ->whereIn('source_item_id', function ($q) use ($sourceId, $coordB) {
            $q->select('id')->from('content.source_items')->where('source_id', $sourceId)->where('coord', $coordB);
        })->update(['item_id' => $itemA]);

    expect(DB::table('content.item_media')->where('item_id', $itemA)->count())->toBe(2);

    // Re-save coord A only. Before origin scoping this deleted BOTH rows.
    $writer->writeManualItem($userId, $coordA, fosRelease('A', 'https://e.test/a', [
        ['role' => 'cover', 'url' => 'https://cdn.test/a.jpg'],
    ]));

    expect(DB::table('content.item_media')->where('item_id', $itemA)->count())->toBe(2);
});

it('still replaces un-attributed rows, so the backfill gap cannot orphan them', function () {
    $userId = createTenant('fos-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $coord = 'manual:'.Str::uuid();
    $itemId = $writer->writeManualItem($userId, $coord, fosRelease('A', 'https://e.test/a', [
        ['role' => 'cover', 'url' => 'https://cdn.test/a.jpg'],
    ]));

    // Simulate a pre-backfill row: attributed to nothing.
    DB::table('content.item_media')->where('item_id', $itemId)->update(['source_item_id' => null]);

    $writer->writeManualItem($userId, $coord, fosRelease('A', 'https://e.test/a', [
        ['role' => 'cover', 'url' => 'https://cdn.test/a2.jpg'],
    ]));

    // ONE row, not two: NULL must still mean "replaced as today". If the
    // predicate is ever "simplified" to source_item_id IN (...) alone, this
    // becomes 2 and nothing else in the suite notices.
    expect(DB::table('content.item_media')->where('item_id', $itemId)->count())->toBe(1);
});
```

- [ ] **Step 3: Run it and confirm it fails**

```bash
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_facets DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres/FacetOriginScopeTest.php
```
Expected: test 1 FAILS asserting `1` where `2` is expected (the second coord's row was wiped). Test 2 passes already — it pins behaviour that must not change.

- [ ] **Step 4: Carry the coord through `writeFacets()`**

In `writeFacets()`, replace the `$byItem` build:

```php
        $byItem = [];
        foreach ($projections as $coord => $projection) {
            $itemId = $itemByCoord[$coord] ?? null;
            if ($itemId !== null) {
                // The COORD travels with its projection now: replaceCollections()
                // stamps each row with the source item that contributed it, and a
                // projection stripped of its coord cannot be attributed.
                $byItem[$itemId][] = ['coord' => (string) $coord, 'projection' => $projection];
            }
        }
```

- [ ] **Step 5: Stamp and scope inside `replaceCollections()`**

Resolve coords to source-item ids once for the whole call, immediately after the `$chunk` line:

```php
        // One indexed read for the batch: content.source_items is UNIQUE on
        // (source_id, coord), so this is exact. Rows are stamped with their
        // contributing source item and the delete below is scoped to it.
        $coords = [];
        foreach ($byItem as $entries) {
            foreach ($entries as $entry) {
                $coords[$entry['coord']] = true;
            }
        }
        $originByCoord = $coords === [] ? [] : DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->whereIn('coord', array_keys($coords))
            ->pluck('id', 'coord');
```

Change the per-item fold loop to read the tuple and tag each entry with its origin:

```php
        foreach ($byItem as $itemId => $entries) {
            $media = [];
            $offers = [];
            $tags = [];
            $variants = [];
            $collections = [];
            foreach ($entries as $entry) {
                $origin = $originByCoord[$entry['coord']] ?? null;
                $projection = $entry['projection'];
                $tag = fn (array $rows): array => array_map(
                    fn ($row) => ['row' => $row, 'origin' => $origin],
                    array_values($rows),
                );
                $media = array_merge($media, $tag((array) ($projection['media'] ?? [])));
                $offers = array_merge($offers, $tag((array) ($projection['offers'] ?? [])));
                $tags = array_merge($tags, $tag((array) ($projection['tags'] ?? [])));
                $variants = array_merge($variants, $tag((array) ($projection['variants'] ?? [])));
                // collection_items has no origin column — membership is per-item
                // by design (PK is (collection_id, item_id)).
                $collections = array_merge($collections, array_values((array) ($projection['collections'] ?? [])));
            }
            $mediaByItem[(string) $itemId] = $media;
            $offersByItem[(string) $itemId] = $offers;
            $tagsByItem[(string) $itemId] = $tags;
            $variantsByItem[(string) $itemId] = $variants;
            $collectionsByItem[(string) $itemId] = $collections;
        }
```

Then in each of the four row-building loops, unwrap the tuple and add the column. For `item_media` the loop head becomes `foreach ($entries as $position => $wrapped) { $entry = (array) $wrapped['row']; ... }` and the row array gains `'source_item_id' => $wrapped['origin'],`. Do the same for `$offerRows`, `$tagRows`, `$variantRows`. Leave `$collectionItemRows` untouched.

- [ ] **Step 6: Scope the delete**

Replace the delete inside the chunk transaction:

```php
                foreach ($tables as $table => $rows) {
                    $delete = DB::table("content.{$table}")
                        ->whereIn('item_id', $itemIds)
                        ->where('source_id', $contentSourceId);

                    // collection_items has no origin column, and the manual
                    // source is scoped unconditionally; the connector lane waits
                    // for the flag.
                    if ($table !== 'collection_items' && $originScoped) {
                        $originIds = array_values(array_filter($originByCoord->all()));
                        $delete->where(function ($q) use ($originIds) {
                            // The IS NULL half is LOAD-BEARING, not redundant.
                            // Without it, un-attributed rows are never deleted by
                            // anything and survive forever as orphans nothing
                            // replaces — turning a data-loss bug into a
                            // data-duplication one. NULL must keep meaning
                            // "unscoped, replaced exactly as today".
                            $q->whereNull('source_item_id');
                            if ($originIds !== []) {
                                $q->orWhereIn('source_item_id', $originIds);
                            }
                        });
                    }

                    $delete->delete();
```

`$originScoped` is computed once near the top of `replaceCollections()`:

```php
        // The manual source carries the live bug and no comparable traffic, so it
        // is scoped unconditionally. The connector lane waits for the flag.
        $isManualSource = DB::table('content.sources')->where('id', $contentSourceId)->value('kind') === 'manual';
        $originScoped = $isManualSource || (bool) config('partna.content.facet_origin_scope');
```

- [ ] **Step 7: Run the tests**

Run the command from Step 3. Expected: both PASS.

- [ ] **Step 8: Run the whole PG lane and the fast suite**

```bash
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_facets DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml
COMPOSER_PROCESS_TIMEOUT=0 php artisan test --parallel
```
Expected: PG lane `2 failed, 3 skipped, 244+2 passed` (the 2 pre-existing `LanderFoldAtomicityTest` failures, plus this task's 2 new tests). Fast suite 0 failed.

- [ ] **Step 9: Commit**

```bash
git add config/partna.php app/Ingest/Projection/ProjectionWriter.php tests/Postgres/FacetOriginScopeTest.php
git commit -m "feat(content): scope collection-facet replace to the contributing source item"
```

---

## Task 3: Fold the loser's facets on merge

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (`mergeInto()`)
- Test: `tests/Postgres/MergeFacetFoldTest.php`

**Interfaces:**
- Consumes: `source_item_id` (Task 1), origin-scoped replace (Task 2).
- Produces: `private function foldCollections(string $keptItemId, string $discardedItemId): void`, called from `mergeInto()` inside the `! $hasCuration` branch.

- [ ] **Step 1: Write the failing test**

Create `tests/Postgres/MergeFacetFoldTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Helpers prefixed `mff` — file-unique, per CrossFileTestHelperGuardTest.

function mffRelease(string $headline, string $url, array $media = []): array
{
    return [
        'kind' => 'release',
        'headline' => $headline,
        'facets' => ['f_link' => ['url' => $url]],
        'media' => $media,
    ];
}

/** Two hand-added items ruled the same, returning [userId, keptCoord, otherCoord]. */
function mffRuledPair(array $mediaA, array $mediaB): array
{
    $userId = createTenant('mff-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $coordA = 'manual:'.Str::uuid();
    $coordB = 'manual:'.Str::uuid();
    $writer->writeManualItem($userId, $coordA, mffRelease('A', 'https://e.test/a', $mediaA));
    $writer->writeManualItem($userId, $coordB, mffRelease('B', 'https://e.test/b', $mediaB));

    [$left, $right] = strcmp($coordA, $coordB) <= 0 ? [$coordA, $coordB] : [$coordB, $coordA];
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => $left, 'right_coord' => $right,
        'decided_at' => now(), 'decided_by' => 'owner',
    ]);

    return [$userId, $coordA, $coordB];
}

it('carries the loser media onto the survivor instead of cascading it away', function () {
    [$userId, $coordA] = mffRuledPair(
        [['role' => 'cover', 'url' => 'https://cdn.test/a.jpg']],
        [['role' => 'cover', 'url' => 'https://cdn.test/b.jpg']],
    );

    // Any resolve applies the ruling and merges.
    $itemId = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.item_media')->where('item_id', $itemId)->count())->toBe(2);
});

it('does not duplicate a photo both sides share', function () {
    [$userId, $coordA] = mffRuledPair(
        [['role' => 'cover', 'url' => 'https://cdn.test/same.jpg']],
        [['role' => 'cover', 'url' => 'https://cdn.test/same.jpg']],
    );

    $itemId = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    expect(DB::table('content.item_media')->where('item_id', $itemId)->count())->toBe(1);
});

it('leaves a curated loser its own media, because it is not deleted', function () {
    [$userId, $coordA, $coordB] = mffRuledPair(
        [['role' => 'cover', 'url' => 'https://cdn.test/a.jpg']],
        [['role' => 'cover', 'url' => 'https://cdn.test/b.jpg']],
    );

    $sourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');
    $loserItem = DB::table('content.source_items')->where('source_id', $sourceId)->where('coord', $coordB)->value('item_id');

    // Curation spares the loser from the delete — mergeInto()'s $hasCuration.
    // NOTE: content.manual_overrides has NO user_id column (it is reached
    // through item_id), and `value` is jsonb — a bare string is a 22P02.
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $loserItem,
        'facet' => 'f_text', 'column_name' => 'headline',
        'value' => json_encode('Owner title'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA]);

    // The spared item is still rendered wherever it is pinned, so stripping its
    // media would be a fresh data-loss bug on exactly the items the owner cared
    // about most.
    expect(DB::table('content.item_media')->where('item_id', $loserItem)->count())->toBe(1);
});
```

- [ ] **Step 2: Run and confirm it fails**

```bash
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_facets DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres/MergeFacetFoldTest.php
```
Expected: tests 1 and 2 FAIL (`1` media row, not `2`/`1` — the loser's cascaded away). Test 3 PASSES already and must keep passing.

- [ ] **Step 3: Add `foldCollections()`**

```php
    /**
     * Carry the discarded item's collection facets onto the survivor before the
     * delete cascades them away.
     *
     * Only the MANUAL lane actually loses data here — a connector coord's facets
     * are re-derived by the next reprojection from ingest.record_versions — but
     * the fold is unconditional because it is correct for both and a lane branch
     * inside a merge is a trap. moveLinks()/moveSlugs() already do exactly this
     * for the two tables no projection rewrites.
     *
     * Callers MUST invoke this only where the discarded item is actually
     * deleted. A loser spared by $hasCuration is still rendered wherever it is
     * pinned, and emptying it would be a fresh data-loss bug.
     */
    private function foldCollections(string $keptItemId, string $discardedItemId): void
    {
        // (item_media, dedup key) — the value tuple that decides whether the
        // survivor already carries this contribution.
        $dedupe = [
            'item_media' => ['asset_id', 'role'],
            'offers' => ['channel', 'variant_label', 'amount_minor', 'currency', 'qualifier'],
            'item_tags' => ['tag', 'tag_type'],
            'item_variants' => ['label', 'sku'],
        ];

        foreach ($dedupe as $table => $keys) {
            $existing = DB::table("content.{$table}")->where('item_id', $keptItemId)->get();
            $seen = [];
            foreach ($existing as $row) {
                $seen[$this->foldKey($row, $keys)] = true;
            }

            foreach (DB::table("content.{$table}")->where('item_id', $discardedItemId)->get() as $row) {
                $key = $this->foldKey($row, $keys);
                if (isset($seen[$key])) {
                    // The survivor already says this. Let the cascade take it —
                    // image FILES are deduped by media_assets' UNIQUE
                    // (user_id, fingerprint), so a duplicate row is a second
                    // reference to one asset and would render twice.
                    continue;
                }
                $seen[$key] = true;

                DB::table("content.{$table}")->where('id', $row->id)->update([
                    'item_id' => $keptItemId,
                    // Stamp origin if it has none: an unattributed moved row would
                    // be clobbered by the survivor's next save, which is exactly
                    // the failure this whole change exists to prevent.
                    'source_item_id' => $row->source_item_id ?? DB::table('content.source_items')
                        ->where('item_id', $keptItemId)->whereNull('removed_at')->value('id'),
                ]);
            }
        }
    }

    /** @param list<string> $keys */
    private function foldKey(object $row, array $keys): string
    {
        return implode('|', array_map(fn (string $k) => (string) ($row->{$k} ?? ''), $keys));
    }
```

- [ ] **Step 4: Call it from the delete branch**

In `mergeInto()`, change:

```php
        if (! $hasCuration) {
            DB::table('content.items')->where('id', $discardedItemId)->delete();
        }
```

to:

```php
        if (! $hasCuration) {
            // BEFORE the delete: every facet table FKs content.items(id) ON
            // DELETE CASCADE, so afterwards there is nothing left to carry.
            // Inside this branch only — a curated loser survives and keeps its
            // own facets (spec §5.2).
            $this->foldCollections($keptItemId, $discardedItemId);

            DB::table('content.items')->where('id', $discardedItemId)->delete();
        }
```

- [ ] **Step 5: Run the tests**

Run the Step 2 command. Expected: all 3 PASS.

- [ ] **Step 6: Run the whole PG lane and the fast suite**

Same commands as Task 2 Step 8. Expected: no new failures beyond the 2 pre-existing.

- [ ] **Step 7: Commit**

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/Postgres/MergeFacetFoldTest.php
git commit -m "feat(content): fold the loser collection facets onto the survivor on merge"
```

---

## Task 4: Cap the fold

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (`foldCollections()`)
- Test: `tests/Postgres/MergeFacetFoldTest.php`

**Interfaces:**
- Consumes: `config('partna.content.merge_media_cap')` (Task 2), `foldCollections()` (Task 3).
- Produces: no new symbols.

- [ ] **Step 1: Write the failing test**

Append to `tests/Postgres/MergeFacetFoldTest.php`:

```php
it('caps what a fold adds without ever removing what the survivor already had', function () {
    config(['partna.content.merge_media_cap' => 2]);

    [$userId, $coordA] = mffRuledPair(
        [
            ['role' => 'gallery', 'url' => 'https://cdn.test/a1.jpg'],
            ['role' => 'gallery', 'url' => 'https://cdn.test/a2.jpg'],
            ['role' => 'gallery', 'url' => 'https://cdn.test/a3.jpg'],
        ],
        [
            ['role' => 'gallery', 'url' => 'https://cdn.test/b1.jpg'],
            ['role' => 'gallery', 'url' => 'https://cdn.test/b2.jpg'],
        ],
    );

    $itemId = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    // The survivor was ALREADY over the cap with 3. The cap must drop only
    // incoming rows — trimming the combined set to 2 would destroy live data to
    // enforce a guard that exists to prevent growth.
    $urls = DB::table('content.item_media as im')
        ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
        ->where('im.item_id', $itemId)->pluck('ma.source_url');

    expect($urls)->toHaveCount(3)
        ->and($urls->filter(fn ($u) => str_contains((string) $u, '/b'))->all())->toBe([]);
});
```

- [ ] **Step 2: Run and confirm it fails**

Run the Task 3 Step 2 command. Expected: FAIL with 5 rows, not 3.

- [ ] **Step 3: Apply the cap to media only**

Inside `foldCollections()`, before the `foreach ($dedupe as $table => $keys)` loop:

```php
        $cap = max(1, (int) config('partna.content.merge_media_cap', 8));
        $mediaHeld = DB::table('content.item_media')->where('item_id', $keptItemId)->count();
        $mediaDropped = 0;
```

Inside the row loop, immediately after the `isset($seen[$key])` check:

```php
                if ($table === 'item_media') {
                    if ($mediaHeld >= $cap) {
                        // Only ever drops INCOMING rows. The survivor's own are
                        // never removed: a connector item legitimately carrying
                        // 20 images must not be truncated the moment anything
                        // merges into it.
                        $mediaDropped++;
                        continue;
                    }
                    $mediaHeld++;
                }
```

And after the outer loop:

```php
        if ($mediaDropped > 0) {
            // A silent cap is a defect. user + item so it is attributable.
            Log::warning('content.merge_fold.media_capped', [
                'user_id' => $userId,
                'item_id' => $keptItemId,
                'kept' => $mediaHeld,
                'dropped' => $mediaDropped,
            ]);
        }
```

`foldCollections()` therefore takes `$userId` as its first parameter — update the signature to `foldCollections(string $userId, string $keptItemId, string $discardedItemId)` and the call site in `mergeInto()` to `$this->foldCollections($userId, $keptItemId, $discardedItemId);`. `$userId` is already `mergeInto()`'s first parameter.

- [ ] **Step 4: Run the tests**

Run the Task 3 Step 2 command. Expected: all 4 PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/Postgres/MergeFacetFoldTest.php
git commit -m "feat(content): cap what a merge fold adds, and log when it bites"
```

---

## Task 5: Verify, document, and record the flag decision

**Files:**
- Modify: `docs/superpowers/specs/2026-08-26-facet-origin-scope-design.md` (add a Results section)
- Modify: `.env.example`

**Interfaces:** none.

- [ ] **Step 1: Add the flags to `.env.example`**

```
# Facet origin scoping for the CONNECTOR lane (spec 2026-08-26). The manual
# source is scoped unconditionally; this gates the connector half.
PARTNA_CONTENT_FACET_ORIGIN_SCOPE=false
# Media rows one merge fold may ADD to an item. Never removes existing rows.
PARTNA_CONTENT_MERGE_MEDIA_CAP=8
```

- [ ] **Step 2: Full verification**

```bash
./vendor/bin/pint --test app/ tests/Postgres/FacetOriginScopeTest.php tests/Postgres/MergeFacetFoldTest.php
COMPOSER_PROCESS_TIMEOUT=0 php artisan test --parallel
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_pg_lane_facets DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml
```

Record the exact numbers. Baseline to compare against: fast suite `0 failed`; PG lane `2 failed, 3 skipped, 244 passed` **plus this plan's 6 new tests**.

- [ ] **Step 3: Prove the flag-off path is byte-for-byte**

With `PARTNA_CONTENT_FACET_ORIGIN_SCOPE=false` (the default), confirm no connector-lane test changed behaviour:

```bash
./vendor/bin/pest tests/Feature/Ingest/
```
Expected: same count as before this branch. If any connector test moved, the manual-vs-connector gate in Task 2 Step 6 is wrong — the manual source is identified by `content.sources.kind = 'manual'`, nothing else.

- [ ] **Step 4: Write the Results section into the spec**

Append to the spec: measured numbers, whether the connector flag is recommended for flipping and on what evidence, and anything §9's uncertainties turned out to be wrong about.

- [ ] **Step 5: Commit**

```bash
git add .env.example docs/superpowers/specs/2026-08-26-facet-origin-scope-design.md
git commit -m "docs(spec): facet origin scope results"
```

---

## Self-review notes

- **Spec coverage.** §4 schema → Task 1. §5.1 origin-scoped replace + flag → Task 2. §5.2 fold, curation gate, dedupe, renumber, cap → Tasks 3–4. §5.3 backfill → Task 1 Step 1. §6 test cases 1–9 → Tasks 2–4 (case 5, connector flag off → Task 5 Step 3). §7 constraints → Global Constraints.
- **Known deviation from the spec, flagged deliberately:** §5.2 asks for `position` renumbering on moved media. It is **not** implemented in Task 3, because `item_media`'s index `(item_id, role, position)` is not unique, colliding positions are legal, and no test in §6 can observe the difference. Renumbering would be untested code. If render order turns out to matter, it is a follow-up with its own test — not a line added here on faith.
