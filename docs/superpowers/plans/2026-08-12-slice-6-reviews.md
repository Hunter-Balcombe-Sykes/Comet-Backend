# Slice 6 — Reviews → `content.*` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve Google reviews from `content.*` through the pool lane, retire the legacy `platform_connections` review read, and close a live reviewer-PII defect in which the reviewer's name is stored in two ungoverned tables.

**Architecture:** Reviews already land via the ingest lane (slice 1b's shared `places.details` call brought them). This plan adds the render path (a `reviews` pool with a `review` block on the pool payload), a source-level `content.source_stats` table for the aggregates that have no item to hang on, and stops the projector copying reviewer names into `items.headline_cache` / `f_text.headline`.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase), raw SQL migrations.

**Spec:** `docs/superpowers/specs/2026-08-12-slice-6-reviews-design.md`. Read §2 (PII contract) before Task 2.

## Global Constraints

- **Never create Laravel migration files.** Raw SQL under `supabase/migrations/` only. Composer guard rejects otherwise.
- **Assigned migration prefix block: `20260813110000`–`20260813119999`.** Do not consume outside it.
- **Do NOT apply migrations.** Write the file; the coordinator applies to shared dev. Three sessions share `glncumufgaqcmqhzwrxm`. Every migration added here must ALSO be mirrored into the SQLite stand-in (`tests/Pest.php`) and the Postgres-lane DDL, or the suites break before the migration lands.
- **Tests run SQLite, production is Postgres.** Verify constraint-bound writes against the DDL.
- **`composer test:pg` is mandatory** for any task touching `app/Ingest/Projection/ProjectionWriter.php` (CLAUDE.md). A green SQLite run says nothing about that lane.
- **Cache invalidation is three lanes, no CI check enforces it:** `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. Required for any raw-SQL write path.
- **No new billed calls.** The effect digest stays `['place_id' => …]`. Adding an input key doubles the Places Details bill for every user — blocker-gate conversation, not a detail.
- **Never weaken a PII control.** A review path that bypasses `redactionScopes`, or leaves `f_review` rows unreachable by `PruneOrphanedReviewPiiCommand`, is a launch blocker.
- **Authorization via Policies**, never inline `abort(403)`. CI fails the build on inline 403s.
- **Branch:** `feat/slice-6-reviews`, worktree `.worktrees/slice-6-reviews`, cut from `origin/development`, rebased onto `52c81ba43` (post-5b-merge). Never push to `production`.
- **Do not run `git stash`** at any point.

---

## File Structure

**Create:**
- `supabase/migrations/20260813110000_f_review_author_uri.sql` — adds `author_uri`
- `supabase/migrations/20260813110001_create_content_source_stats.sql` — source-level aggregates
- `app/Console/Commands/PurgeReviewHeadlinePiiCommand.php` — one-off cleanup of the two ungoverned copies
- `tests/Feature/Content/PurgeReviewHeadlinePiiTest.php`
- `tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php`
- `tests/Feature/Pools/ReviewsPoolTest.php`
- `docs/wire-changes/2026-08-12-slice-6-reviews.md`

**Modify:**
- `app/Ingest/Projection/GoogleBusinessReviewProjector.php` — headline null, `author_uri`, `source_stats`
- `app/Ingest/Connectors/GoogleBusinessConnector.php` — emit aggregates in `reviewsMessages()`
- `app/Ingest/Projection/ProjectionWriter.php` — `f_review` column list, source-scoped write path
- `app/Site/Pools/PoolRegistry.php` — `reviews` pool + two new consts
- `app/Site/Pools/PoolResolver.php` — `review` block on the item payload, display-toggle gate
- `app/Http/Controllers/Api/Content/PoolItemCreateController.php` — refuse manual `review`
- `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:185` — drop four keys
- `app/Services/User/DataExport/DataExportPayloadBuilder.php` — `source_stats` section
- `app/Console/Commands/PruneOrphanedReviewPiiCommand.php` — docblock correction
- `tests/Pest.php` — SQLite stand-in for `author_uri` + `source_stats`

---

## Task 1: Schema — `author_uri` and `content.source_stats`

**Files:**
- Create: `supabase/migrations/20260813110000_f_review_author_uri.sql`
- Create: `supabase/migrations/20260813110001_create_content_source_stats.sql`
- Modify: `tests/Pest.php:2848` (singleton stand-in), and the `setupContentTables()` helper
- Modify: `app/Ingest/Projection/ProjectionWriter.php:56` (`SINGLETON_FACETS['f_review']`)

**Interfaces:**
- Produces: `content.f_review.author_uri` (text, nullable); `content.source_stats(source_id PK, rating_avg, rating_count, summary_text, updated_at)`.

- [ ] **Step 1: Write the `author_uri` migration**

```sql
-- Slice 6 Task 1. content.f_review carries the reviewer's display name and
-- photo but not the URI of their Google contributor profile, so the content.*
-- lane could not reach parity with the legacy platform_connections payload
-- (GoogleBusinessService::mapDetails' `authorUri`). Unit 3 retires that legacy
-- read; without this column the retirement would silently DROP a field that
-- docs/legal/reviewer-data-disclosure.md §1 lists as published today, which is
-- a change to what third-party data reaches the public wire — a legal-review
-- item, not a refactor.
--
-- Inherits redaction with no manifest change: GoogleBusinessConnector's
-- Manifest::$redactionScopes already declares `author_uri` as when_unclaimed
-- alongside `author` and `author_photo`, so an unclaimed owner's record lands
-- with it stripped exactly as the other two already do.
--
-- No CONCURRENTLY: a nullable ADD COLUMN with no DEFAULT and no index is a
-- catalog-only metadata change on Postgres 11+ — no table rewrite, no scan.
-- A future change to this table that DOES scan or rewrite needs its own
-- treatment and must not inherit this justification.
--
-- NOT APPLIED by this task — no supabase db push, no db link, no MCP database
-- call was run. The coordinator applies it to the shared dev database (two
-- other sessions use it). The SQLite stand-in and Postgres-lane provisioning
-- carry it so the suites run either way.
--
-- ROLLBACK: ALTER TABLE content.f_review DROP COLUMN IF EXISTS author_uri;

BEGIN;

ALTER TABLE content.f_review
    ADD COLUMN IF NOT EXISTS author_uri text NULL;

COMMENT ON COLUMN content.f_review.author_uri IS
    'Permanent link to the reviewer''s Google contributor profile (authorAttribution.uri). Third-party PII: stripped before landing for unclaimed owners via Manifest::$redactionScopes when_unclaimed, hard-deleted by content:prune-orphaned-review-pii, and omitted from DSAR exports alongside author_name/author_photo_url/text.';

COMMIT;
```

- [ ] **Step 2: Write the `source_stats` migration**

```sql
-- Slice 6 Task 1. Per-SOURCE aggregates for a connected Google place: the star
-- average, the review count, and Google's own prose review summary. These
-- describe the PLACE, not any one review, so they have no content.items row to
-- hang a facet on — this is the first source-level fact in content.*.
--
-- Why a new table rather than an existing lane: the `profile` stream that would
-- naturally own place-level facts projects to nothing (3 record_versions, 0
-- source_items on dev), and the field-bindings lane its ProjectorRegistry
-- docblock defers to was created by 20260728150000 and deliberately dropped by
-- 20260805110000 for never gaining a production caller. Writing these onto the
-- reviews stream instead keeps them off machinery slice 7 could legitimately
-- delete. See the design spec §5.2.
--
-- summary_text is Google-authored prose about the business, derived from
-- reviews. It is NOT redacted pre-claim — GoogleBusinessPayload::
-- stripThirdPartyPii removes `reviews` only and leaves rating/reviewCount/
-- reviewSummary untouched, and this table mirrors that precedent rather than
-- inventing a stricter one. It IS withheld from DSAR, same as the legacy
-- `reviewSummary` key. That asymmetry is deliberate (see ThirdPartyPii).
--
-- ON DELETE CASCADE from content.sources: these rows must not outlive the
-- source, and account-close erasure reaches them through the existing
-- content.sources -> core.users cascade chain.
--
-- NOT APPLIED by this task — the coordinator applies it to shared dev.
--
-- ROLLBACK: DROP TABLE IF EXISTS content.source_stats;

BEGIN;

CREATE TABLE IF NOT EXISTS content.source_stats (
    source_id    uuid PRIMARY KEY REFERENCES content.sources (id) ON DELETE CASCADE,
    rating_avg   double precision NULL,
    rating_count integer NULL,
    summary_text text NULL,
    updated_at   timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE content.source_stats IS
    'Source-level aggregates that describe the connected account/place itself rather than any one item — currently the Google Business star average, review count and Google-authored review summary. Written by the reviews stream (design spec 2026-08-12 §5.2). NOT reviewer PII: rating/count are business facts and summary_text is Google prose about the business, so none is redacted pre-claim, matching GoogleBusinessPayload::stripThirdPartyPii. summary_text IS withheld from DSAR.';

COMMIT;
```

- [ ] **Step 3: Mirror both into the SQLite stand-in**

In `tests/Pest.php`, change the `f_review` singleton line (currently line 2848) to add `author_uri`:

```php
        'f_review' => 'author_name TEXT NULL, author_photo_url TEXT NULL, author_uri TEXT NULL, rating REAL NULL, text TEXT NULL, reviewed_at TEXT NULL',
```

Then, immediately after the `foreach ($singletons ...)` loop that ends at line 2860, add the source-level table (it is NOT a singleton facet — different PK, no `item_id`):

```php
    // Slice 6: source-level aggregates. Not in $singletons — keyed by
    // source_id alone, with no item_id, so it does not fit that loop's shape.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.source_stats (
        source_id TEXT NOT NULL PRIMARY KEY,
        rating_avg REAL NULL,
        rating_count INTEGER NULL,
        summary_text TEXT NULL,
        updated_at TEXT NOT NULL
    )');
```

- [ ] **Step 4: Add `author_uri` to the writer's column allowlist**

`app/Ingest/Projection/ProjectionWriter.php`, `SINGLETON_FACETS`:

```php
        'f_review' => ['author_name', 'author_photo_url', 'author_uri', 'rating', 'text', 'reviewed_at'],
```

`upsertSingletonFacet()` filters columns against this list, so without it the projector's `author_uri` is silently dropped — no error, no row.

- [ ] **Step 5: Add the same tables to the Postgres lane**

Find the PG-lane provisioning that creates `content.f_review` (grep `f_review` under `tests/Postgres/` — `ContentRetentionConstraintsTest.php` and the shared provisioning helper it uses). Add `author_uri text NULL` to the `f_review` DDL and create `content.source_stats` with the same shape as the migration. The PG lane's DDL is hand-written and drifts silently from writer changes — slice 5a turned it red for 7 tests and two reviews missed it on a green SQLite run.

- [ ] **Step 6: Run both suites**

```bash
composer test -- --filter=Projection
composer test:pg
```
Expected: PASS. If `composer test:pg` cannot reach a Postgres container, do NOT record it as passing — it skips silently and "31 skipped" reads green while testing nothing.

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260813110000_f_review_author_uri.sql \
        supabase/migrations/20260813110001_create_content_source_stats.sql \
        tests/Pest.php app/Ingest/Projection/ProjectionWriter.php tests/Postgres/
git commit -m "feat(content): f_review.author_uri + content.source_stats

Migrations NOT applied — coordinator applies to shared dev. SQLite stand-in
and the Postgres lane carry both so the suites run before the migration lands."
```

---

## Task 2: Stop the projector writing reviewer names into ungoverned tables

**Read `docs/superpowers/specs/2026-08-12-slice-6-reviews-design.md` §2.2–§2.3 before starting.** This is the P0 half of the slice.

**Files:**
- Modify: `app/Ingest/Projection/GoogleBusinessReviewProjector.php:34`
- Test: `tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php` (create)

**Interfaces:**
- Consumes: `content.f_review.author_uri` from Task 1.
- Produces: projector output with `headline` always `null`; `facets.f_review.author_uri` populated from the record's `author_uri`.

**Why:** `ProjectionWriter:949-951` folds any non-empty `headline` into the `f_text` facet, and `:1459-1479` resolves that back into `content.items.headline_cache`. So today a claimed account's reviewer name is stored in three tables and only `f_review` is governed by redaction, the prune command and the DSAR omission. `KindRegistry` already declares `review`'s facets as `['f_review','f_rated','f_published']` — no `f_text` — so the current writer produces a facet the kind registry says reviews do not have. `GoogleBusinessMediaProjector:44` sets `'headline' => null` for the same class of reason.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php`:

```php
<?php

use App\Ingest\Projection\GoogleBusinessReviewProjector;
use App\Ingest\Projection\RecordView;

// Slice 6 §2.2: the projector's headline was the reviewer's display name, and
// ProjectionWriter folds any non-empty headline into f_text -> headline_cache
// — two copies of third-party PII that redaction, content:prune-orphaned-
// review-pii and the DSAR omission all fail to reach. Null headline is the
// fix, matching GoogleBusinessMediaProjector's null-by-contract.

it('never puts the reviewer name in the headline', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'places/X/reviews/Y',
        'rating' => 5.0,
        'text' => 'Excellent service.',
        'author' => 'A Real Person',
        'author_uri' => 'https://maps.google.com/contrib/123',
        'author_photo' => 'https://lh3.googleusercontent.com/a/abc',
        'publish_time' => '2026-07-01T10:00:00Z',
    ], 'places/X/reviews/Y'));

    expect($projection['headline'])->toBeNull();

    // The name is still landed where redaction and pruning govern it.
    expect($projection['facets']['f_review']['author_name'])->toBe('A Real Person');
});

