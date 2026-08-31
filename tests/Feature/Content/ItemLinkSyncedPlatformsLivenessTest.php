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

/** @return array{0: string, 1: string, 2: string} [itemId, connectionId, ownerId] */
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

    return [$itemId, $connectionId, (string) $pro->id];
}

/** A live youtube connection owned by SOMEBODY ELSE. Returns [userId, connectionId]. */
function linkForeignConnection(): array
{
    $pro = createTenant('fgn-'.Str::lower(Str::random(6)));

    $connectionId = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $pro->id, 'surface_key' => 'youtube.channel',
        'routing_class' => 'content', 'resource_id' => 'res-'.Str::random(8),
        'payload' => json_encode([]), 'is_active' => 1, 'deleted_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [(string) $pro->id, $connectionId];
}

/** The content.sources row that connection lands through. */
function linkSourceFor(string $userId, string $connectionId): string
{
    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $sourceId;
}

it('counts a link from a live connection as synced', function () {
    [$itemId, , $ownerId] = linkLivenessFixture(connectionLive: true);

    // The control. Without it the assertions below could pass because the
    // query returns nothing at all, for a reason that has nothing to do with
    // liveness.
    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe(['youtube']);
});

it('stops counting it once the owner removes the connection, so a manual replacement is allowed (LIFE-8)', function () {
    [$itemId, $connectionId, $ownerId] = linkLivenessFixture(connectionLive: true);
    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe(['youtube']);

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => now()]);

    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe([]);

    // History is kept — hide, never delete. Reconnecting restores the claim.
    expect(DB::table('content.f_link')->where('item_id', $itemId)->exists())->toBeTrue();
    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => null]);
    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe(['youtube']);
});

it('also stops counting it while the connection is paused, matching LiveSourceScope everywhere else', function () {
    // is_active = false. This is the semantics LiveSourceScope has carried since
    // W2 and which this change now applies consistently rather than on some
    // surfaces only — see DECISIONS §3.1, where the underlying product question
    // is written up for the owner.
    [$itemId, $connectionId, $ownerId] = linkLivenessFixture();

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['is_active' => 0]);

    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe([]);
});

// ── #FU-2 residual 1: the connection_id tenancy hop ─────────────────────────
//
// syncedPlatformsFor() walks f_link.source_id -> content.sources ->
// sources.connection_id -> site.platform_connections and, until now, filtered
// only on the item id. Neither link table carries a user_id of its own, so one
// mislinked source_id or connection_id — a writer bug, an identity merge, a
// hand-run SQL fix — let ANOTHER account's connection answer "already synced"
// for this owner's item.
//
// The symptom is the #LIFE-8 lockout in cross-tenant form: the owner opens the
// link control on their own item, adds a YouTube url, and the API answers 422
// "that platform already syncs this item" — naming a sync they do not have and
// cannot disconnect, because the connection belongs to somebody else.

it('does not let another owner connection veto this owner manual link (FU-2)', function () {
    [$itemId, $connectionId, $ownerId] = linkLivenessFixture();

    // Control first: the owner's OWN live connection does veto. Without this
    // the assertion below could pass for any reason at all.
    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe(['youtube']);

    // The mislink: this owner's source now points at a stranger's connection.
    // (content.sources.connection_id is UNIQUE, so the stranger's own source row
    // is created only in the second case below, which does not repoint.)
    [, $foreignConnectionId] = linkForeignConnection();
    DB::table('content.sources')->where('connection_id', $connectionId)
        ->update(['connection_id' => $foreignConnectionId]);

    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe([]);
});

it('does not let another owner source veto this owner manual link (FU-2, first hop)', function () {
    // Deliberately shaped so ONLY the content.sources.user_id predicate can
    // catch it: the stranger's source lands through THIS OWNER'S connection, so
    // the platform_connections.user_id predicate passes. A fixture where both
    // hops are foreign would still pass with the sources predicate deleted, and
    // would be asserting nothing about the first hop.
    [$itemId, $connectionId, $ownerId] = linkLivenessFixture();

    // A second, live connection of the owner's — content.sources.connection_id
    // is UNIQUE, so the stranger's source needs a connection of its own to sit
    // on, and this one is the owner's.
    $ownSpareConnection = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $ownSpareConnection, 'user_id' => $ownerId, 'surface_key' => 'vimeo.channel',
        'routing_class' => 'content', 'resource_id' => 'res-'.Str::random(8),
        'payload' => json_encode([]), 'is_active' => 1, 'deleted_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $stranger = createTenant('fgn-'.Str::lower(Str::random(6)));
    $foreignSourceId = linkSourceFor((string) $stranger->id, $ownSpareConnection);

    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $foreignSourceId,
        'url' => 'https://vimeo.com/stranger', 'updated_at' => now(),
    ]);

    // 'vimeo' would be in this list if the stranger's source counted — and the
    // owner would be refused a hand-added vimeo link naming a sync they do not
    // have. The control is that their own youtube sync is still counted.
    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe(['youtube']);

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => now()]);

    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe([]);
});

it('still ignores a manual-lane link, whose source carries a NULL connection_id', function () {
    // connection_id is NULLABLE and a kind='manual' source always carries NULL.
    // Both joins here are INNER, so such a row simply never reaches the query —
    // which is the behaviour this surface already had, and the reason a plain
    // `where` is safe here where PoolResolver's LEFT joins need an ON clause.
    [$itemId, $connectionId, $ownerId] = linkLivenessFixture();

    $manualSourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $manualSourceId, 'user_id' => $ownerId, 'kind' => 'manual',
        'connection_id' => null, 'priority' => 50,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $manualSourceId,
        'url' => 'https://vimeo.com/12345', 'updated_at' => now(),
    ]);

    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe(['youtube']);

    // And with the connection-backed link gone, the manual one leaves nothing
    // behind to veto with — the owner is free to hand-add any platform.
    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => now()]);

    expect(ItemLinkRules::syncedPlatformsFor($ownerId, $itemId))->toBe([]);
});
