<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupLinkClicksTable();
    Queue::fake();
});

it('silently discards clicks with a known bot user agent', function () {
    $tenant = createBrandTenant('bot-filter-basic');
    $block = createLinkBlockFor($tenant);

    $response = $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Origin' => 'https://bot-filter-basic.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
    ]);

    $response->assertStatus(200);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('silently discards clicks from a headless browser', function () {
    $tenant = createBrandTenant('bot-filter-headless');
    $block = createLinkBlockFor($tenant);

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/112.0.0.0 Safari/537.36',
        'Origin' => 'https://bot-filter-headless.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
    ]);

    $response->assertStatus(200);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('silently discards clicks from curl', function () {
    $tenant = createBrandTenant('bot-filter-curl');
    $block = createLinkBlockFor($tenant);

    $response = $this->withHeaders([
        'User-Agent' => 'curl/7.68.0',
        'Origin' => 'https://bot-filter-curl.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
    ]);

    $response->assertStatus(200);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('records clicks from a legitimate browser user agent', function () {
    $tenant = createBrandTenant('bot-filter-legit');
    $block = createLinkBlockFor($tenant);

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Origin' => 'https://bot-filter-legit.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
});

it('records the click but nulls out a malformed referrer', function () {
    $tenant = createBrandTenant('bot-filter-referrer');
    $block = createLinkBlockFor($tenant);

    $response = $this->withHeaders([
        'Origin' => 'https://bot-filter-referrer.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'referrer' => 'NOT_A_VALID_URL',
    ]);

    $response->assertStatus(201);

    $record = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($record)->not->toBeNull();
    expect($record->referrer)->toBeNull();
});

it('preserves a valid referrer URL', function () {
    $tenant = createBrandTenant('bot-filter-referrer-valid');
    $block = createLinkBlockFor($tenant);

    $referrer = 'https://instagram.com/p/abc123';

    $response = $this->withHeaders([
        'Origin' => 'https://bot-filter-referrer-valid.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'referrer' => $referrer,
    ]);

    $response->assertStatus(201);

    $record = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($record->referrer)->toBe($referrer);
});