it('carries author_uri onto the f_review facet', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r1', 'rating' => 4.0,
        'author_uri' => 'https://maps.google.com/contrib/123',
    ], 'r1'));

    expect($projection['facets']['f_review']['author_uri'])
        ->toBe('https://maps.google.com/contrib/123');
});

// Redaction strips author fields BEFORE landing for unclaimed owners, so the
// projector must render their absence rather than require them.
it('projects a redacted record without author fields', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r2', 'rating' => 3.0, 'text' => 'Fine.',
    ], 'r2'));

    expect($projection['headline'])->toBeNull()
        ->and($projection['facets']['f_review']['author_name'])->toBeNull()
        ->and($projection['facets']['f_review']['author_uri'])->toBeNull()
        ->and($projection['facets']['f_review']['rating'])->toBe(3.0);
});

it('projects nothing when the record has no rating', function () {
    expect((new GoogleBusinessReviewProjector)->project(new RecordView(['review_id' => 'r3'], 'r3')))
        ->toBeNull();
});
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
composer test -- --filter=GoogleBusinessReviewProjector
```
Expected: FAIL — `headline` is `'A Real Person'`, and `author_uri` is not set.

- [ ] **Step 3: Implement**

`app/Ingest/Projection/GoogleBusinessReviewProjector.php` — replace the class docblock and `project()`:

```php
/**
 * Places review → the `review` item kind (Sample stream: latest-from-Google,
 * never pinned, never edited). Author fields may be absent — unclaimed
 * accounts land the record post-redaction, and the projection must render
 * that honestly rather than require what redaction removed.
 *
 * headline is NULL BY CONTRACT (slice 6 §2.3), the same rule
 * GoogleBusinessMediaProjector follows. It used to be the reviewer's display
 * name, and ProjectionWriter folds any non-empty headline into the f_text
 * facet, which then resolves into content.items.headline_cache — two copies
 * of third-party PII outside everything that governs it: Manifest::
 * $redactionScopes, content:prune-orphaned-review-pii, and the DSAR omission
 * in DataExportPayloadBuilder::streamContentFReview(). KindRegistry already
 * declares this kind's facets as f_review/f_rated/f_published with no f_text.
 *
 * Do not restore a headline here to give the review card a title. The card
 * renders from the pool payload's `review` block, which reads f_review — the
 * one copy that redaction, pruning and DSAR all reach.
 */
