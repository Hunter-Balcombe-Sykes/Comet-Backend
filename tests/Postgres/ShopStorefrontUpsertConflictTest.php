<?php

// Hotfix regression pin (2026-08-12/13): ShopContentWriter::upsertStore()'s
// content.storefronts ON CONFLICT DO UPDATE SET clause referenced a BARE
// `products_curated_at`, which Postgres cannot resolve between the target
// row and the special `excluded` pseudo-table — SQLSTATE[42702] "column
// reference \"products_curated_at\" is ambiguous". Postgres validates every
// column reference in an ON CONFLICT DO UPDATE statement at PARSE time,
// before it ever checks whether a row genuinely conflicts, so this threw on
// the very FIRST upsertStore() call for a brand-new row, not only on a
// second/conflicting one. All 9 real stores' first-ever backfill hit this on
// dev and wrote nothing.
//
// SQLite has no such ambiguity — it resolves a bare column reference inside
// ON CONFLICT DO UPDATE to the target row unconditionally (verified
// separately against SQLite directly: the bare form, a table-qualified form,
// and a schema.table-qualified form all succeed there) — so every test in
// the default (SQLite) suite passed while every real call against Postgres
// failed. upsertStore()'s conflict path was never otherwise exercised
// against a real Postgres server before this file: the SQLite mirror in
// tests/Feature/Shop/ShopContentWriterTest.php cannot reproduce a Postgres
// parse-time ambiguity error by construction.
//
// The fix qualifies the reference with the target table's own name —
// `storefronts.products_curated_at` — which is PostgreSQL's own documented
// ON CONFLICT idiom (see e.g. the DO UPDATE example in the INSERT docs: `SET
// dcount = distributors.dcount + 1`); no schema prefix is needed since an
// INSERT statement has exactly one target table in scope. Verified directly
// against this lane's own Postgres server (not assumed) that both the bare
// form throws 42702 and the table-qualified form does not, before this file
// was written.
//
// LANE HYGIENE — self-provisions core.users/content.collections/
// content.storefronts inside a transaction that afterEach always rolls
// back, mirroring ShopCatalogueCreatedAtTimezoneTest in this same directory:
// content.* tables are SHARED fixtures across this lane (whichever file
// runs first decides a table's shape for every later file in the same run —
// a narrowed stand-in left behind previously took this lane from 7 failures
// to 37), so provisioning outside a rolled-back transaction is not safe
// here.

use App\Models\Core\Site\ShopBrand;
use App\Services\Shop\ShopContentWriter;
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

    // Faithful to supabase/migrations/20260727140000_content_schema.sql's
    // content.collections — only the columns upsertStore() writes, but with
    // the real NOT NULL/FK shape so this table's ON CONFLICT behaves like
    // production's.
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

    // Faithful to supabase/migrations/20260813100000_create_content_storefronts.sql
    // + 20260813100001_content_storefronts_external_ref.sql — this IS the
    // table and the ON CONFLICT target the production failure hit.
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

/**
 * An in-memory ShopBrand — deliberately never persisted. upsertStore() only
 * reads attributes off the object it's handed; it never queries
 * site.shop_brands itself. Not saving it means this file doesn't need to
 * provision that table (or site.integration_connections) at all.
 */
function shopUpsertConflictTestBrand(string $externalRef): ShopBrand
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

function shopUpsertConflictTestUser(): string
{
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    return $userId;
}

it('upserts the same store twice against real Postgres without SQLSTATE 42702', function () {
    $userId = shopUpsertConflictTestUser();
    $brand = shopUpsertConflictTestBrand('store-1');
    $writer = app(ShopContentWriter::class);

    // The FIRST call already emits the full ON CONFLICT DO UPDATE statement
    // — Laravel's upsert() always builds it, whether or not a row actually
    // conflicts — and Postgres validates every column reference in that SET
    // clause at parse time. The bare-column bug threw here already, on a
    // brand-new row with nothing to conflict with, which is exactly why all
    // 9 real stores failed on their first-ever backfill rather than only on
    // a resync.
    $first = $writer->upsertStore($brand->toStoreRecord(), $userId);

    // The SECOND call is the genuine ON CONFLICT DO UPDATE path: same
    // provider+external_ref, so collectionIdFor() finds the existing row.
    $second = $writer->upsertStore($brand->toStoreRecord(), $userId);

    expect($second)->toBe($first)
        ->and(DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $first)->count())->toBe(1);
});

it('keeps an already-stamped products_curated_at when the incoming value is null', function () {
    $userId = shopUpsertConflictTestUser();
    $brand = shopUpsertConflictTestBrand('store-2'); // products_curated_at null
    $writer = app(ShopContentWriter::class);
    $collectionId = $writer->upsertStore($brand->toStoreRecord(), $userId);

    // Simulate Task 8 having stamped the content-side column directly,
    // independent of the (in this test, still-null) legacy column the
    // in-memory $brand represents.
    $stamp = now()->subMinute();
    DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $collectionId)
        ->update(['products_curated_at' => $stamp]);

    // A routine resync calls upsertStore() again with the SAME (still-null)
    // brand — this must NOT reset the stamp back to null. Getting the
    // COALESCE arguments the wrong way round (or losing the qualification
    // that made the statement runnable at all) reinstates exactly the sync-
    // clobbers-curation bug this column was written to prevent.
    $writer->upsertStore($brand->toStoreRecord(), $userId);

    $after = DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $collectionId)->value('products_curated_at');
    expect($after)->not->toBeNull();
});

it('fills a null products_curated_at from a non-null incoming value', function () {
    $userId = shopUpsertConflictTestUser();
    $brand = shopUpsertConflictTestBrand('store-3'); // products_curated_at null
    $writer = app(ShopContentWriter::class);
    $collectionId = $writer->upsertStore($brand->toStoreRecord(), $userId);

    expect(DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $collectionId)->value('products_curated_at'))
        ->toBeNull();

    // The row has never recorded a curation event — the incoming value must
    // be taken this time.
    $brand->products_curated_at = now();
    $writer->upsertStore($brand->toStoreRecord(), $userId);

    expect(DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $collectionId)->value('products_curated_at'))
        ->not->toBeNull();
});
