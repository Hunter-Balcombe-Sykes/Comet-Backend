<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #PRIV-5: content.* URL columns have no canonicaliser stripping tracking
// params ahead of them (unlike routing.* canonical_url), so ProjectionWriter
// must minimise every URL_COLUMNS entry itself before persisting. Distinct
// helper names from ProjectionWriterTest.php/ProjectionWriterBatchingTest.php
// on purpose — Pest under --parallel does not guarantee both files share a
// worker process, so this file is self-contained.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    // Every connector runs eagerly on connect (F21, 2026-08-18; paid ones by
    // opt-in) — under the sync test queue the observer would run the connector
    // inline and mint the stream row this file's helpers insert by hand. Keep
    // the eager run out; the projection paths under test are exercised directly.
    Bus::fake([RunSourceJob::class]);
});

const PUM_URL = 'https://x.com/p?utm_content=jane%40example.com&token=eyJa.eyJb.c&id=42';
const PUM_MINIMISED = 'https://x.com/p?utm_content=[redacted]&token=[redacted]&id=42';

/** @return array{0: string, 1: array<string, mixed>, 1: string} userId, ingest.sources row, streamId — a bandcamp "releases" stream. */
function pumBandcampStream(string $userId): array
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

    return [$source, $streamId];
}

/**
 * @return array{0: array<string, mixed>, 1: string} ingest.sources row + streamId — an eventbrite "events" stream.
 *
 * Was a gumroad "products" stream until Phase 1 de-sourced Gumroad. The
 * behaviour under test is ProjectionWriter's, not any one projector's — the
 * minimisation happens at the single `SecretParams::minimiseUrl($offer['url'])`
 * call — so it just needs any live lane whose projection carries an offer with
 * a url. Eventbrite is that lane.
 */
function pumEventbriteStream(string $userId): array
{
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://www.eventbrite.com/o/'.Str::lower(Str::random(8))],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'events',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$source, $streamId];
}

function pumLand(string $streamId, string $key, array $doc): void
{
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId,
        'last_seen_at' => now(),
    ]);
}

it('minimises f_link.url and the media source_url, keeping the load-bearing id param', function () {
    $userId = createTenant('pum-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = pumBandcampStream($userId);

    pumLand($streamId, 'album/one', [
        'title' => 'Only Album',
        'url' => PUM_URL,
        'artist' => 'Some Artist',
        'release_date' => '2025-05-05',
        'art_url' => 'https://f4.bcbits.com/img/a1.jpg?utm_source=newsletter&sig=deadbeef',
        'type' => 'album',
    ]);

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');
    expect($result['status'])->toBe('ok');

    $item = DB::table('content.items')->where('user_id', $userId)->first();
    expect(DB::table('content.f_link')->where('item_id', $item->id)->value('url'))->toBe(PUM_MINIMISED);

    $asset = DB::table('content.media_assets')->where('user_id', $userId)->first();
    expect($asset)->not->toBeNull()
        ->and($asset->source_url)->toBe('https://f4.bcbits.com/img/a1.jpg?utm_source=[redacted]&sig=[redacted]')
        ->and($asset->fingerprint)->toBe('url-'.sha1($asset->source_url));

    // Re-run: the fingerprint is derived from the MINIMISED url both times,
    // so this must dedupe onto the same row rather than mint a second one.
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');
    expect(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(1);
});

it('minimises content.offers.url the same way as f_link.url', function () {
    $userId = createTenant('pum-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = pumEventbriteStream($userId);

    pumLand($streamId, 'events/1', [
        'name' => 'An Event',
        'url' => PUM_URL,
        'startDate' => '2026-09-01T18:00:00+10:00',
        'price_min' => 5.0,
        'currency' => 'AUD',
    ]);

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'events');
    expect($result['status'])->toBe('ok');

    $item = DB::table('content.items')->where('user_id', $userId)->first();
    expect(DB::table('content.offers')->where('item_id', $item->id)->value('url'))->toBe(PUM_MINIMISED);
});

it('minimises content.f_review.author_photo_url via the URL_COLUMNS denylist in upsertSingletonFacet', function () {
    $userId = createTenant('pum-'.Str::lower(Str::random(6)))->id;

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

    $writer = app(ProjectionWriter::class);
    $method = new ReflectionMethod($writer, 'upsertSingletonFacet');
    $method->setAccessible(true);
    $method->invoke($writer, $itemId, $sourceId, 'f_review', [
        'author_name' => 'Jane',
        'author_photo_url' => 'https://lh3.googleusercontent.com/a/photo.jpg?sz=128&sig=deadbeef',
        'rating' => 5.0,
    ]);

    expect(DB::table('content.f_review')->where('item_id', $itemId)->value('author_photo_url'))
        ->toBe('https://lh3.googleusercontent.com/a/photo.jpg?sz=128&sig=[redacted]');
});

it('leaves a non-URL_COLUMNS singleton-facet column verbatim — the denylist protects everything not listed', function () {
    $userId = createTenant('pum-'.Str::lower(Str::random(6)))->id;
    [$source, $streamId] = pumBandcampStream($userId);

    // f_authored.creator is not in URL_COLUMNS; a value that happens to look
    // query-string-shaped must survive untouched.
    pumLand($streamId, 'album/two', [
        'title' => 'Second Album',
        'url' => 'https://artist.bandcamp.com/album/two',
        'artist' => 'Some Artist?utm_content=jane@example.com',
        'release_date' => '2025-05-05',
        'art_url' => 'https://f4.bcbits.com/img/a1.jpg',
        'type' => 'album',
    ]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    $item = DB::table('content.items')->where('user_id', $userId)->first();
    expect(DB::table('content.f_authored')->where('item_id', $item->id)->value('creator'))
        ->toBe('Some Artist?utm_content=jane@example.com');
});