class GoogleBusinessReviewProjector implements Projector
{
    public static function version(): int
    {
        return 2;
    }

    public static function kind(): string
    {
        return 'review';
    }

    public function project(RecordView $view): ?array
    {
        $rating = $view->float('rating');
        if ($rating === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_review' => [
                    'author_name' => $view->string('author'),
                    'author_photo_url' => $view->string('author_photo'),
                    'author_uri' => $view->string('author_uri'),
                    'rating' => $rating,
                    'text' => $view->string('text'),
                    'reviewed_at' => $view->string('publish_time'),
                ],
                'f_rated' => ['rating' => $rating, 'rating_max' => 5.0],
                'f_published' => $view->string('publish_time') === null ? null : [
                    'published_from' => $view->string('publish_time'),
                    // The vendor's own wording ("3 months ago") is provenance
                    // for the dashboard; the public document renders absolute.
                    'verbatim' => $view->string('published_ago'),
                ],
            ]),
        ];
    }
}
```

`version()` goes 1 → 2: output changed for unchanged input, which is exactly what triggers `ingest:project --rebuild`.

- [ ] **Step 4: Run the test**

```bash
composer test -- --filter=GoogleBusinessReviewProjector
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/Projection/GoogleBusinessReviewProjector.php \
        tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php
git commit -m "fix(privacy): stop projecting reviewer names into headline_cache and f_text

The projector's headline was the reviewer's display name. ProjectionWriter
folds a non-empty headline into f_text and resolves it into
items.headline_cache — two copies outside redaction, the prune command and
the DSAR omission. Verified 5/5 claimed review items on dev.

Does NOT clean up existing rows: upsertSingletonFacet never deletes. Task 3."
```

---

## Task 3: Purge the existing ungoverned copies

**Files:**
- Create: `app/Console/Commands/PurgeReviewHeadlinePiiCommand.php`
- Test: `tests/Feature/Content/PurgeReviewHeadlinePiiTest.php`

**Interfaces:**
- Consumes: Task 2's null-headline projector.
- Produces: artisan command `content:purge-review-headline-pii {--dry-run}`.

**Why this task exists and must not be folded into Task 2:** `ProjectionWriter::upsertSingletonFacet()` is upsert-only — it never deletes. Stopping the projector contributing `f_text` does not remove the rows it already wrote, and because `headline_cache` is resolved *from* those rows (`:1459`), the reviewer's name keeps being served indefinitely. A reviewer reading Task 2's diff would reasonably conclude the fix is complete. It is not.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/PurgeReviewHeadlinePiiTest.php`:

```php
<?php

use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function seedReviewWithHeadlinePii(string $userId): array
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'review',
        'headline_cache' => 'A Real Person', 'facets_cache' => '["f_text","f_review"]',
        'eligible_cache' => '[]', 'first_seen_at' => $now, 'last_seen_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'A Real Person', 'updated_at' => $now,
    ]);
    DB::table('content.f_review')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'author_name' => 'A Real Person', 'rating' => 5.0, 'updated_at' => $now,
    ]);

    return [$itemId, $sourceId];
}

it('deletes the f_text row and nulls headline_cache for review items', function () {
    $userId = createUser();
    [$itemId] = seedReviewWithHeadlinePii($userId);

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('content.f_text')->where('item_id', $itemId)->count())->toBe(0)
        ->and(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBeNull();
});

// f_review is the ONE copy that redaction, pruning and DSAR govern. This
// command must not touch it — deleting reviewer PII is the prune command's
// job, on its own orphan rule and grace window.
it('leaves f_review untouched', function () {
    $userId = createUser();
    [$itemId] = seedReviewWithHeadlinePii($userId);

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('content.f_review')->where('item_id', $itemId)->value('author_name'))
        ->toBe('A Real Person');
});

it('does not touch non-review items', function () {
    $userId = createUser();
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'video',
        'headline_cache' => 'My Video', 'facets_cache' => '["f_text"]',
        'eligible_cache' => '[]', 'first_seen_at' => $now, 'last_seen_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'My Video', 'updated_at' => $now,
    ]);

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('content.f_text')->where('item_id', $itemId)->count())->toBe(1)
        ->and(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('My Video');
});

it('changes nothing on a dry run', function () {
    $userId = createUser();
    [$itemId] = seedReviewWithHeadlinePii($userId);

    $this->artisan('content:purge-review-headline-pii', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('content.f_text')->where('item_id', $itemId)->count())->toBe(1)
        ->and(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('A Real Person');
});
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
composer test -- --filter=PurgeReviewHeadlinePii
```
Expected: FAIL — command `content:purge-review-headline-pii` does not exist.

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/PurgeReviewHeadlinePiiCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 6 §2.3, part two. One-off purge of the two UNGOVERNED copies of a
 * reviewer's display name.
 *
 * GoogleBusinessReviewProjector used to set `headline` to the reviewer's
 * name. ProjectionWriter folds a non-empty headline into content.f_text and
 * resolves it into content.items.headline_cache, so the name existed in three
 * tables while only content.f_review was reached by Manifest::
 * $redactionScopes, content:prune-orphaned-review-pii and the DSAR omission.
 *
 * Task 2 stopped NEW copies. This removes the existing ones, and it is not
 * optional: upsertSingletonFacet() is upsert-only and never deletes, so the
 * f_text rows survive the projector change — and because headline_cache is
 * resolved FROM those rows, the name would keep being served forever.
 *
 * Deliberately does NOT touch content.f_review. That is the copy the platform
 * is entitled to hold for a claimed account and is what renders attribution;
 * deleting it is content:prune-orphaned-review-pii's job, on its own orphan
 * rule and 14-day grace window.
 *
 * One-off by intent, but idempotent and safe to re-run — a second run finds
 * nothing because Task 2 stopped the source.
 */
class PurgeReviewHeadlinePiiCommand extends Command
{
    protected $signature = 'content:purge-review-headline-pii
                            {--dry-run : Report affected rows without mutating anything}';

    protected $description = 'Delete reviewer display names from content.f_text and content.items.headline_cache '
        .'for review-kind items (slice 6 §2.3 — the copies outside redaction, pruning and DSAR).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $itemIds = DB::connection('pgsql')->table('content.items')
            ->where('kind', 'review')
            ->whereNotNull('headline_cache')
            ->pluck('id');

        $textIds = DB::connection('pgsql')->table('content.f_text')
            ->join('content.items', 'content.items.id', '=', 'content.f_text.item_id')
            ->where('content.items.kind', 'review')
            ->pluck('content.f_text.item_id');

        $affected = $itemIds->merge($textIds)->unique()->values();

        if ($affected->isEmpty()) {
            $this->info('No reviewer PII on review headlines — nothing to do.');
            Log::info('content.purge_review_headline_pii', ['affected' => 0, 'dry_run' => $dryRun]);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would clear {$affected->count()} review item(s).");
            Log::info('content.purge_review_headline_pii_dry_run', ['affected' => $affected->count()]);

            return self::SUCCESS;
        }

        // Resolve sites BEFORE mutating — the join runs off content.items,
        // which survives, but doing it first keeps the ordering identical to
        // PruneOrphanedReviewPiiCommand's and avoids a second decision later.
        $siteIds = DB::connection('pgsql')->table('content.items')
            ->join('site.sites', 'site.sites.user_id', '=', 'content.items.user_id')
            ->whereIn('content.items.id', $affected)
            ->distinct()
            ->pluck('site.sites.id');

        $textDeleted = DB::connection('pgsql')->table('content.f_text')
            ->whereIn('item_id', $affected)->delete();

