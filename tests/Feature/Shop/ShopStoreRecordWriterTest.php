<?php

use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 7 Task 24: ShopContentWriter's identity anchor is content.* data, NOT
// the site.shop_brands Eloquent model. These tests exercise the writer with a
// hand-built StoreRecord and ZERO legacy rows in existence — the state the
// codebase is in the moment 20260817000900_drop_site_shop_brands.sql lands.
//
// Helpers are prefixed `ssrw*`: cross-file helper name collisions are fatal
// under --parallel in this repo (PHP has no per-file function scope).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

/** A storefront record with no legacy row behind it — every field supplied by the caller. */
function ssrwRecord(array $overrides = []): StoreRecord
{
    return new StoreRecord(...array_merge([
        'externalRef' => 'store-'.Str::lower(Str::random(8)),
        'provider' => 'shopify',
        'name' => 'Fear No Evil',
        'position' => 3,
        'url' => 'https://fearnoevil.test',
        'sourceUrl' => 'https://fearnoevil.test/collections/all',
        'currency' => 'AUD',
        'discountCode' => 'TENOFF',
        'referralQuery' => 'ref=partna',
        'isIndividual' => false,
        'fetchMode' => 'client',
        'connectStatus' => 'pending',
        'connectError' => null,
        'logoUrl' => 'https://cdn.test/logo.png',
        'faviconUrl' => 'https://cdn.test/favicon.ico',
        'logoMarkUrl' => null,
        'logoMarkSvgUrl' => null,
        'productsCuratedAt' => null,
    ], $overrides));
}

it('upsertStore writes the storefront with no site.shop_brands row in existence', function () {
    $user = ssrwUser();
    $record = ssrwRecord();

    $collectionId = app(ShopContentWriter::class)->upsertStore($record, (string) $user->id);

    $collection = DB::table('content.collections')->where('id', $collectionId)->first();
    $storefront = DB::table('content.storefronts')->where('collection_id', $collectionId)->first();

    expect(ShopBrand::count())->toBe(0)
        ->and($collection->label)->toBe('Fear No Evil')
        ->and($collection->kind)->toBe('storefront')
        ->and((int) $collection->position)->toBe(3)
        ->and($storefront->provider)->toBe('shopify')
        ->and($storefront->external_ref)->toBe($record->externalRef)
        ->and($storefront->url)->toBe('https://fearnoevil.test')
        ->and($storefront->source_url)->toBe('https://fearnoevil.test/collections/all')
        ->and($storefront->currency)->toBe('AUD')
        ->and($storefront->discount_code)->toBe('TENOFF')
        ->and($storefront->referral_query)->toBe('ref=partna')
        ->and((bool) $storefront->is_individual)->toBeFalse()
        ->and($storefront->fetch_mode)->toBe('client')
        ->and($storefront->connect_status)->toBe('pending')
        ->and($storefront->logo_url)->toBe('https://cdn.test/logo.png')
        ->and($storefront->favicon_url)->toBe('https://cdn.test/favicon.ico');
});

it('upsertStore issues no query against site.shop_brands', function () {
    $user = ssrwUser();

    $seen = [];
    DB::connection('pgsql')->listen(function ($query) use (&$seen) {
        $seen[] = $query->sql;
    });

    app(ShopContentWriter::class)->upsertStore(ssrwRecord(), (string) $user->id);

    expect($seen)->not->toBeEmpty()
        ->and(array_filter($seen, fn (string $sql) => str_contains($sql, 'shop_brands')))->toBe([]);
});

it('keys the store on provider + external_ref, so a rename reuses the same collection', function () {
    $user = ssrwUser();
    $writer = app(ShopContentWriter::class);
    $record = ssrwRecord();

    $first = $writer->upsertStore($record, (string) $user->id);

    // The rename hazard the docblock names: the display name is user-editable,
    // so it can never be the key. Rebuilt from content.* — the identity the
    // second call carries came out of the storefront row, not a legacy row.
    $reread = StoreRecord::fromStorefrontRow(
        DB::table('content.storefronts as s')
            ->join('content.collections as c', 'c.id', '=', 's.collection_id')
            ->where('s.collection_id', $first)
            ->first(['s.*', 'c.label', 'c.position']),
    );

    expect($reread->externalRef)->toBe($record->externalRef)
        ->and($reread->provider)->toBe($record->provider)
        ->and($reread->name)->toBe('Fear No Evil')
        ->and($reread->position)->toBe(3);

    $second = $writer->upsertStore(
        ssrwRecord(['externalRef' => $reread->externalRef, 'name' => 'Fear No Evil Supply Co.']),
        (string) $user->id,
    );

    expect($second)->toBe($first)
        ->and(DB::table('content.collections')->where('kind', 'storefront')->count())->toBe(1)
        ->and(DB::table('content.collections')->where('id', $first)->value('label'))
        ->toBe('Fear No Evil Supply Co.');
});

it('collectionIdFor and isCurated read the store off content.* alone', function () {
    $user = ssrwUser();
    $writer = app(ShopContentWriter::class);
    $record = ssrwRecord();

    expect($writer->collectionIdFor($record, (string) $user->id))->toBeNull()
        ->and($writer->isCurated($record, (string) $user->id))->toBeFalse();

    $collectionId = $writer->upsertStore($record, (string) $user->id);

    expect($writer->collectionIdFor($record, (string) $user->id))->toBe($collectionId)
        ->and($writer->isCurated($record, (string) $user->id))->toBeFalse();

    DB::table('content.storefronts')->where('collection_id', $collectionId)
        ->update(['products_curated_at' => now()]);

    expect($writer->isCurated($record, (string) $user->id))->toBeTrue();
});

it('syncStore reconciles a catalogue for a store that has no legacy row', function () {
    $user = ssrwUser();
    $writer = app(ShopContentWriter::class);
    $collectionId = $writer->upsertStore(ssrwRecord(), (string) $user->id);

    $written = $writer->syncStore((string) $user->id, $collectionId, [ssrwProduct('a'), ssrwProduct('b')], 'AUD');

    expect($written)->toBe(2)
        ->and(DB::table('content.collection_items')->where('collection_id', $collectionId)->count())->toBe(2);

    // Dropping one retires its item (never a hard delete) and leaves the other live.
    $writer->syncStore((string) $user->id, $collectionId, [ssrwProduct('a')], 'AUD');

    $retired = DB::table('content.items')->whereNotNull('removed_at')->count();
    expect($retired)->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(2);

    // §9.8 / slice 5b §3.3: re-adding it is an OWNER-authored write, so the
    // un-retire step clears removed_at for exactly the items it just linked.
    $writer->syncStore((string) $user->id, $collectionId, [ssrwProduct('a'), ssrwProduct('b')], 'AUD');

    expect(DB::table('content.items')->whereNotNull('removed_at')->count())->toBe(0);
});

/** @return array<string,mixed> */
function ssrwProduct(string $slug): array
{
    return [
        'productId' => "pid-{$slug}",
        'title' => "Product {$slug}",
        'url' => "https://fearnoevil.test/products/{$slug}",
        'price' => '10.00',
        'currency' => 'AUD',
        'available' => true,
        'image' => null,
        'images' => [],
        'variants' => [],
    ];
}

function ssrwUser(): User
{
    $handle = 'ssrw'.Str::lower(Str::random(8));

    return User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);
}
