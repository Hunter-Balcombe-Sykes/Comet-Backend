<?php

// shop:backfill-favicons — retro-fit the favicon onto stores connected before
// StoreBrandSeeder started fetching one.
//
// Nothing re-runs for an already-connected store, so every Shopify/Big Cartel
// storefront placed before 2026-08-26 keeps a NULL favicon_url forever — which
// blanks the Platforms table icon, not just the suggestion card.
//
// The store is written DIRECTLY rather than seeded through a probe. Two
// reasons: it isolates the command from the probe cascade, and Http::fake()
// MERGES stubs rather than replacing them, so a seed-then-refake test would
// keep the seed catch-all 404 and silently 404 the very homepage the backfill
// is supposed to read.

use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Cache::flush();
});

/** A connected store carrying neither a favicon nor a logo. */
function storeMissingIcon(string $handle): object
{
    $pro = createTenant($handle);
    app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: '4242',
        provider: 'shopify',
        name: 'The Store',
        position: 0,
        url: 'https://example.com',
        sourceUrl: 'https://example.com/',
        currency: 'AUD',
        isIndividual: false,
    ), (string) $pro->id);

    expect(app(ShopConnections::class)->store($pro, '4242')?->faviconUrl)->toBeNull();

    return $pro;
}

function faviconPageResponds(string $head): void
{
    Http::fake([
        'https://example.com/' => Http::response("<html><head>{$head}</head><body>x</body></html>", 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);
}

it('backfills a favicon onto a store that has none', function () {
    $pro = storeMissingIcon('backfill-basic');
    faviconPageResponds('<link rel="icon" type="image/png" sizes="32x32" href="/icon-32.png">');

    $this->artisan('shop:backfill-favicons')->assertSuccessful();

    expect(app(ShopConnections::class)->store($pro, '4242')?->faviconUrl)->toBe('https://example.com/icon-32.png');
});

it('writes nothing under --dry-run', function () {
    $pro = storeMissingIcon('backfill-dry');
    faviconPageResponds('<link rel="icon" href="/icon.png">');

    $this->artisan('shop:backfill-favicons', ['--dry-run' => true])->assertSuccessful();

    expect(app(ShopConnections::class)->store($pro, '4242')?->faviconUrl)->toBeNull();
});

it('leaves a store alone when its homepage still offers no icon', function () {
    // An honest miss must not be written back as an empty string, which would
    // then read as "already has one" and permanently block a later run that
    // would have succeeded.
    $pro = storeMissingIcon('backfill-miss');
    faviconPageResponds('');

    $this->artisan('shop:backfill-favicons')->assertSuccessful();

    expect(app(ShopConnections::class)->store($pro, '4242')?->faviconUrl)->toBeNull();
});

it('skips a store that already has a logo, since that is what renders', function () {
    $pro = createTenant('backfill-haslogo');
    app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: '5353',
        provider: 'shopify',
        name: 'Logo Store',
        position: 0,
        url: 'https://example.com',
        sourceUrl: 'https://example.com/',
        isIndividual: false,
        logoUrl: 'https://example.com/logo.png',
    ), (string) $pro->id);
    faviconPageResponds('<link rel="icon" href="/icon.png">');

    $this->artisan('shop:backfill-favicons')->assertSuccessful();

    expect(app(ShopConnections::class)->store($pro, '5353')?->faviconUrl)->toBeNull();
});

it('counts only real storefronts as candidates, not every storefronts sidecar row', function () {
    // content.storefronts is a sidecar on content.collections, and ordering
    // platforms (Uber Eats, DoorDash) carry one too. ShopConnections'
    // storeQuery() filters c.kind = 'storefront', so storeByCollection()
    // correctly refuses those — but the candidate query did not, so they were
    // counted and then dropped with no line of output.
    //
    // Live on dev 2026-08-28: "Candidates 33" where only 12 were shops. The 21
    // order_platform rows vanished silently, which reads as coverage that did
    // not happen (CLAUDE.md: no silent caps).
    $pro = storeMissingIcon('backfill-kind');

    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $pro->id, 'label' => 'Uber Eats',
        'kind' => 'order_platform', 'position' => 1, 'is_user_created' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.storefronts')->insert([
        'collection_id' => $collectionId, 'provider' => 'uber_eats',
        'url' => 'https://example.com', 'source_url' => 'https://example.com/',
        'referral_query' => '', 'is_individual' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    faviconPageResponds('<link rel="icon" href="/icon.png">');

    // One candidate, not two — and the numbers account for it.
    $this->artisan('shop:backfill-favicons')
        ->expectsOutputToContain('Candidates 1;')
        ->assertSuccessful();
});