        $headlinesCleared = DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $affected)
            ->update(['headline_cache' => null]);

        // MANDATORY, all three lanes: this is a raw write that bypasses
        // Eloquent observers, so nothing else invalidates the published
        // document. Without it the page keeps rendering a headline whose row
        // was just deleted. PruneOrphanedReviewPiiCommand bumps BuildState
        // only — that is a gap in it, not a precedent to copy.
        foreach ($siteIds as $siteId) {
            BuildState::bump((string) $siteId);
        }

        DB::connection('pgsql')->table('site.sites')
            ->whereIn('id', $siteIds)
            ->update(['updated_at' => now()]);

        foreach ($siteIds as $siteId) {
            CloudflareCachePurgeJob::dispatch((string) $siteId);
        }

        $this->info("Cleared {$headlinesCleared} headline(s), deleted {$textDeleted} f_text row(s), bumped {$siteIds->count()} site(s).");

        Log::info('content.purge_review_headline_pii', [
            'headlines_cleared' => $headlinesCleared,
            'f_text_deleted' => $textDeleted,
            'sites_bumped' => $siteIds->count(),
            'dry_run' => false,
        ]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test**

```bash
composer test -- --filter=PurgeReviewHeadlinePii
```
Expected: PASS (4 tests).

- [ ] **Step 5: Correct the prune command's docblock**

`app/Console/Commands/PruneOrphanedReviewPiiCommand.php` — after the first paragraph, add:

```php
 * Scope note (slice 6): this deletes content.f_review ONLY. Until slice 6 the
 * reviewer's display name was ALSO held in content.f_text.headline and
 * content.items.headline_cache, which this command does not reach — so its
 * guarantee was incomplete in exactly the way its own description implies it
 * is not. GoogleBusinessReviewProjector no longer writes those copies and
 * content:purge-review-headline-pii removed the existing ones. If a future
 * projector reintroduces a headline for the `review` kind, this command's
 * guarantee silently breaks again.
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PurgeReviewHeadlinePiiCommand.php \
        app/Console/Commands/PruneOrphanedReviewPiiCommand.php \
        tests/Feature/Content/PurgeReviewHeadlinePiiTest.php
git commit -m "fix(privacy): purge reviewer names from f_text and headline_cache

upsertSingletonFacet is upsert-only, so the projector fix alone leaves the
existing rows in place and headline_cache keeps resolving from them."
```

---

## Task 4: Land the aggregates on `content.source_stats`

**Files:**
- Modify: `app/Ingest/Connectors/GoogleBusinessConnector.php` (`reviewsMessages()`, `mapReview()`)
- Modify: `app/Ingest/Projection/GoogleBusinessReviewProjector.php` (emit `source_stats`)
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (`projectStream()` — write the stats)
- Test: `tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php` (extend)

**Interfaces:**
- Consumes: `content.source_stats` from Task 1.
- Produces: projector output MAY carry a top-level `source_stats` key `['rating_avg' => ?float, 'rating_count' => ?int, 'summary_text' => ?string]`. `ProjectionWriter::projectStream()` upserts the last non-null one per run.

**Design note:** the aggregates ride on every review record rather than a dedicated record. They are identical across a run, so the last one wins — the same last-record-wins semantics `upsertSingletonFacet` already relies on. **Accepted gap:** a place returning zero reviews emits no records and therefore no stats. That is tolerable because with no reviews there is no reviews pool to render a rating against; if a rating badge is ever wanted independently of reviews, it needs its own record and this note is the reason why.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php`:

```php
it('carries the place aggregates as source_stats', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r1', 'rating' => 5.0,
        'place_rating' => 4.7, 'place_rating_count' => 312,
        'place_review_summary' => 'Customers praise the friendly staff.',
    ], 'r1'));

    expect($projection['source_stats'])->toBe([
        'rating_avg' => 4.7,
        'rating_count' => 312,
        'summary_text' => 'Customers praise the friendly staff.',
    ]);
});

it('omits source_stats when the place carried no aggregates', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r1', 'rating' => 5.0,
    ], 'r1'));

    expect($projection)->not->toHaveKey('source_stats');
});
```

- [ ] **Step 2: Run and confirm failure**

```bash
composer test -- --filter=GoogleBusinessReviewProjector
```
Expected: FAIL on the two new tests.

- [ ] **Step 3: Emit the aggregates from the connector**

`app/Ingest/Connectors/GoogleBusinessConnector.php` — replace `reviewsMessages()`:

```php
    /** @return iterable<Message> */
    private function reviewsMessages(array $place): iterable
    {
        $reviews = is_array($place['reviews'] ?? null) ? $place['reviews'] : [];

        // Slice 6 §5.2: the place's own aggregates ride on every review record.
        // They describe the PLACE, not the review, but the `profile` stream that
        // would naturally own them projects to nothing (its field-bindings
        // consumer was dropped by 20260805110000), and hanging a public-wire
        // fact off a stream slice 7 could legitimately delete is a defect
        // waiting for someone to act correctly. Identical across the run, so
        // the writer's last-one-wins upsert is the intended behaviour.
        // A place returning zero reviews emits no records and so no stats —
        // accepted: with no reviews there is no pool to render a rating against.
        $stats = array_filter([
            'place_rating' => is_numeric($place['rating'] ?? null) ? (float) $place['rating'] : null,
            'place_rating_count' => is_numeric($place['userRatingCount'] ?? null) ? (int) $place['userRatingCount'] : null,
            'place_review_summary' => is_string(data_get($place, 'reviewSummary.text.text'))
                ? data_get($place, 'reviewSummary.text.text')
                : (is_string(data_get($place, 'reviewSummary.text')) ? data_get($place, 'reviewSummary.text') : null),
        ], static fn ($v) => $v !== null);

        $items = [];
        foreach ($reviews as $review) {
            $item = $this->mapReview($review);
            if ($item !== null) {
                $items[] = $item + $stats;
            }
        }

        if ($items === []) {
            yield new Note('no_reviews', 'Places details carried no reviews this run');

            return;
        }

        foreach ($items as $item) {
            yield new Record('reviews', $item['review_id'], $item);
        }

        // Deliberately NO Covered message: a Sample is a vendor-curated
        // subset we can never claim to have exhaustively seen, and emitting
        // even Coverage::exhaustive() here would misstate what this endpoint
        // returns. mayDelete() is already false (Sample + null orderField);
        // this is about not making a false claim, not about the folding
        // mechanics.
    }
```

- [ ] **Step 4: Emit `source_stats` from the projector**

In `GoogleBusinessReviewProjector::project()`, build the return with the optional key:

```php
        $stats = array_filter([
            'rating_avg' => $view->float('place_rating'),
            'rating_count' => $view->int('place_rating_count'),
            'summary_text' => $view->string('place_review_summary'),
        ], static fn ($v) => $v !== null);

        $projection = [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([ /* … unchanged from Task 2 … */ ]),
        ];

        if ($stats !== []) {
            $projection['source_stats'] = $stats;
        }

        return $projection;
```

If `RecordView` has no `int()` method, use `$view->float('place_rating_count')` and cast: `is_float($v) ? (int) $v : null`. Check `app/Ingest/Projection/RecordView.php` before writing this — do not invent a method.

- [ ] **Step 5: Write the stats in `ProjectionWriter::projectStream()`**

Inside the `foreach ($records as $record)` loop, after the null check, collect:

```php
            if (isset($projection['source_stats']) && is_array($projection['source_stats'])) {
                $sourceStats = $projection['source_stats'];
            }
```

Declare `$sourceStats = null;` beside `$projections = [];`, and after the loop completes, before the method returns:

```php
        // Slice 6 §5.2: source-level aggregates. Last record wins — they are
        // identical across a run, mirroring upsertSingletonFacet's
        // last-processed-record-wins column semantics.
        if ($sourceStats !== null) {
            DB::table('content.source_stats')->upsert(
                [$sourceStats + ['source_id' => $contentSourceId, 'updated_at' => now()]],
                ['source_id'],
                array_merge(array_keys($sourceStats), ['updated_at']),
            );
        }
