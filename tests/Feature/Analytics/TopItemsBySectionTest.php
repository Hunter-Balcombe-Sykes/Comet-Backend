<?php

use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Section-scoped item analytics: shop/booking/event sitepage sections all carry
// product_id/product_title on the same analytics.link_clicks table, distinguished
// by section_key. AnalyticsQueryService::topItemsBySection() (shared by
// topProducts()/topServices()/topEvents()) scopes each list to its own section
// keys so a services or events click never pollutes "top products" and vice versa.

beforeEach(function () {
    setupUsersTable();
    setupLinkClicksTable();

    $this->userId = (string) Str::uuid();

    $handle = 'top-items-test-pro';

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $this->userId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Top Items Test Pro',
        'first_name' => 'Top Items Test Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->from = Carbon::now()->subDays(7);
    $this->to = Carbon::now();
    $this->service = app(AnalyticsQueryService::class);
});

/**
 * Inserts a single analytics.link_clicks row with sane defaults, overridable
 * per test. Mirrors the columns set by the real v2 tracker (see
 * ClickV2AndSessionPingTest for the ingest-endpoint equivalent) but writes
 * straight to the table so each test can pin an exact section_key/product_id
 * combination without going through dedup/bot-filtering middleware.
 */
function insertLinkClick(string $userId, array $overrides = []): void
{
    DB::connection('pgsql')->table('analytics.link_clicks')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'occurred_at' => now()->subHours(1)->toDateTimeString(),
        'visitor_id' => (string) Str::uuid(),
        'url' => 'https://example.com/item',
        'product_id' => null,
        'product_title' => null,
        'section_key' => null,
        'created_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('topProducts() only returns clicks scoped to shop section keys', function () {
    insertLinkClick($this->userId, [
        'product_id' => 'black-tee',
        'product_title' => 'Black Tee',
        'section_key' => 'shop-products',
    ]);
    insertLinkClick($this->userId, [
        'product_id' => 'live-set',
        'product_title' => 'Live Set',
        'section_key' => 'shop-tracks',
    ]);
    // A booking click carrying product_id under 'book' — must NOT leak into topProducts().
    insertLinkClick($this->userId, [
        'product_id' => 'haircut-basic',
        'product_title' => 'Basic Haircut',
        'section_key' => 'book',
    ]);
    // An events click carrying product_id under 'events' — must NOT leak into topProducts().
    insertLinkClick($this->userId, [
        'product_id' => 'summer-workshop',
        'product_title' => 'Summer Workshop',
        'section_key' => 'events',
    ]);

    $products = $this->service->topProducts($this->userId, $this->from, $this->to);

    expect($products)->toHaveCount(2);
    $productIds = collect($products)->pluck('product_id')->all();
    expect($productIds)->toContain('black-tee', 'live-set');
    // One needle per call: toContain is variadic and `not` means "not ALL of
    // them", so a two-needle negation passes the moment either is absent.
    expect($productIds)->not->toContain('haircut-basic');
    expect($productIds)->not->toContain('summer-workshop');
});

it('topServices() surfaces clicks under book and services section keys, not shop or events', function () {
    insertLinkClick($this->userId, [
        'product_id' => 'haircut-basic',
        'product_title' => 'Basic Haircut',
        'section_key' => 'book',
    ]);
    insertLinkClick($this->userId, [
        'product_id' => 'colour-treatment',
        'product_title' => 'Colour Treatment',
        'section_key' => 'services',
    ]);
    // Shop click — must not leak into topServices().
    insertLinkClick($this->userId, [
        'product_id' => 'black-tee',
        'product_title' => 'Black Tee',
        'section_key' => 'shop-products',
    ]);
    // Events click — must not leak into topServices().
    insertLinkClick($this->userId, [
        'product_id' => 'summer-workshop',
        'product_title' => 'Summer Workshop',
        'section_key' => 'events',
    ]);

    $services = $this->service->topServices($this->userId, $this->from, $this->to);

    expect($services)->toHaveCount(2);
    $serviceIds = collect($services)->pluck('product_id')->all();
    expect($serviceIds)->toContain('haircut-basic', 'colour-treatment');
    // One needle per call: toContain is variadic and `not` means "not ALL of
    // them", so a two-needle negation passes the moment either is absent.
    expect($serviceIds)->not->toContain('black-tee');
    expect($serviceIds)->not->toContain('summer-workshop');
});

it('topEvents() surfaces clicks under events and attend section keys, not shop or book', function () {
    insertLinkClick($this->userId, [
        'product_id' => 'summer-workshop',
        'product_title' => 'Summer Workshop',
        'section_key' => 'events',
    ]);
    insertLinkClick($this->userId, [
        'product_id' => 'winter-retreat',
        'product_title' => 'Winter Retreat',
        'section_key' => 'attend',
    ]);
    // Booking click — must not leak into topEvents().
    insertLinkClick($this->userId, [
        'product_id' => 'haircut-basic',
        'product_title' => 'Basic Haircut',
        'section_key' => 'book',
    ]);
    // Shop click — must not leak into topEvents().
    insertLinkClick($this->userId, [
        'product_id' => 'black-tee',
        'product_title' => 'Black Tee',
        'section_key' => 'shop-products',
    ]);

    $events = $this->service->topEvents($this->userId, $this->from, $this->to);

    expect($events)->toHaveCount(2);
    $eventIds = collect($events)->pluck('product_id')->all();
    expect($eventIds)->toContain('summer-workshop', 'winter-retreat');
    // One needle per call: toContain is variadic and `not` means "not ALL of
    // them", so a two-needle negation passes the moment either is absent.
    expect($eventIds)->not->toContain('haircut-basic');
    expect($eventIds)->not->toContain('black-tee');
});

it('excludes rows with NULL section_key from every scoped list', function () {
    // A product_id row with no section_key at all (e.g. malformed/legacy) must
    // not surface anywhere — IN() scoping never matches NULL.
    insertLinkClick($this->userId, [
        'product_id' => 'orphan-item',
        'product_title' => 'Orphan Item',
        'section_key' => null,
    ]);

    expect($this->service->topProducts($this->userId, $this->from, $this->to))->toBe([]);
    expect($this->service->topServices($this->userId, $this->from, $this->to))->toBe([]);
    expect($this->service->topEvents($this->userId, $this->from, $this->to))->toBe([]);
});

it('returns the expected shape with clicks and unique_clickers counted correctly', function () {
    $visitorA = (string) Str::uuid();
    $visitorB = (string) Str::uuid();

    insertLinkClick($this->userId, [
        'product_id' => 'haircut-basic',
        'product_title' => 'Basic Haircut',
        'section_key' => 'book',
        'visitor_id' => $visitorA,
        'url' => 'https://fresha.com/book/haircut-basic',
    ]);
    insertLinkClick($this->userId, [
        'product_id' => 'haircut-basic',
        'product_title' => 'Basic Haircut',
        'section_key' => 'book',
        'visitor_id' => $visitorB,
        'url' => 'https://fresha.com/book/haircut-basic',
    ]);

    $services = $this->service->topServices($this->userId, $this->from, $this->to);

    expect($services)->toHaveCount(1)
        ->and($services[0]['product_id'])->toBe('haircut-basic')
        ->and($services[0]['title'])->toBe('Basic Haircut')
        ->and($services[0]['url'])->toBe('https://fresha.com/book/haircut-basic')
        ->and($services[0]['clicks'])->toBe(2)
        ->and($services[0]['unique_clickers'])->toBe(2);
});
