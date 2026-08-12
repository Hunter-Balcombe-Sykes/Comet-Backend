<?php

use App\Ingest\Projection\ProjectionWriter;
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