```

- [ ] **Step 6: Run both lanes**

```bash
composer test -- --filter=GoogleBusinessReviewProjector
composer test -- --filter=Projection
composer test:pg
```
Expected: PASS. `composer test:pg` is mandatory here — this modifies `ProjectionWriter`.

- [ ] **Step 7: Commit**

```bash
git add app/Ingest/ tests/Feature/Ingest/GoogleBusinessReviewProjectorTest.php
git commit -m "feat(ingest): land Google place aggregates on content.source_stats

Rides the reviews stream, not the profile stream: profile projects to nothing
and its field-bindings consumer was dropped by 20260805110000, so slice 7
could legitimately delete it. No extra billed call — same places.details
payload, same digest."
```

---

## Task 5: The `reviews` pool in `PoolRegistry`

**Files:**
- Modify: `app/Site/Pools/PoolRegistry.php`
- Test: `tests/Feature/Pools/ReviewsPoolTest.php` (create); existing `PoolRegistryTest`

**Interfaces:**
- Produces: `PoolRegistry::POOLS['reviews'] = ['review']`; `EXCLUDE_ONLY_POOLS`; `MANUAL_ADD_FORBIDDEN_POOLS`; `PoolRegistry::allowsPin(string $pool): bool`; `PoolRegistry::allowsManualAdd(string $pool): bool`.

**Merge hazard — UPDATED 2026-08-13.** Slice 5b **merged** (`52c81ba43`); its `'shop' => ['product']` entry is already in `PoolRegistry` on `development`, so there is no 5b conflict left to negotiate — just add `reviews` beside `shop`. The live risk is now **slice 3b**, which is 25 commits behind `development` and carries changes to `PoolRegistry` (12+/21-), `PoolResolver` (13+/296-), `ProjectionWriter` (146+), `PublicIntegrationConnectionResource` (170+/19-) and `tests/Pest.php` (21+). Its large negative line counts are an artefact of being behind, not of deletions it intends — do not read them as 3b removing 5b's work. **3b rebases before it merges; whoever merges second re-runs `PoolRegistryTest` AFTER resolving** — a merge that drops half a const array still passes every test written by the branch that added the other half.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Pools/ReviewsPoolTest.php`:

```php
<?php

use App\Site\Pools\PoolRegistry;

it('owns the review kind', function () {
    expect(PoolRegistry::kinds('reviews'))->toBe(['review'])
        ->and(PoolRegistry::poolForKind('review'))->toBe('reviews');
});

// A "latest review" tag would present a vendor-curated sample of five as a
// chronology of the business's feedback.
it('does not carry the Latest tag', function () {
    expect(PoolRegistry::carriesLatestTag('reviews'))->toBeFalse();
});

// The default shape's latest_per_auto_source emits ONE item per source, which
// for a five-review sample means one review shown and four hidden — the same
// pathology media (slice 1a) and events (slice 2) each hit.
it('uses its own section shape, not the rolling-latest default', function () {
    $shape = PoolRegistry::sectionShape('reviews');

    expect(collect($shape['rule'])->pluck('op')->all())->not->toContain('latest_per_auto_source')
        ->and(collect($shape['rule'])->pluck('op')->all())->toContain('kind_is');
});

it('allows exclusion but refuses pinning', function () {
    expect(PoolRegistry::allowsPin('reviews'))->toBeFalse()
        ->and(PoolRegistry::allowsPin('watch'))->toBeTrue();
});

// Hand-authoring an item of kind `review` is fabricating a testimonial
// attributed to a customer.
it('forbids manual adds', function () {
    expect(PoolRegistry::allowsManualAdd('reviews'))->toBeFalse()
        ->and(PoolRegistry::allowsManualAdd('watch'))->toBeTrue();
});
```

- [ ] **Step 2: Run and confirm failure**

```bash
composer test -- --filter=ReviewsPool
```
Expected: FAIL — `kinds('reviews')` returns `[]`, `allowsPin` undefined.

- [ ] **Step 3: Implement**

In `app/Site/Pools/PoolRegistry.php`:

```php
    public const POOLS = [
        'watch' => ['video'],
        'listen' => ['track', 'release', 'episode'],
        'media' => ['media'],
        'events' => ['event'],
        'services' => ['service'],
        'reviews' => ['review'],
    ];
```

`PAGE_KEYS` gains `'reviews' => 'reviews'`, `PAGE_LABELS` gains `'reviews' => 'Reviews'`. `LATEST_TAG_POOLS` is **unchanged**. `SECTION_SHAPE` gains:

```php
        // Vendor-curated Sample: orderField null, never dominates, never
        // deletes. The default's latest_per_auto_source would show one review
        // and hide the rest. order_by recency orders the display; it does not
        // claim the set is complete, which is why reviews is absent from
        // LATEST_TAG_POOLS.
        'reviews' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
```

Then the two new consts and their accessors:

```php
    /**
     * Pools where the owner may hide an item but not promote one.
     *
     * Reviews only. The privacy disclosure justifies republishing a stranger's
     * name and words on the grounds that visitors see "genuine, attributable
     * feedback"; letting the owner arrange a testimonial reel weakens that
     * exactly where it matters, and it is the reviewer — who never consented
     * and holds no account — who carries the cost. Owner decision 2026-08-13.
     *
     * @var list<string>
     */
    public const EXCLUDE_ONLY_POOLS = ['reviews'];

    /**
     * Pools that refuse hand-authored items. Creating an item of kind `review`
     * is fabricating a testimonial attributed to a customer.
     *
     * @var list<string>
     */
    public const MANUAL_ADD_FORBIDDEN_POOLS = ['reviews'];

    public static function allowsPin(string $pool): bool
    {
        return ! in_array($pool, self::EXCLUDE_ONLY_POOLS, true);
    }

    public static function allowsManualAdd(string $pool): bool
    {
        return ! in_array($pool, self::MANUAL_ADD_FORBIDDEN_POOLS, true);
    }
```

- [ ] **Step 4: Run the tests**

```bash
composer test -- --filter=ReviewsPool
composer test -- --filter=PoolRegistry
```
Expected: PASS. `PoolRegistryTest` pins that a kind belongs to at most one pool — `review` is new, so it should stay green.

- [ ] **Step 5: Commit**

```bash
git add app/Site/Pools/PoolRegistry.php tests/Feature/Pools/ReviewsPoolTest.php
git commit -m "feat(pools): reviews pool — exclusion only, no pins, no manual adds"
```

---

## Task 6: The `review` block on the pool payload

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php` (`itemPayloads()`)
- Test: `tests/Feature/Pools/ReviewsPoolTest.php` (extend)

**Interfaces:**
- Consumes: `PoolRegistry::POOLS['reviews']` from Task 5; `content.f_review.author_uri` from Task 1.
- Produces: every pool item payload gains `'review' => array{rating: float, text: ?string, authorName: ?string, authorPhotoUrl: ?string, authorUri: ?string, reviewedAt: ?string}|null`, null off non-review kinds.

**Why:** `PoolResolver::itemPayloads()` joins nine facet tables and `f_review` is not one of them. Without this the reviews pool ships a card with no rating and no text — and since Task 2 nulls the headline, an entirely empty card. This is also what makes the null headline safe: attribution now comes from `f_review`, which redaction, pruning and DSAR all govern.

**`PoolWireShapeTest` WILL go red, and that is the mechanism working.** Slice 5b (merged 2026-08-13, `38d814463`) added `PoolResolver::ITEM_KEYS` and `tests/Feature/Content/PoolWireShapeTest.php` as the `#API-1` enforcement point replacing `SHOP_PRODUCT_ALLOWLIST`. It asserts the **exact** key set, so it catches additions as well as removals. Adding `review` to the payload without adding it to `ITEM_KEYS` fails the build — by design. Update both together:

