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
use App\Services\Shop\StoreRecord;
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
        updated_at          timestamptz NOT NULL DEFAULT now(),
        -- Re-home Task 11 (20260819000100). The owner is denormalised onto
        -- this table because true store identity is
        -- (collections.user_id, provider, external_ref) and Postgres has no
        -- cross-table unique index — 20260813100001 named exactly this as
        -- the fix and deferred it.
        user_id             uuid        REFERENCES core.users(id) ON DELETE CASCADE,
        -- Re-home Task 13 pre-flight (20260819000120): the vocabulary carried
        -- over from shop_brands_connect_status_check before the DROP took the
        -- original away.
        CONSTRAINT storefronts_connect_status_check
            CHECK (connect_status IS NULL OR connect_status IN (\'pending\', \'failed\'))
    )');

    // Re-home Task 11 (20260819000110). PARTIAL on external_ref IS NOT NULL:
    // the column is nullable and Postgres treats NULLs as distinct, so a plain
    // unique index would silently permit unlimited (user_id, provider, NULL)
    // rows while LOOKING like it enforced identity. Not CONCURRENTLY here —
    // this lane runs inside a transaction, where CONCURRENTLY is illegal; the
    // real migration builds it concurrently because content.storefronts is on
    // the live public read path.
    $pg->statement('CREATE UNIQUE INDEX storefronts_user_provider_ref_uq
        ON content.storefronts (user_id, provider, external_ref)
        WHERE external_ref IS NOT NULL');
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

/** A StoreRecord for the identity tests below — provider and external_ref are the identity. */
function shopUpsertConflictStoreRecord(string $provider, string $externalRef): StoreRecord
{
    return new StoreRecord(
        externalRef: $externalRef,
        provider: $provider,
        name: 'Test Store',
        url: 'https://store.test',
        sourceUrl: 'https://store.test',
        currency: 'AUD',
        discountCode: '',
    );
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

// ── Re-home Task 11: store identity is enforced by the DATABASE ──────────
//
// Spec §6. Until now upsertStore()'s application-level collectionIdFor()
// lookup was the ONLY thing standing between two concurrent writers and a
// duplicated store — read-then-write, with no constraint behind it. That is
// the same fault that minted 18 collections for 9 stores during slice 5a.
//
// This lane, not the SQLite suite, because a partial unique index over a
// nullable column behaves differently on the two engines and the SQLite
// mirror cannot reproduce a real 23505.

it('refuses a duplicate store identity under a second concurrent writer', function (): void {
    $userId = shopUpsertConflictTestUser();

    // Writer one wins the race and lands the store.
    $collectionId = app(ShopContentWriter::class)->upsertStore(
        shopUpsertConflictStoreRecord('shopify', 'alpha'),
        $userId,
    );
    expect($collectionId)->not->toBeEmpty();

    // Writer two got a null from collectionIdFor() before writer one committed,
    // so it is about to INSERT a SECOND collection for the same store. Before
    // Task 11 this succeeded and the user had the store twice.
    $secondCollectionId = (string) Str::uuid();
    DB::connection('pgsql')->table('content.collections')->insert([
        'id' => $secondCollectionId, 'user_id' => $userId,
        'label' => 'alpha', 'kind' => 'storefront', 'position' => 0,
    ]);

    // Pin the REFUSAL REASON, not merely that something threw: a test asserting
    // only QueryException passes on a typo in the table name.
    expect(fn () => DB::connection('pgsql')->table('content.storefronts')->insert([
        'collection_id' => $secondCollectionId,
        'user_id' => $userId,
        'provider' => 'shopify',
        'external_ref' => 'alpha',
    ]))->toThrow(QueryException::class, 'storefronts_user_provider_ref_uq');
});

it('still allows the same external_ref for a different owner and a different provider', function (): void {
    // The index is (user_id, provider, external_ref) — narrowing it to
    // (provider, external_ref) would be a cross-tenant collision, and five real
    // dev users share the Shopify store id 75102060779 today.
    $userA = shopUpsertConflictTestUser();
    $userB = shopUpsertConflictTestUser();

    $writer = app(ShopContentWriter::class);

    expect($writer->upsertStore(shopUpsertConflictStoreRecord('shopify', '75102060779'), $userA))->not->toBeEmpty()
        ->and($writer->upsertStore(shopUpsertConflictStoreRecord('shopify', '75102060779'), $userB))->not->toBeEmpty()
        ->and($writer->upsertStore(shopUpsertConflictStoreRecord('woocommerce', '75102060779'), $userA))->not->toBeEmpty();

    expect(DB::connection('pgsql')->table('content.storefronts')->count())->toBe(3);
});

it('writes the denormalised owner onto every storefront it upserts', function (): void {
    $userId = shopUpsertConflictTestUser();

    $collectionId = app(ShopContentWriter::class)->upsertStore(shopUpsertConflictStoreRecord('shopify', 'alpha'), $userId);

    expect(DB::connection('pgsql')->table('content.storefronts')
        ->where('collection_id', $collectionId)->value('user_id'))->toBe($userId);
});

// ── The connect_status vocabulary, carried before the DROP ───────────────
//
// site.shop_brands enforced NULL | 'pending' | 'failed' in the DATABASE
// (shop_brands_connect_status_check). content.storefronts declared the column
// bare text, so that guarantee did not come across with the data —
// 20260819000120 carries it, and this pins it before the DROP removes anything
// to compare against.
//
// It matters on the wire, not just in the schema:
// PublicIntegrationConnectionResource rejects only 'pending', so a third value
// reaching the column would render publicly as though the store had connected.

it('refuses a connect_status outside the vocabulary', function (): void {
    $userId = shopUpsertConflictTestUser();
    $collectionId = app(ShopContentWriter::class)->upsertStore(
        shopUpsertConflictStoreRecord('shopify', 'alpha'),
        $userId,
    );

    // Pin the REFUSAL REASON, not merely that something threw.
    expect(fn () => DB::connection('pgsql')->table('content.storefronts')
        ->where('collection_id', $collectionId)
        ->update(['connect_status' => 'connected']))
        ->toThrow(QueryException::class, 'storefronts_connect_status_check');
});

it('accepts every value the vocabulary does allow', function (): void {
    // The negative test above passes just as well against a constraint that
    // refuses EVERYTHING, so pin the accepted set too.
    $userId = shopUpsertConflictTestUser();
    $collectionId = app(ShopContentWriter::class)->upsertStore(
        shopUpsertConflictStoreRecord('shopify', 'alpha'),
        $userId,
    );

    foreach (['pending', 'failed', null] as $status) {
        DB::connection('pgsql')->table('content.storefronts')
            ->where('collection_id', $collectionId)
            ->update(['connect_status' => $status]);

        expect(DB::connection('pgsql')->table('content.storefronts')
            ->where('collection_id', $collectionId)->value('connect_status'))->toBe($status);
    }
});
