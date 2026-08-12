<?php

// Task 7 fix round 4, Finding 3 — the Postgres half of the round-3 CRITICAL.
//
// ShopContentWriter::cataloguesFor() emits a product's `createdAt` from
// content.f_published.published_from (falling back to content.items
// .first_seen_at). Round 2 shipped a RAW PASS-THROUGH of that column, which
// byte-matched the fixture only because the SQLite stand-in stores
// published_from as bare TEXT and hands back the literal string. Round 3
// replaced it with Carbon::parse($raw)->utc()->toIso8601String(). Both the
// bug and the fix are invisible to the SQLite lane, so a regression back to
// the pass-through would still pass `composer test`. This file is the pin,
// against a REAL timestamptz column on a real Postgres server.
//
// Two distinct facts, measured against this lane's own server before being
// asserted (psql, session TimeZone shown in the comment at each test):
//
//   1. Postgres NEVER hands back the original ISO-8601 string. A
//      timestamptz's text rendering is space-separated with a two-digit
//      offset — '2026-01-05 00:30:00+00' — whatever was inserted. A raw
//      pass-through therefore emits a non-ISO-8601 string on the wire; the
//      first test below fails on exactly that.
//   2. The rendering also tracks the SESSION's TimeZone, not UTC: the same
//      row reads '2026-01-05 10:30:00+10' under Australia/Brisbane. That is
//      what makes ->utc() load-bearing rather than decorative — without it,
//      Carbon formats whatever offset the session happened to hand over. The
//      second and third tests set the session zone explicitly and fail if
//      ->utc() is dropped from either branch of the read.
//
// Honest scope note: under the lane's DEFAULT (UTC) session, ->utc() is a
// no-op — the raw text already carries +00, so test 1 alone would NOT catch
// its removal. Test 1 catches the pass-through regression; tests 2 and 3
// catch the ->utc() removal. Both matter and neither substitutes for the
// other.
//
// Rows are inserted directly rather than through ProjectionWriter: the thing
// under test is the READ formatting, and routing the write through the full
// projection lane would need ~20 more canonical tables provisioned here for
// no added coverage of the line in question.
//
// LANE HYGIENE — why this file wraps everything in a rolled-back transaction,
// unlike its neighbours. content.* tables are SHARED fixtures in this lane:
// files DROP and CREATE prod-named tables against one disposable database, so
// whichever file runs first decides a table's shape and every later file
// inherits it (see the reference note behind SubdomainAliasCollisionTest's
// header). This file needs only ten narrow, read-shaped columns; leaving that
// minimal shape behind broke 25 sibling assertions in ProjectionWriterBatching
// Test and friends on the first run here — measured, not feared. Postgres DDL
// is fully transactional, so provisioning inside a transaction that afterEach
// always rolls back leaves the database byte-for-byte as this file found it,
// whatever a sibling had created. PostgresTestCase deliberately adds no
// transaction of its own (its subject is abort semantics); this one is opted
// into locally and is not that.

use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->beginTransaction();

    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');

    foreach ([
        'collection_items', 'f_link', 'f_catalog', 'f_text', 'f_published',
        'offers', 'item_media', 'media_assets', 'item_variants', 'items',
    ] as $table) {
        $pg->statement("DROP TABLE IF EXISTS content.{$table} CASCADE");
    }

    // Minimal, read-shaped stand-ins for exactly the ten tables
    // cataloguesFor() touches. Only the types that matter to this test are
    // faithful — published_from and first_seen_at are real timestamptz, which
    // is the whole point.
    $pg->statement('CREATE TABLE content.items (
        id uuid PRIMARY KEY, kind text NOT NULL, first_seen_at timestamptz
    )');
    $pg->statement('CREATE TABLE content.collection_items (
        collection_id uuid NOT NULL, item_id uuid NOT NULL, source_id uuid, position int NOT NULL
    )');
    $pg->statement('CREATE TABLE content.f_link (item_id uuid NOT NULL, url text)');
    $pg->statement('CREATE TABLE content.f_catalog (
        item_id uuid NOT NULL, sku text, handle text, vendor text, variant_ref text
    )');
    $pg->statement('CREATE TABLE content.f_text (item_id uuid NOT NULL, headline text, body text)');
    $pg->statement('CREATE TABLE content.f_published (item_id uuid NOT NULL, published_from timestamptz)');
    $pg->statement('CREATE TABLE content.offers (
        item_id uuid NOT NULL, variant_label text, amount_minor bigint, currency text, availability text
    )');
    $pg->statement('CREATE TABLE content.media_assets (id uuid PRIMARY KEY, source_url text)');
    $pg->statement('CREATE TABLE content.item_media (
        item_id uuid NOT NULL, asset_id uuid NOT NULL, role text NOT NULL, position int NOT NULL
    )');
    $pg->statement('CREATE TABLE content.item_variants (
        item_id uuid NOT NULL, label text, sku text, image_url text, position int NOT NULL
    )');
});