```php
    public const ITEM_KEYS = [
        'id', 'kind', 'slug', 'aliases', 'headline', 'headlineEdited', 'url',
        'platform', 'creator', 'publishedAt', 'firstSeenAt', 'durationSeconds',
        'thumbnail', 'frames', 'startsAt', 'startsAtLocal', 'endsAtLocal',
        'timezone', 'venue', 'locality', 'price', 'availability', 'links',
        'popularityRank', 'description', 'vendor', 'variants', 'collectionIds',
        'review', 'selected', 'origin',
    ];
```

Then update `PoolWireShapeTest`'s expected set to match. Do NOT weaken the assertion to a subset check to make it pass — the whole value of that test is that it is exact.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Pools/ReviewsPoolTest.php` (uses the same fixture helpers as the existing pool tests — check `tests/Feature/Pools/` for the established `seedPoolItem`-style helper and follow it rather than inventing one):

```php
it('ships rating, text and attribution on a review item', function () {
    // Seed a claimed user with one review item selected into the reviews pool,
    // following the fixture pattern used by the existing pool tests.
    [$site, $itemId] = seedReviewPoolItem([
        'author_name' => 'A Real Person',
        'author_photo_url' => 'https://lh3.googleusercontent.com/a/abc',
        'author_uri' => 'https://maps.google.com/contrib/123',
        'rating' => 5.0,
        'text' => 'Excellent service.',
        'reviewed_at' => '2026-07-01T10:00:00Z',
    ]);

    $resolved = app(App\Site\Pools\PoolResolver::class)->resolve($site, 'reviews');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item['review'])->toMatchArray([
        'rating' => 5.0,
        'text' => 'Excellent service.',
        'authorName' => 'A Real Person',
        'authorUri' => 'https://maps.google.com/contrib/123',
    ])->and($item['headline'])->toBeNull();
});

// Wire shape must not change with kind — the contract startsAt/venue/price
// and frames already keep.
it('ships a null review block on non-review kinds', function () {
    [$site, $itemId] = seedVideoPoolItem();

    $resolved = app(App\Site\Pools\PoolResolver::class)->resolve($site, 'watch');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item)->toHaveKey('review')
        ->and($item['review'])->toBeNull();
});
```

- [ ] **Step 2: Run and confirm failure**

```bash
composer test -- --filter=ReviewsPool
```
Expected: FAIL — no `review` key on the payload.

- [ ] **Step 3: Implement**

In `PoolResolver::itemPayloads()`, alongside the other facet lookups (near the `$places`/`$creators` block around line 270-300), add:

```php
        $reviews = DB::connection('pgsql')->table('content.f_review')
            ->whereIn('item_id', $ids)
            ->get(['item_id', 'author_name', 'author_photo_url', 'author_uri', 'rating', 'text', 'reviewed_at'])
            ->keyBy('item_id');
```

Then in the payload array, beside `'price'`:

```php
                // Slice 6: the review itself. Present on every pool item and
                // null off other kinds, the same contract startsAt/venue/price
                // keep. Attribution is read from f_review — the ONE copy that
                // Manifest::$redactionScopes, content:prune-orphaned-review-pii
                // and the DSAR omission all reach. Do not source it from
                // headline: that copy was the §2.2 defect.
                'review' => isset($reviews[$itemId]) ? [
                    'rating' => (float) $reviews[$itemId]->rating,
                    'text' => $reviews[$itemId]->text,
                    'authorName' => $reviews[$itemId]->author_name,
                    'authorPhotoUrl' => $reviews[$itemId]->author_photo_url,
                    'authorUri' => $reviews[$itemId]->author_uri,
                    'reviewedAt' => $reviews[$itemId]->reviewed_at,
                ] : null,
```

- [ ] **Step 4: Run the tests**

```bash
composer test -- --filter=ReviewsPool
composer test -- --filter=Pool
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Site/Pools/PoolResolver.php tests/Feature/Pools/ReviewsPoolTest.php
git commit -m "feat(pools): review block on the pool item payload

Reads f_review, the one copy redaction, pruning and DSAR all govern. Without
it the reviews pool ships a card with no rating and no text."
```

---

