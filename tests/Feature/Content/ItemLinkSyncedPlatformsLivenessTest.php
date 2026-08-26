<?php

use App\Site\Pools\ItemLinkRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #LIFE-8 — syncedPlatformsFor() counted a link from a RETIRED connection as
// still-synced.
//
// The consequence is the wrong way round from most liveness bugs: it does not
// leak content, it LOCKS THE OWNER OUT. ItemLinkController::upsert() refuses a
// manual link for any platform the item is "already synced" from, so a
// disconnected platform kept its claim on the surface and the owner could not
// hand-add a replacement for a link that had stopped updating.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function linkLivenessFixture(bool $connectionLive = true, bool $paused = false): array
{
    $pro = createTenant('lnk-'.Str::lower(Str::random(6)));

    $connectionId = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $pro->id, 'surface_key' => 'youtube.channel',
        'routing_class' => 'content', 'resource_id' => 'res-'.Str::random(8),
        // NOT 'platform' — it is a GENERATED column derived from surface_key,
        // and inserting it errors. surface_key 'youtube.channel' yields 'youtube'.
        'payload' => json_encode([]),
        'is_active' => $paused ? 0 : 1,
        'deleted_at' => $connectionLive ? null : now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'video',
        'headline_cache' => 'A video', 'facets_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'url' => 'https://www.youtube.com/watch?v=abc', 'updated_at' => now(),
    ]);

    return [$itemId, $connectionId];
}

it('counts a link from a live connection as synced', function () {
    [$itemId] = linkLivenessFixture(connectionLive: true);

    // The control. Without it the assertions below could pass because the
    // query returns nothing at all, for a reason that has nothing to do with
    // liveness.
    expect(ItemLinkRules::syncedPlatformsFor($itemId))->toBe(['youtube']);
});

it('stops counting it once the owner removes the connection, so a manual replacement is allowed (LIFE-8)', function () {
    [$itemId, $connectionId] = linkLivenessFixture(connectionLive: true);
    expect(ItemLinkRules::syncedPlatformsFor($itemId))->toBe(['youtube']);

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => now()]);

    expect(ItemLinkRules::syncedPlatformsFor($itemId))->toBe([]);

    // History is kept — hide, never delete. Reconnecting restores the claim.
    expect(DB::table('content.f_link')->where('item_id', $itemId)->exists())->toBeTrue();
    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => null]);
    expect(ItemLinkRules::syncedPlatformsFor($itemId))->toBe(['youtube']);
});

it('also stops counting it while the connection is paused, matching LiveSourceScope everywhere else', function () {
    // is_active = false. This is the semantics LiveSourceScope has carried since
    // W2 and which this change now applies consistently rather than on some
    // surfaces only — see DECISIONS §3.1, where the underlying product question
    // is written up for the owner.
    [$itemId, $connectionId] = linkLivenessFixture();

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['is_active' => 0]);

    expect(ItemLinkRules::syncedPlatformsFor($itemId))->toBe([]);
});
