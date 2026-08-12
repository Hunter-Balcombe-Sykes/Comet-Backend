<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 0b: owner-authored items land through the SAME writer a connector
// uses. The three things that must be true and were not before this slice:
// a manual item carries identity keys and an anchor (so a connector run
// enriches it instead of minting a blank duplicate beside it); a connector
// run can never merge it away (mergeInto()'s DELETE cascades the facet rows,
// and a manual source has no next run to rewrite them); and the manual source
// outranks every connection on value resolution.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('creates exactly one manual source per user, above connection priority', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;

    $writer = app(ProjectionWriter::class);
    $first = $writer->ensureManualSource($userId);
    $second = $writer->ensureManualSource($userId);

    expect($second)->toBe($first);

    $rows = DB::table('content.sources')->where('user_id', $userId)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->kind)->toBe('manual')
        ->and($rows[0]->connection_id)->toBeNull()
        // content.sources' DDL comment calls this "max priority: what makes
        // 'the user outranks the machine' a data fact rather than a special
        // case in code". ValueResolver::byPriority() sorts DESC, so 200 is
        // what makes the owner's headline and link beat a connection's 100.
        ->and((int) $rows[0]->priority)->toBe(200);
});

it('raises a manual source left at connection priority by the old writer', function () {
    // The live controller wrote priority 100. Find-or-create alone would
    // return that row unchanged and the C8 guarantee would silently never
    // apply to anyone who had already hand-added.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $legacyId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $legacyId, 'user_id' => $userId, 'kind' => 'manual',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(ProjectionWriter::class)->ensureManualSource($userId))->toBe($legacyId)
        ->and((int) DB::table('content.sources')->where('id', $legacyId)->value('priority'))->toBe(200);
});

/**
 * The projection shape a projector would return, for a hand-authored release.
 * Carries an offer and a tag as well as facets and media, because spec §6
 * names offers and item_tags in the lane's scope and a fixture that omits
 * them would let the lane ship with those paths never executed.
 */
function manualLaneRelease(string $headline, string $url): array
{
    return [
        'kind' => 'release',
        'headline' => $headline,
        'facets' => [
            'f_link' => ['url' => $url],
            'f_authored' => ['creator' => 'The Owner'],
        ],
        'media' => [['role' => 'cover', 'url' => 'https://cdn.test/'.sha1($url).'.jpg']],
        // 'from' is one of the seven values offers_qualifier_check admits
        // (20260727140000_content_schema.sql:394-395). Verified against the
        // DDL, not against a green SQLite suite — spec §10's testing note.
        'offers' => [['channel' => 'base', 'amount_minor' => 2500, 'currency' => 'AUD', 'qualifier' => 'from']],
        'tags' => [['tag' => 'owner-authored', 'tag_type' => 'origin']],
    ];
}

it('lands an owner-authored item with identity keys, an anchor, and every facet family', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;

    $itemId = app(ProjectionWriter::class)->writeManualItem(
        $userId,
        'manual:'.Str::uuid(),
        manualLaneRelease('My own album', 'https://example.test/mine'),
    );

    $sourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');

    $sourceItem = DB::table('content.source_items')->where('source_id', $sourceId)->first();
    expect($sourceItem)->not->toBeNull()
        ->and($sourceItem->item_id)->toBe($itemId)
        ->and($sourceItem->kind)->toBe('release')
        // No stream and no record key: there is no ingest run behind a
        // hand-authored row, and retireAbsentSourceItems() filters on a
        // concrete stream_id, so a null one can never be retired by a
        // connector pass.
        ->and($sourceItem->stream_id)->toBeNull()
        ->and($sourceItem->record_key)->toBeNull();

    // The two keys ProjectionWriter writes for any record. CanonicalUrl is
    // the joining key that lets this fold into a synced item later — its
    // absence is precisely what broke the old hand-rolled writer.
    $keys = DB::table('content.identity_keys')
        ->where('source_item_id', $sourceItem->id)
        ->pluck('key_class')->sort()->values()->all();
    expect($keys)->toBe(['canonical_url', 'platform_object']);

    expect(DB::table('content.item_anchors')
        ->where('user_id', $userId)->where('coord', $sourceItem->coord)->value('item_id'))->toBe($itemId);

    expect(DB::table('content.f_text')->where('item_id', $itemId)->where('source_id', $sourceId)->value('headline'))
        ->toBe('My own album')
        ->and(DB::table('content.f_link')->where('item_id', $itemId)->where('source_id', $sourceId)->value('url'))
        ->toBe('https://example.test/mine')
        ->and(DB::table('content.f_authored')->where('item_id', $itemId)->value('creator'))->toBe('The Owner')
        ->and(DB::table('content.item_media')->where('item_id', $itemId)->where('role', 'cover')->count())->toBe(1)
        ->and(DB::table('content.offers')->where('item_id', $itemId)->where('source_id', $sourceId)->value('qualifier'))
        ->toBe('from')
        ->and((int) DB::table('content.offers')->where('item_id', $itemId)->value('amount_minor'))->toBe(2500)
        ->and(DB::table('content.item_tags')->where('item_id', $itemId)->where('source_id', $sourceId)->value('tag'))
        ->toBe('owner-authored');

    expect(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('My own album');
});