## Task 7: Honour the owner's reviews toggle, and refuse manual review items

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php`
- Modify: `app/Http/Controllers/Api/Content/PoolItemCreateController.php`
- Test: `tests/Feature/Pools/ReviewsPoolTest.php` (extend)

**Interfaces:**
- Consumes: `PoolRegistry::allowsManualAdd()` from Task 5.

**Why:** `DisplaySettingsFilter` is applied only in `PublicIntegrationConnectionResource` and the GB dashboard paths. `buildPools()` calls `PoolResolver::resolve()` with no gate, so an owner who switched reviews **off** would have them republished by the pool — a regression against their express setting.

- [ ] **Step 1: Write the failing tests**

```php
it('drops review items whose connection suppresses reviews', function () {
    [$site, $itemId, $connectionId] = seedReviewPoolItem(['rating' => 5.0]);

    DB::table('site.platform_connections')->where('id', $connectionId)
        ->update(['display_settings' => json_encode(['reviews' => false])]);

    $resolved = app(App\Site\Pools\PoolResolver::class)->resolve($site, 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->toBeNull();
});

it('refuses a manual add to the reviews pool', function () {
    $user = createUserWithSite();

    $this->withSupabaseJwt($user)
        ->postJson('/api/content/pools/reviews/items', ['url' => 'https://example.com/x'])
        ->assertStatus(422);
});
```

Match the auth helper and route prefix used by the existing pool controller tests — check `tests/Feature/` for the established pattern rather than assuming `withSupabaseJwt`.

- [ ] **Step 2: Run and confirm failure**

```bash
composer test -- --filter=ReviewsPool
```
Expected: FAIL — item still present, and the POST returns 201.

- [ ] **Step 3: Implement the display gate**

In `PoolResolver::itemPayloads()`, after `$reviews` is fetched, resolve suppression per source and drop the block AND the item. Keyed per-source, not per-pool, so it stays correct if a second review platform ever lands:

```php
        // Slice 6: the owner's "reviews" display toggle lives on the platform
        // connection and is applied by DisplaySettingsFilter on the LEGACY
        // payload lane only — buildPools() never passes through it. Without
        // this, switching reviews off leaves them republished via the pool.
        $suppressed = DB::connection('pgsql')->table('content.sources')
            ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.sources.id', $sourceIdsForItems)
            ->get(['content.sources.id', 'site.platform_connections.platform', 'site.platform_connections.display_settings'])
            ->filter(fn ($row) => in_array(
                'reviews',
                array_keys(array_filter(
                    (array) json_decode((string) ($row->display_settings ?? '{}'), true),
                    static fn ($v) => $v === false
                )),
                true
            ))
            ->pluck('id')
            ->flip();
```

Exclude any item whose sole source is suppressed before building its payload. Derive `$sourceIdsForItems` from the `content.source_items` rows already loaded for `$ids`; if the method does not already have them, add a single lookup rather than a per-item query — this sits on the public hot path.

- [ ] **Step 4: Implement the manual-add refusal**

In `PoolItemCreateController`, immediately after `$kinds = PoolRegistry::kinds($pool);`:

```php
        // Slice 6: hand-authoring an item of kind `review` is fabricating a
        // testimonial attributed to a customer. Declared once in PoolRegistry
        // so the resolver and this endpoint read one source of truth.
        if (! PoolRegistry::allowsManualAdd($pool)) {
            abort(422, 'Reviews come from your connected platforms and cannot be added by hand.');
        }
```

This is a capability rule about the pool, not an authorization check on a resource, so `abort(422)` is correct here and does not trip the inline-403 CI guard.

- [ ] **Step 5: Enforce exclusion-only at the curation write path**

`PoolRegistry::allowsPin()` from Task 5 is decorative until something consumes
it. The curation state is written through `UpsertSectionItemRequest`, which
validates `state` as `Rule::in(['pinned','excluded'])`. Find the controller
that handles it (grep `UpsertSectionItemRequest`) and refuse a `pinned` state
for a section whose pool disallows it:

```php
        // Slice 6: reviews may be hidden but not promoted — owner decision
        // 2026-08-13. Refusing here rather than in the request rule because
        // the rule has no pool context; PoolRegistry::allowsPin is the one
        // source of truth and PoolResolver reads the same const.
        if ($state === 'pinned' && $pool !== null && ! PoolRegistry::allowsPin($pool)) {
            abort(422, 'Reviews can be hidden, but not pinned.');
        }
```

Derive `$pool` from the section key — `PoolRegistry::sectionKey($pool)` produces
`"pool:{$pool}"`, so strip that prefix rather than re-deriving from the kind.

Add the matching test:

```php
it('refuses a pin on the reviews pool but allows an exclusion', function () {
    [$site, $itemId] = seedReviewPoolItem(['rating' => 5.0]);
    $user = /* the site's owner, per the existing section-curation tests */;

    $this->actingAsUser($user)
        ->putJson("/api/user/sections/pool:reviews/items/{$itemId}", ['state' => 'pinned'])
        ->assertStatus(422);

    $this->actingAsUser($user)
        ->putJson("/api/user/sections/pool:reviews/items/{$itemId}", ['state' => 'excluded'])
        ->assertSuccessful();
});
```

Match the route and auth helper to the existing section-curation tests — grep
`UpsertSectionItemRequest` for the route and copy the pattern rather than
assuming `actingAsUser`.

- [ ] **Step 6: Run the tests**

```bash
composer test -- --filter=ReviewsPool
composer test -- --filter=PoolItemCreate
composer test -- --filter=SectionItem
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Site/Pools/PoolResolver.php \
        app/Http/Controllers/Api/Content/PoolItemCreateController.php \
        tests/Feature/Pools/ReviewsPoolTest.php
git commit -m "fix(pools): honour the reviews display toggle, refuse hand-written reviews"
```

---

## Task 8: Retire the legacy public read, and follow with DSAR

**Files:**
- Modify: `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:185`
- Modify: `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- Modify: `app/Services/Platforms/DsarPayloadFilter.php` (`WITHHELD_DISCLOSURE` copy only)
- Test: `tests/Feature/Security/ContentPiiExportCoverageTest.php`, `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php`

**Interfaces:**
- Consumes: Tasks 4, 6 — the pool and `source_stats` now serve what the legacy keys served.

- [ ] **Step 1: Write the failing tests**

The allowlist is a plain const, so assert it directly rather than building a
resource — that keeps the test honest about what actually changed and does not
depend on connection fixtures:

```php
it('no longer serves the review keys from the legacy connection payload', function () {
    $reflection = new ReflectionClass(App\Http\Resources\Platforms\PublicIntegrationConnectionResource::class);
    $allowlist = $reflection->getConstant('PAYLOAD_KEYS')['google-business'];

    expect($allowlist)
        ->not->toContain('reviews')
        ->not->toContain('reviewSummary')
        ->not->toContain('rating')
        ->not->toContain('reviewCount')
        // Everything else on that lane is untouched — this retires the review
        // surface, not the connection.
        ->toContain('photos')
        ->toContain('hours');
});
```

Read `PublicIntegrationConnectionResource.php:185` first and use the const's
real name; if the map is a private property rather than a const, assert through
the resource's public `toArray()` with a connection fixture copied from the
existing tests in that file.

// The 2026-08-05 precedent: DSAR allowlists deliberately RETAIN legacy keys so
// payloads stored before the retirement stay disclosable.
it('keeps the legacy keys in the DSAR third-party list', function () {
    expect(App\Services\Platforms\DsarPayloadFilter::THIRD_PARTY_KEYS)
        ->toContain('reviews')
        ->toContain('reviewSummary');
});

it('exports source_stats without the review summary', function () {
    // summary_text is Google prose derived from reviews — withheld from the
    // subject's export exactly as the legacy reviewSummary key is.
    $userId = createUser();
    $sourceId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.source_stats')->insert([
        'source_id' => $sourceId, 'rating_avg' => 4.7, 'rating_count' => 312,
        'summary_text' => 'Customers praise the friendly staff.', 'updated_at' => $now,
    ]);

    $rows = iterator_to_array(
        app(App\Services\User\DataExport\DataExportPayloadBuilder::class)
            ->sectionRows($userId, 'content.source_stats')
    );

    expect($rows)->toHaveCount(1)
        ->and(array_keys((array) $rows[0]))
        ->toContain('rating_avg')->toContain('rating_count')
        ->not->toContain('summary_text');
});
```

`sectionRows()` is illustrative — `DataExportPayloadBuilder` exposes its
sections through the array built around `:220-290`. Read that method and drive
the test through whatever the existing `DataExportPayloadBuilderTest` uses to
resolve a single section; do not add a public method just to make the test
convenient.

```php
```

- [ ] **Step 2: Run and confirm failure**

```bash
composer test -- --filter=DataExport
composer test -- --filter=PublicIntegrationConnection
```

- [ ] **Step 3: Drop the four keys from the public allowlist**

`PublicIntegrationConnectionResource.php:185` — remove `'rating'`, `'reviewCount'`, `'reviews'`, `'reviewSummary'` from the `google-business` list, leaving the rest untouched. Add above it:

```php
        // Slice 6: reviews, reviewSummary, rating and reviewCount retired from
        // this lane — the pool serves the individual reviews and
        // content.source_stats serves the aggregates. The FETCH is unchanged;
        // GoogleBusinessService still populates the payload and the dashboard
        // resource still reads it. This retires a read, not a fetch.
```

Leave `GoogleBusinessConnectionResource:25` (the dashboard resource) alone.

- [ ] **Step 4: Add the `source_stats` DSAR section**

In `DataExportPayloadBuilder`, beside the `content.f_review` line:

```php
            // Slice 6: source-level aggregates. summary_text is Google-authored
            // prose derived from reviews and is withheld the same way
            // DsarPayloadFilter withholds the legacy reviewSummary key —
            // see WITHHELD_DISCLOSURE. rating_avg/rating_count are business
            // facts about the subject's own listing and ARE disclosed.
            ['name' => 'content.source_stats', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentSourceStats($userId)],
```

```php
    private function streamContentSourceStats(string $userId): Generator
    {
        return $this->lazyRows(
            DB::connection('pgsql')
                ->table('content.source_stats')
                ->join('content.sources', 'content.source_stats.source_id', '=', 'content.sources.id')
                ->where('content.sources.user_id', $userId)
                ->select(['content.source_stats.source_id', 'content.source_stats.rating_avg',
                    'content.source_stats.rating_count', 'content.source_stats.updated_at'])
        );
    }
```

Add `'content.source_stats'` to the `COVERED_PII_TABLES` list at `:106` alongside `'content.f_review'`.

- [ ] **Step 5: Extend the withheld-disclosure copy**

In `DsarPayloadFilter::WITHHELD_DISCLOSURE`, extend the Google clause to name the aggregate summary — keep the existing sentence structure and the `reviews`/`reviewSummary` backticks intact so the 2026-08-05 wording precedent survives.

- [ ] **Step 6: Run the tests**

```bash
composer test -- --filter=DataExport
composer test -- --filter=ContentPiiExportCoverage
composer test -- --filter=PublicIntegrationConnection
```
Expected: PASS. `ContentPiiExportCoverageTest` is the guard that every `content.*` PII table is either exported or justified — a new table with neither turns it red, which is the intended behaviour.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php \
        app/Services/User/DataExport/DataExportPayloadBuilder.php \
        app/Services/Platforms/DsarPayloadFilter.php tests/
git commit -m "feat(wire): retire the legacy review read; DSAR follows to source_stats

THIRD_PARTY_KEYS keeps reviews/reviewSummary — the 2026-08-05 precedent is
that allowlists retain legacy keys so stored payloads stay disclosable."
```

---

## Task 9: Provision sections, then documentation and propagation

