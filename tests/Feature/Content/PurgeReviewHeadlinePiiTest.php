<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 6 §2.3 part two. GoogleBusinessReviewProjector used to set `headline`
// to the reviewer's display name; ProjectionWriter folds a non-empty headline
// into content.f_text and resolves it into content.items.headline_cache. Task
// 2 stopped NEW copies, but upsertSingletonFacet is upsert-only and never
// deletes — so without this command the existing rows keep being served.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

/**
 * A review item carrying the reviewer's name in all three places: the governed
 * f_review row plus the two ungoverned copies this command exists to remove.
 *
 * @return array{0: string, 1: string} [itemId, sourceId]
 */
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
    $pro = createTenant('purgehl1');
    [$itemId] = seedReviewWithHeadlinePii($pro->id);

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('content.f_text')->where('item_id', $itemId)->count())->toBe(0)
        ->and(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBeNull();
});

// f_review is the ONE copy that redaction, pruning and DSAR govern. This
// command must not touch it — deleting reviewer PII is the prune command's
// job, on its own orphan rule and grace window.
it('leaves f_review untouched', function () {
    $pro = createTenant('purgehl2');
    [$itemId] = seedReviewWithHeadlinePii($pro->id);

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('content.f_review')->where('item_id', $itemId)->value('author_name'))
        ->toBe('A Real Person');
});

it('does not touch non-review items', function () {
    $pro = createTenant('purgehl3');
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'video',
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
    $pro = createTenant('purgehl4');
    [$itemId] = seedReviewWithHeadlinePii($pro->id);

    $this->artisan('content:purge-review-headline-pii', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('content.f_text')->where('item_id', $itemId)->count())->toBe(1)
        ->and(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('A Real Person');
});

// Spec §6.2: three lanes, and no CI check enforces them. This is a raw write
// that bypasses every Eloquent observer, so without all three the published
// document keeps rendering a headline whose row was just deleted.
it('bumps all three cache lanes for the affected site', function () {
    Bus::fake();

    $pro = createTenant('purgehl5');
    seedReviewWithHeadlinePii($pro->id);

    $siteId = DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    $updatedBefore = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    $this->travelTo(now()->addMinute());
    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))->toBe(1)
        ->and(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($updatedBefore);

    // Dispatched by SUBDOMAIN — the job's constructor takes a handle, not a
    // site id. Passing the uuid would purge a cache key that does not exist.
    Bus::assertDispatched(CloudflareCachePurgeJob::class, fn ($job) => $job->handle === 'purgehl5');
});

it('is a no-op on a second run', function () {
    $pro = createTenant('purgehl6');
    seedReviewWithHeadlinePii($pro->id);

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    $siteId = DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    $revision = DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision');

    $this->artisan('content:purge-review-headline-pii')->assertSuccessful();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($revision);
});
