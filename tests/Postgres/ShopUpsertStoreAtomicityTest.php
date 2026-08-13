<?php

// Hotfix regression pin (2026-08-12/13): the incident this file guards.
//
// ShopContentWriter::upsertStore() used to run its content.collections
// upsert and its content.storefronts upsert as two independent statements.
// A bug (since fixed, see ShopStorefrontUpsertConflictTest in this same
// directory) made the storefronts write throw SQLSTATE[42702] on Postgres —
// AFTER the collections write had already committed. All 9 real stores' own
// first-ever backfill hit this: 9 orphaned content.collections rows with no
// content.storefronts partner.
//
// It got worse on retry. collectionIdFor() finds an existing store by
// JOINing content.collections to content.storefronts — an orphan has no
// storefronts row to join to, so the lookup missed and the successful
// re-run minted 9 NEW collections instead of reusing the orphans. 9 orphans
// became 18 collections for 9 stores.
//
// This lane, not SQLite: transactional ROLLBACK on a failed statement is a
// real-driver behaviour. SQLite's own transaction semantics (and the
// TestCase pgsql→SQLite remap the default Feature lane uses, see
// tests/TestCase.php) are close enough for most things but this incident was
// itself only reachable on a real Postgres server — the SQLite mirror in
// tests/Feature/Shop/ShopContentWriterTest.php cannot reproduce it by
// construction, exactly as ShopStorefrontUpsertConflictTest's own header
// explains for the sibling bug. Proving the FIX needs the same real driver
// that exposed the bug.
//
// The forced failure: DROP one of the nullable columns upsertStore() writes
// unconditionally (logo_url) from content.storefronts before calling it.
// Every real column in the write list is legitimate — this is not a
// hand-invented constraint that doesn't exist in production (contrast: a
// synthetic CHECK would be dishonest here). Dropping a column that a real
// migration created and upsertStore() genuinely writes is the most faithful
// way this test can force a real Postgres statement-level error without
// mocking anything — the point is that a REAL database error rolls the pair
// back, not that a particular error shape does.
//
// LANE HYGIENE — mirrors ShopStorefrontUpsertConflictTest.php exactly:
// self-provisions core.users/content.collections/content.storefronts inside
// a transaction that afterEach always rolls back. content.* tables are
// SHARED fixtures across this lane (whichever file runs first decides a
// table's shape for every later file in the same run), so provisioning
// outside a rolled-back transaction is not safe here.

use App\Models\Core\Site\ShopBrand;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->beginTransaction();

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');

    foreach (['content.storefronts', 'content.collections', 'core.users'] as $table) {
        $pg->statement("DROP TABLE IF EXISTS {$table} CASCADE");
    }

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    // Identical shape to ShopStorefrontUpsertConflictTest.php — see that
    // file's own comment for the migration files this mirrors.
    //
    // external_ref/removed_at: slice 3b Task 1
    // (20260813090000_slice3b_collections_keys_and_selection_ref.sql). Added
    // here (not just to CollectionsUpsertConflictTest.php's own provision) so
    // an ordering fluke in this shared Postgres lane never leaves a narrower
    // content.collections behind for that file to inherit.
    $pg->statement('CREATE TABLE content.collections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        parent_id uuid REFERENCES content.collections(id) ON DELETE CASCADE,
        label text NOT NULL,
        kind text,
        external_ref text,
        removed_at timestamptz,
        position integer NOT NULL DEFAULT 0,
        is_user_created boolean NOT NULL DEFAULT false,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE content.storefronts (
        collection_id       uuid PRIMARY KEY REFERENCES content.collections(id) ON DELETE CASCADE,
        provider            text        NOT NULL,
        external_ref        text,
        url                 text,
        source_url          text,
        currency            text,
        discount_code       text,
        referral_query      text        NOT NULL DEFAULT \'\',
        is_individual       boolean     NOT NULL DEFAULT false,
        fetch_mode          text,
        connect_status      text,
        connect_error       text,
        products_curated_at timestamptz,
        logo_url            text,
        favicon_url         text,
        logo_mark_url       text,
        logo_mark_svg_url   text,
        created_at          timestamptz NOT NULL DEFAULT now(),
        updated_at          timestamptz NOT NULL DEFAULT now()
    )');
});

afterEach(function () {
    DB::connection('pgsql')->rollBack();
});

function atomicityTestBrand(string $externalRef): ShopBrand
{
    return new ShopBrand([
        'connection_id' => (string) Str::uuid(),
        'brand_id' => $externalRef,
        'provider' => 'shopify',
        'url' => 'https://store.test',
        'source_url' => 'https://store.test',
        'name' => 'Test Store',
        'currency' => 'AUD',
        'discount_code' => '',
        'referral_query' => '',
        'is_individual' => false,
        'position' => 0,
        'products_curated_at' => null,
    ]);
}

function atomicityTestUser(): string
{
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    return $userId;
}

it('rolls back the collections write when the storefronts write fails', function () {
    $userId = atomicityTestUser();
    $brand = atomicityTestBrand('store-atomic-1');

    // Force a genuine Postgres statement-level error on the SECOND write —
    // logo_url is a real, nullable column upsertStore() writes on every call.
    DB::connection('pgsql')->statement('ALTER TABLE content.storefronts DROP COLUMN logo_url');

    expect(fn () => app(ShopContentWriter::class)->upsertStore($brand, $userId))
        ->toThrow(QueryException::class);

    // The incident's actual failure mode: without the transaction, this
    // count would be 1 (the collections upsert had already committed before
    // the storefronts upsert threw). Proving 0 here is proving the fix.
    expect(DB::connection('pgsql')->table('content.collections')->count())->toBe(0)
        ->and(DB::connection('pgsql')->table('content.storefronts')->count())->toBe(0);
});

it('leaves both tables untouched, not just collections, on the same failure', function () {
    // Belt-and-braces on the assertion above: prove the pair is atomic in
    // BOTH directions, not merely that collections happens to end up empty
    // for some unrelated reason (e.g. a second bug that also blocks its own
    // write). Two stores: the first succeeds normally: the second's
    // storefronts write is forced to fail. Only the first must survive.
    $userId = atomicityTestUser();
    $goodBrand = atomicityTestBrand('store-atomic-good');
    $writer = app(ShopContentWriter::class);
    $goodCollectionId = $writer->upsertStore($goodBrand, $userId);

    DB::connection('pgsql')->statement('ALTER TABLE content.storefronts DROP COLUMN logo_url');

    $badBrand = atomicityTestBrand('store-atomic-bad');
    expect(fn () => $writer->upsertStore($badBrand, $userId))
        ->toThrow(QueryException::class);

    expect(DB::connection('pgsql')->table('content.collections')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('content.collections')->value('id'))->toBe($goodCollectionId)
        ->and(DB::connection('pgsql')->table('content.storefronts')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('content.storefronts')->value('collection_id'))->toBe($goodCollectionId);
});