it('is idempotent on the coord, so a backfill can be re-run', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $coord = 'manual:'.Str::uuid();
    $writer = app(ProjectionWriter::class);

    $first = $writer->writeManualItem($userId, $coord, manualLaneRelease('Draft title', 'https://example.test/mine'));
    $second = $writer->writeManualItem($userId, $coord, manualLaneRelease('Corrected title', 'https://example.test/mine'));

    expect($second)->toBe($first)
        ->and(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->count())->toBe(1)
        // A re-run overwrites the value rather than appending a second row:
        // f_text is a singleton keyed (item_id, source_id), and the
        // collection facets are replaced wholesale per (item, source).
        ->and(DB::table('content.f_text')->where('item_id', $first)->value('headline'))->toBe('Corrected title')
        ->and(DB::table('content.f_text')->where('item_id', $first)->count())->toBe(1)
        ->and(DB::table('content.offers')->where('item_id', $first)->count())->toBe(1)
        ->and(DB::table('content.item_tags')->where('item_id', $first)->count())->toBe(1);
});

it('bumps the site build state so the public document rebuilds', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $siteId = (string) DB::table('site.sites')->where('user_id', $userId)->value('id');

    app(ProjectionWriter::class)->writeManualItem(
        $userId,
        'manual:'.Str::uuid(),
        manualLaneRelease('My own album', 'https://example.test/mine'),
    );

    expect((int) DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBeGreaterThan(0);
});

/** A bandcamp connection with its ingest source/stream and one landed release doc. */
function manualLaneBandcamp(string $userId, string $key, string $title, string $url): array
{
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://'.Str::lower(Str::random(8)).'.bandcamp.com'],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'releases',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $doc = ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'type' => 'album'];
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId, 'last_seen_at' => now(),
    ]);

    return [$source, $streamId];
}

it('keeps the owner-authored item when a connector run merges it with a synced one', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $album = 'https://artist.bandcamp.com/album/first';
    $writer = app(ProjectionWriter::class);

    // 1. The connector lands first.
    [$source, $streamId] = manualLaneBandcamp($userId, 'album/first', 'First Album', $album);
    $writer->projectStream($source, $streamId, 'releases');
    $syncedItemId = (string) DB::table('content.items')->where('user_id', $userId)->value('id');

    // Age the synced anchor explicitly. Laravel binds timestamps at SECOND
    // precision, so without this the two anchors tie on bound_at and
    // bindGroup()'s orderBy has no tiebreak — the pre-fix run would pass
    // spuriously about half the time and the post-fix assertion would prove
    // nothing. The synced side MUST be strictly older, because "oldest
    // binding wins" is exactly the rule this task overrides.
    DB::table('content.item_anchors')->where('user_id', $userId)->update(['bound_at' => now()->subHour()]);

    // 2. The owner hand-adds something with a DIFFERENT url, so it gets its
    //    own item — nothing unions the two yet.
    $coord = 'manual:'.Str::uuid();
    $ownerItemId = $writer->writeManualItem($userId, $coord, manualLaneRelease('The owner name', 'https://example.test/mine'));
    expect($ownerItemId)->not->toBe($syncedItemId);

    // 3. The owner corrects the link to the one the connector already carries.
    //    Both coords are now anchored to DIFFERENT items and share a
    //    CanonicalUrl — the only union mechanism ProjectionWriter writes, and
    //    the shape that reaches mergeInto()'s DELETE. The two coords sit on
    //    DIFFERENT sources, so the key is not poisoned.
    $resolved = $writer->writeManualItem($userId, $coord, manualLaneRelease('The owner name', $album));

    // The owner's row is the survivor, not the scrape's.
    expect($resolved)->toBe($ownerItemId);

    // Asserted as the ITEM ROW, deliberately: the loss mechanism is a
    // Postgres FK cascade off items.id, and the SQLite stand-ins declare no
    // foreign keys, so asserting "the facets survived" would pass vacuously
    // under `composer test` even with the bug present. "The row was never
    // deleted" is driver-independent and is what stops the cascade firing.
    expect(DB::table('content.items')->where('id', $ownerItemId)->exists())->toBeTrue();

    // Both coords now point at the owner's item.
    expect(DB::table('content.source_items')->where('item_id', $ownerItemId)->count())->toBe(2);

    // And the owner's own words are still there, at a priority that wins.
    $manualSourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');
    expect(DB::table('content.f_text')->where('item_id', $ownerItemId)->where('source_id', $manualSourceId)->value('headline'))
        ->toBe('The owner name')
        ->and(DB::table('content.items')->where('id', $ownerItemId)->value('headline_cache'))->toBe('The owner name');
});