// Undoes both halves of beforeEach in one statement: the ten stand-in tables
// and — because a SET issued inside a transaction block is itself rolled back
// — the session TimeZone two of the tests below change. The explicit reset
// after it is belt-and-braces for the case where a test failed before its own
// SET was reached, so the value can never leak to a sibling file either way.
afterEach(function () {
    DB::connection('pgsql')->rollBack();
    DB::connection('pgsql')->statement("SET TIME ZONE 'UTC'");
});

/**
 * One product item in a fresh collection. $publishedFrom lands in
 * content.f_published; passing null leaves that table empty so the read
 * exercises the items.first_seen_at fallback branch instead.
 *
 * @return string the collection id
 */
function seedTimezoneProduct(?string $publishedFrom, string $firstSeenAt): string
{
    $pg = DB::connection('pgsql');
    $collectionId = (string) Str::uuid();
    $itemId = (string) Str::uuid();

    $pg->table('content.items')->insert([
        'id' => $itemId, 'kind' => 'product', 'first_seen_at' => $firstSeenAt,
    ]);
    $pg->table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => null, 'position' => 0,
    ]);
    $pg->table('content.f_catalog')->insert(['item_id' => $itemId, 'sku' => 'sq-1']);
    $pg->table('content.f_text')->insert(['item_id' => $itemId, 'headline' => 'Handmade Mug']);

    if ($publishedFrom !== null) {
        $pg->table('content.f_published')->insert([
            'item_id' => $itemId, 'published_from' => $publishedFrom,
        ]);
    }

    return $collectionId;
}

it('emits canonical UTC ISO-8601 for a +10:00 createdAt stored in a real timestamptz', function () {
    // Session TimeZone: the lane default (UTC). Postgres hands back
    // '2026-01-05 00:30:00+00' here — already the right INSTANT, but not an
    // ISO-8601 string, which is what a raw pass-through would emit.
    $collectionId = seedTimezoneProduct('2026-01-05T10:30:00+10:00', '2020-01-01T00:00:00Z');

    $catalogue = app(ShopContentWriter::class)->currentCatalogue($collectionId);

    // 10:30 +10:00 is 00:30 UTC — the same instant, canonically rendered.
    expect($catalogue)->toHaveCount(1)
        ->and($catalogue[0]['createdAt'])->toBe('2026-01-05T00:30:00+00:00');

    // And the guard on WHY this test exists: the driver's own text for that
    // column is NOT the emitted string, so a pass-through cannot pass here.
    $raw = (string) DB::connection('pgsql')->table('content.f_published')->value('published_from');
    expect($raw)->toBe('2026-01-05 00:30:00+00')
        ->and($raw)->not->toBe($catalogue[0]['createdAt']);
});

it('emits canonical UTC ISO-8601 even when the session TimeZone is not UTC', function () {
    // Measured: the SAME row reads '2026-01-05 10:30:00+10' under this
    // session zone. Carbon::parse(...)->toIso8601String() on that text
    // yields '2026-01-05T10:30:00+10:00' — the right instant, the wrong
    // rendering, and a DIFFERENT string from what the SQLite lane produces
    // for the identical item. ->utc() is what makes the two converge; drop
    // it from cataloguesFor() and this assertion fails.
    $collectionId = seedTimezoneProduct('2026-01-05T10:30:00+10:00', '2020-01-01T00:00:00Z');
    DB::connection('pgsql')->statement("SET TIME ZONE 'Australia/Brisbane'");

    expect((string) DB::connection('pgsql')->table('content.f_published')->value('published_from'))
        ->toBe('2026-01-05 10:30:00+10');

    $catalogue = app(ShopContentWriter::class)->currentCatalogue($collectionId);

    expect($catalogue[0]['createdAt'])->toBe('2026-01-05T00:30:00+00:00');
});

it('normalises the items.first_seen_at fallback the same way', function () {
    // Fix round 4, Finding 4: the fallback branch (no f_published row) had no
    // ->utc(), one line below the branch that got one in round 3 — same
    // column type, same defect. first_seen_at is a timestamptz too.
    $collectionId = seedTimezoneProduct(null, '2026-03-01T09:00:00+10:00');
    DB::connection('pgsql')->statement("SET TIME ZONE 'Australia/Brisbane'");

    $catalogue = app(ShopContentWriter::class)->currentCatalogue($collectionId);

    expect($catalogue[0]['createdAt'])->toBe('2026-02-28T23:00:00+00:00');
});
