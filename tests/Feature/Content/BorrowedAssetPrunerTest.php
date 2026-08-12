<?php

use App\Services\Migration\BorrowedAssetPruner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1b D4. A borrowed asset whose item is tombstoned and whose source_url
// is older than the expiry window is dead by definition — the url 403s. Google
// grants photos no caching exemption, so this is also the mechanism that keeps
// us from retaining borrowed content, not merely a tidy-up.
//
// item_media_asset_id_fkey is ON DELETE SET NULL, which is what makes the
// delete safe: a stale link nulls rather than cascading into items.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function prunerAsset(string $userId, string $fingerprint, \DateTimeInterface $createdAt, ?string $storagePath = null): string
{
    $id = (string) Str::uuid();
    DB::table('content.media_assets')->insert([
        'id' => $id,
        'user_id' => $userId,
        'fingerprint' => $fingerprint,
        'source_url' => 'https://lh3.googleusercontent.com/place-photos/'.Str::random(8),
        'storage_path' => $storagePath,
        'created_at' => $createdAt,
    ]);

    return $id;
}

/** Link the asset to a LIVE item, the way a projection run would. */
function prunerLinkLiveItem(string $userId, string $assetId): void
{
    $itemId = (string) Str::uuid();
    $sourceId = (string) Str::uuid();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => (string) Str::uuid(), 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'media',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.item_media')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'asset_id' => $assetId, 'role' => 'gallery', 'position' => 0,
        'created_at' => now(),
    ]);
}

it('prunes an unreferenced borrowed asset older than 30 days', function () {
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    $assetId = prunerAsset($user->id, 'url-'.sha1('places/x/photos/old'), now()->subDays(31));

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['pruned'])->toBe(1)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeFalse();
});

it('spares an asset with storage_path — owned bytes are never pruned', function () {
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    $assetId = prunerAsset($user->id, 'url-'.sha1('instagram:ABC:0'), now()->subDays(90), 'content-media/x/abc.webp');

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_owned'])->toBe(1)
        ->and($result['pruned'])->toBe(0)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeTrue();
});

it('spares an asset still referenced by a live item_media row', function () {
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    $assetId = prunerAsset($user->id, 'url-'.sha1('places/x/photos/live'), now()->subDays(90));
    prunerLinkLiveItem($user->id, $assetId);

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_referenced'])->toBe(1)
        ->and($result['pruned'])->toBe(0)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeTrue();
});

it('spares an asset younger than 30 days', function () {
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    $assetId = prunerAsset($user->id, 'url-'.sha1('places/x/photos/new'), now()->subDays(29));

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_recent'])->toBe(1)
        ->and($result['pruned'])->toBe(0)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeTrue();
});

it('spares an upload-backed asset even with no storage_path', function () {
    // An upload's bytes live in site.media_variants, not storage_path — its
    // asset row is a POINTER into the variant pipeline (1a §3.2). Pruning one
    // on "no storage_path" would delete an owner's own photo.
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    $siteMediaId = (string) Str::uuid();
    $id = (string) Str::uuid();
    DB::table('content.media_assets')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'fingerprint' => 'url-'.sha1('upload:'.$siteMediaId),
        'site_media_id' => $siteMediaId,
        'source_url' => null,
        'created_at' => now()->subDays(200),
    ]);

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_owned'])->toBe(1)
        ->and(DB::table('content.media_assets')->where('id', $id)->exists())->toBeTrue();
});

it('writes nothing on a dry run', function () {
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    prunerAsset($user->id, 'url-'.sha1('places/x/photos/old'), now()->subDays(31));

    $result = app(BorrowedAssetPruner::class)->run(dryRun: true);

    expect($result['pruned'])->toBe(1)
        ->and(DB::table('content.media_assets')->count())->toBe(1);
});

it('is idempotent — a second run finds nothing left to prune', function () {
    $user = createTenant('prn-'.Str::lower(Str::random(6)));
    prunerAsset($user->id, 'url-'.sha1('places/x/photos/old'), now()->subDays(31));

    app(BorrowedAssetPruner::class)->run();
    $second = app(BorrowedAssetPruner::class)->run();

    expect($second['pruned'])->toBe(0);
});

it('is registered on the schedule', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '');

    expect($events->filter(fn ($c) => str_contains($c, 'content:prune-borrowed-assets')))->not->toBeEmpty();
});