it('still merges two connector items on the oldest binding when no owner row is involved', function () {
    // The owner preference must not change the survivor when nothing is
    // owner-authored — otherwise it is not a narrow addition, it is a rewrite
    // of merge semantics.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);
    $shared = 'https://artist.bandcamp.com/album/shared';

    [$sourceA, $streamA] = manualLaneBandcamp($userId, 'album/a', 'Album A', 'https://artist.bandcamp.com/album/a');
    $writer->projectStream($sourceA, $streamA, 'releases');
    $firstItemId = (string) DB::table('content.items')->where('user_id', $userId)->value('id');
    // Same second-precision hazard as above: force A's anchor strictly older.
    DB::table('content.item_anchors')->where('user_id', $userId)->update(['bound_at' => now()->subHour()]);

    [$sourceB, $streamB] = manualLaneBandcamp($userId, 'album/b', 'Album B', $shared);
    $writer->projectStream($sourceB, $streamB, 'releases');

    // Repoint A's record at B's url so the two coords union.
    DB::table('ingest.record_versions')->where('stream_id', $streamA)->update([
        'doc' => json_encode(['title' => 'Album A', 'url' => $shared, 'artist' => 'Some Artist', 'type' => 'album']),
    ]);
    $writer->projectStream($sourceA, $streamA, 'releases');

    // Oldest anchor still wins: the first-projected item is the survivor.
    expect(DB::table('content.source_items')->where('item_id', $firstItemId)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// The one-coord-per-url constraint (spec §1.7, plan Task 4).
//
// NOT a bug to fix here — a deliberate Resolver property (poisonedKeys(): a
// value one source contributes twice identifies nothing), colliding with
// "exactly one manual source per user". Pinned so slices 3/4/5 read it as a
// constraint on how a backfiller mints coords, and so nobody "fixes"
// poisonedKeys() without seeing what it protects.
//
// THE RULE: AT MOST ONE MANUAL COORD PER CANONICAL URL PER USER.
//
// Both orderings are pinned because they do NOT cost the same, and the plan
// that specified this task predicted the wrong number by testing only the
// cheaper one. The pure Resolver does return three separate GROUPS in both
// cases — that much the plan verified correctly — but a group is not an item.
// content.item_anchors is sticky: a coord that already has an anchor rebinds
// to it, so coords that converged BEFORE the poisoning stay converged. Only a
// coord with no anchor yet mints a fresh item.
// ---------------------------------------------------------------------------

it('poisons a url on the second manual coord, which then cannot fold in', function () {
    // ORDERING A — connector first, then two hand-adds. The already-converged
    // pair is protected by its anchors, so the cost is bounded: the second
    // manual coord simply strands as its own item instead of folding.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $album = 'https://artist.bandcamp.com/album/first';
    $writer = app(ProjectionWriter::class);

    [$source, $streamId] = manualLaneBandcamp($userId, 'album/first', 'First Album', $album);
    $writer->projectStream($source, $streamId, 'releases');

    // One manual coord on that url folds into the synced item — the good case.
    $folded = $writer->writeManualItem($userId, 'manual:'.Str::uuid(), manualLaneRelease('Mine', $album));
    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->where('item_id', $folded)->count())->toBe(2);

    // A SECOND manual coord on the same url poisons it. The connector coord
    // and the first manual coord keep their existing anchor and stay on one
    // item; the new coord has no anchor and mints its own. Two items, not one
    // — and had the url not been poisoned this would still be one.
    $stranded = $writer->writeManualItem($userId, 'manual:'.Str::uuid(), manualLaneRelease('Mine again', $album));

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(2)
        ->and($stranded)->not->toBe($folded)
        ->and(DB::table('content.source_items')->where('item_id', $stranded)->count())->toBe(1);

    // The damage is scoped to identity, not to data: every coord still has an
    // item and no row was destroyed. That is what makes the caller-side rule
    // (one coord per url) a sufficient remedy.
    expect(DB::table('content.source_items')->whereNull('item_id')->count())->toBe(0);
});

it('poisons a url before the connector arrives, so it never converges at all', function () {
    // ORDERING B — the one a backfiller actually hits: legacy rows land first,
    // the connector runs afterwards. No anchor exists to protect anything, so
    // the url is already poisoned when the connector's coord shows up and it
    // never folds. Three items for one real-world thing, permanently.
    //
    // This is the case that makes the rule load-bearing rather than tidy, and
    // it is why slices 3, 4 and 5 must dedupe by canonical url BEFORE writing,
    // not rely on a later run to reconcile.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $album = 'https://artist.bandcamp.com/album/first';
    $writer = app(ProjectionWriter::class);

    $writer->writeManualItem($userId, 'manual:'.Str::uuid(), manualLaneRelease('Legacy row A', $album));
    $writer->writeManualItem($userId, 'manual:'.Str::uuid(), manualLaneRelease('Legacy row B', $album));

    [$source, $streamId] = manualLaneBandcamp($userId, 'album/first', 'First Album', $album);
    $writer->projectStream($source, $streamId, 'releases');

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(3);

    // Every coord is on its own item — the connector included. Nothing joined.
    $perItem = DB::table('content.source_items')
        ->selectRaw('item_id, count(*) as c')->groupBy('item_id')->pluck('c')->all();
    expect($perItem)->toBe([1, 1, 1]);
});
