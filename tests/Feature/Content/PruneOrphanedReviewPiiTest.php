<?php

use App\Console\Commands\PruneOrphanedReviewPiiCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #PRIV-3: content:prune-orphaned-review-pii hard-deletes content.f_review
// rows (author_name, author_photo_url, verbatim text — third-party reviewer
// PII) once no LIVE content.source_items row still carries the
// (item_id, source_id) pair, past a grace window. Deliberately NOT a TTL
// over live data — see PruneOrphanedReviewPiiCommand's docblock for why an
// age rule over a live row would loop forever against ingest:dispatch's
// 15-minute re-projection.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

/**
 * One reviewer-PII fixture: a content.sources row, a content.items row
 * (kind 'review'), a content.source_items row, and the content.f_review row
 * itself.
 *
 * @return array{0: string, 1: string} [itemId, sourceId]
 */
function seedReviewFixture(string $userId, array $overrides = []): array
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
        'headline_cache' => 'A reviewer', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'coord' => 'coord-'.Str::random(8),
        'item_id' => $itemId, 'kind' => 'review',
        'first_seen_at' => $now, 'last_seen_at' => $now,
        'removed_at' => $overrides['source_item_removed_at'] ?? null,
    ]);

    DB::table('content.f_review')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'author_name' => $overrides['author_name'] ?? 'Jane Reviewer',
        'author_photo_url' => 'https://example.com/photo.jpg',
        'rating' => 5.0,
        'text' => 'Great service!',
        'reviewed_at' => $now,
        'updated_at' => $overrides['updated_at'] ?? $now,
    ]);

    return [$itemId, $sourceId];
}

function reviewFixtureExists(string $itemId, string $sourceId): bool
{
    return DB::table('content.f_review')->where('item_id', $itemId)->where('source_id', $sourceId)->exists();
}

it('deletes an orphaned review past the grace window', function () {
    $pro = createTenant('privorph1');
    [$itemId, $sourceId] = seedReviewFixture($pro->id, [
        'source_item_removed_at' => now()->subDays(20)->toDateTimeString(),
        'updated_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14])->assertSuccessful();

    expect(reviewFixtureExists($itemId, $sourceId))->toBeFalse();
});

it('keeps an orphaned review that is still within the grace window', function () {
    $pro = createTenant('privorph2');
    [$itemId, $sourceId] = seedReviewFixture($pro->id, [
        'source_item_removed_at' => now()->subDay()->toDateTimeString(),
        'updated_at' => now()->subDay()->toDateTimeString(),
    ]);

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14])->assertSuccessful();

    expect(reviewFixtureExists($itemId, $sourceId))->toBeTrue();
});

it('never deletes a review whose source_item is still LIVE, no matter how old — proves no TTL over live data', function () {
    $pro = createTenant('privorph3');
    [$itemId, $sourceId] = seedReviewFixture($pro->id, [
        'source_item_removed_at' => null,
        'updated_at' => now()->subYear()->toDateTimeString(),
    ]);

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14])->assertSuccessful();

    expect(reviewFixtureExists($itemId, $sourceId))->toBeTrue();
});

it('--dry-run mutates nothing', function () {
    $pro = createTenant('privorph4');
    [$itemId, $sourceId] = seedReviewFixture($pro->id, [
        'source_item_removed_at' => now()->subDays(20)->toDateTimeString(),
        'updated_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    $siteId = DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    DB::table('site.site_build_state')->insert([
        'site_id' => $siteId, 'content_revision' => 3, 'built_revision' => 3, 'updated_at' => now(),
    ]);

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14, '--dry-run' => true])->assertSuccessful();

    expect(reviewFixtureExists($itemId, $sourceId))->toBeTrue()
        ->and(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))->toBe(3);
});

it('bumps site_build_state.content_revision exactly once per affected site', function () {
    $pro = createTenant('privorph5');
    seedReviewFixture($pro->id, [
        'source_item_removed_at' => now()->subDays(20)->toDateTimeString(),
        'updated_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    $siteId = DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    // No pre-existing site_build_state row — BuildState::bump() must create
    // one rather than silently no-op against a missing row.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->exists())->toBeFalse();

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14])->assertSuccessful();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))->toBe(1);
});

it('re-running after a successful prune is a zero-row no-op', function () {
    $pro = createTenant('privorph6');
    [$itemId, $sourceId] = seedReviewFixture($pro->id, [
        'source_item_removed_at' => now()->subDays(20)->toDateTimeString(),
        'updated_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14])->assertSuccessful();
    expect(reviewFixtureExists($itemId, $sourceId))->toBeFalse();

    $siteId = DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    $revisionAfterFirstRun = DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision');

    $this->artisan(PruneOrphanedReviewPiiCommand::class, ['--days' => 14])->assertSuccessful();

    // Second run finds nothing eligible — no further bump.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($revisionAfterFirstRun);
});