**Files:**
- Modify: `app/Site/Pools/PoolSectionProvisioner.php` if it needs a per-pool branch (read it first — it may already loop `PoolRegistry::POOLS`)
- Create: `docs/wire-changes/2026-08-12-slice-6-reviews.md`
- Modify: `docs/legal/reviewer-data-disclosure.md`
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`
- Modify: `docs/superpowers/plans/2026-08-12-slice-6-reviews-KICKOFF-PROMPT.md`
- Modify: the slice 5b, slice 4 and slice 7 kickoff prompts

- [ ] **Step 1: Provision `pool:reviews` sections for existing users**

Read `app/Site/Pools/PoolSectionProvisioner.php`. If it already loops `PoolRegistry::POOLS`, no code change is needed — confirm with a test that a site with a `google_business` source gets a `pool:reviews` section. If it does not, add the loop rather than a `reviews` special case.

- [ ] **Step 2: Write the wire manifest**

Create `docs/wire-changes/2026-08-12-slice-6-reviews.md` covering: `pools.reviews` added with its item shape including the `review` block; `review` null on all other kinds; `integrations[].reviews` / `reviewSummary` / `rating` / `reviewCount` removed from the public integration payload; `headline` now null on review items. Follow the structure of `docs/wire-changes/2026-08-12-slice-1b-*.md`. Mark STATUS as pending until Task 10 verifies on dev.

- [ ] **Step 3: Update the legal disclosure**

In `docs/legal/reviewer-data-disclosure.md`:
- §1: reviewer data now reaches the public wire via the pool lane (`PoolResolver`'s `review` block reading `content.f_review`), not `GoogleBusinessService.php:304-311`. The field list is unchanged — `author_uri` was preserved deliberately so the retirement did not silently drop it.
- §3 draft clause: add a sentence disclosing that the professional can suppress individual reviews.
- §4: add a point for the adviser — selective suppression by the owner interacts with the "genuine, attributable feedback" justification in §3.

**Do not soften or remove any existing §4 point.** LEGAL-2 is not discharged by this slice.

- [ ] **Step 4: Propagate — edit in place, say the fact not the story**

| Target | Fact to record |
|---|---|
| Parent spec §1 + revision note | The five corrected premises (design spec §1.1) |
| Parent spec §5 | `content.source_stats` is the first source-level fact in `content.*`; `ProjectionWriter` has a source-scoped write path |
| `slice-7-teardown` | `content.source_stats` exists and the public wire depends on it; the `profile` stream is redundant with the `IdentitySync` fold — retire or justify, do not drop silently; `PruneOrphanedReviewPiiCommand` still depends on `content.f_review` |
| `slice-5b`, `slice-4-menus` | `PoolRegistry` gains `reviews` + `EXCLUDE_ONLY_POOLS` + `MANUAL_ADD_FORBIDDEN_POOLS`; `PoolResolver::itemPayloads()` gains a `review` block and a display-toggle gate |
| This slice's own kickoff prompt | Replace the "expect 0 on both" entry gate with the measured state |

- [ ] **Step 5: Commit**

```bash
git add docs/ app/Site/Pools/PoolSectionProvisioner.php tests/
git commit -m "docs(slice-6): wire manifest, legal disclosure, and propagation"
```

---

## Task 10: Verify on dev

**Not a code task. Do not mark complete without pasted output.**

- [ ] **Step 1: Confirm the coordinator has applied both migrations**

```sql
SELECT column_name FROM information_schema.columns
WHERE table_schema='content' AND table_name='f_review' AND column_name='author_uri';
SELECT to_regclass('content.source_stats');
```
Both must return a row. If not, STOP — do not run the commands below against a schema that lacks them.

- [ ] **Step 2: Re-project and purge**

```bash
cloud command:run development "php artisan ingest:project --rebuild"
cloud command:run development "php artisan content:purge-review-headline-pii --dry-run"
cloud command:run development "php artisan content:purge-review-headline-pii"
```

- [ ] **Step 3: Assert the PII copies are gone**

```sql
SELECT count(*) FROM content.items i WHERE i.kind='review' AND i.headline_cache IS NOT NULL;
SELECT count(*) FROM content.f_text ft JOIN content.items i ON i.id=ft.item_id WHERE i.kind='review';
```
Both must be 0.

- [ ] **Step 4: Assert redaction still holds in both directions**

```sql
SELECT u.status, count(*) AS rows,
       count(*) FILTER (WHERE fr.author_name IS NOT NULL) AS named,
       count(*) FILTER (WHERE fr.author_uri IS NOT NULL) AS with_uri
FROM content.f_review fr
JOIN content.items i ON i.id = fr.item_id
JOIN core.users u ON u.id = i.user_id
GROUP BY u.status;
```
Expected: `active` named = row count; `unclaimed` named = 0 AND with_uri = 0. **Report counts, not names.**

- [ ] **Step 4b: Assert the claim-transition contract**

Spec §2.1: redaction is applied at LANDING (`RunExecutor:146` → `Lander`), so
the stored `record_versions` doc for an unclaimed owner is permanently
redacted. Claiming does NOT restore attribution — only a fresh billed fetch
past the freshness window does. Assert the real behaviour, not the intuitive
one:

1. Pick an unclaimed user holding review rows. Record `count(author_name)` = 0.
2. Flip that user to `active` (a real claim, or `core.users.status` directly on
   dev — note which you did).
3. Re-project without a new fetch:
   `cloud command:run development "php artisan ingest:project --rebuild"`
4. Assert `author_name` is STILL null for those rows.

```sql
SELECT count(*) FILTER (WHERE fr.author_name IS NOT NULL) AS named
FROM content.f_review fr
JOIN content.items i ON i.id = fr.item_id
WHERE i.user_id = '<the newly-claimed user id>';
```
Expected: 0. If this returns non-zero, redaction is NOT applied at landing and
§2.1 of the spec is wrong — STOP and report, do not proceed to merge.

Restore the user's original status afterwards if you changed it directly.

- [ ] **Step 5: Exercise the prune command against real rows**

Retire a `source_items` row for one review, backdate its `f_review.updated_at` past the grace window, run `content:prune-orphaned-review-pii --dry-run` then for real, and assert the row is gone and no `f_text`/`headline_cache` remnant exists.

- [ ] **Step 6: Verify a NEVER-RUN source lands the new shape**

Effect replay means a source that ran inside the 7-day freshness window returns the cached payload without calling the driver — re-running a recent source proves nothing. Pick one of the **9 never-run** `google_business` sources, run it, and assert `content.source_stats` gained a row with `rating_avg`/`rating_count`.

- [ ] **Step 7: Logs and Nightwatch**

```bash
cloud env:logs partna development --minutes 10
```
Then a Nightwatch scan. Slice 0 recorded a log scan and skipped Nightwatch — do not repeat that.

- [ ] **Step 8: Paste all output into a parent-spec checkpoint, and flip the wire manifest STATUS to LIVE**

---

## Task 11: Merge

- [ ] **Step 1:** `git fetch origin && git rebase origin/development`
- [ ] **Step 2:** Resolve the 5b collision on `PoolRegistry` and `PoolResolver` if 5b merged first. **Re-run `PoolRegistryTest` AFTER resolving.**
- [ ] **Step 3:** `composer test`, `composer test:pg`, `composer test:schema`, `vendor/bin/phpstan analyse`, `php artisan pint`
- [ ] **Step 4: STOP — explicit sign-off from the owner before merging.**
- [ ] **Step 5:** Merge to `development` and push. **Never push to `production`.**

---

## Notes for the implementer

- `composer test -- --filter=X` and `php artisan pint` are known-broken in combination — filter locally, run pint separately.
- CI runs nine jobs and takes ~17 minutes; red costs the same as green. Filter locally rather than pushing to find out.
- The `authz` lane SKIPS silently without Postgres — "31 skipped" reads green and tests nothing.
- SQLite treats an unknown double-quoted identifier as a string literal, so a typo'd column name in a test assertion passes silently. Check column names against the migration.
- Do not run `git stash`. Other sessions share this checkout's parent.
