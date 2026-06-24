<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupLinkClicksTable();
    setupSiteSessionsTable();
    Queue::fake();
});

it('records a blockless v2 click with url, platform, product and section labels', function () {
    $tenant = createBrandTenant('v2-click');

    $this->withHeader('Origin', 'https://v2-click.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'visitor_id' => (string) Str::uuid(),
            'url' => 'https://shop.example.com/products/black-tee',
            'platform' => 'shopify',
            'product_id' => 'black-tee',
            'product_title' => 'Black Tee',
            'section_key' => 'shop',
            'label' => 'Black Tee',
        ])->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($row)->not->toBeNull()
        ->and($row->link_block_id)->toBeNull()
        ->and($row->url)->toBe('https://shop.example.com/products/black-tee')
        ->and($row->platform)->toBe('shopify')
        ->and($row->product_id)->toBe('black-tee')
        ->and($row->section_key)->toBe('shop');
});

it('rejects a click that has neither block_id nor url', function () {
    $tenant = createBrandTenant('v2-click-invalid');

    // 422 is the validation rejection (no block_id or url) — origin check is irrelevant.
    $this->withHeader('Origin', 'https://v2-click-invalid.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'platform' => 'instagram',
        ])->assertStatus(422);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('deduplicates rapid v2 clicks on the same destination from the same visitor', function () {
    $tenant = createBrandTenant('v2-click-dedup');
    $visitorId = (string) Str::uuid();
    $origin = 'https://v2-click-dedup.'.config('partna.public_domain');

    $payload = [
        'site_id' => $tenant->site->id,
        'visitor_id' => $visitorId,
        'url' => 'https://instagram.com/someone',
        'platform' => 'instagram',
    ];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
});

it('upserts a session from pings — duration only grows (GREATEST semantics)', function () {
    $tenant = createBrandTenant('ping-upsert');
    $sessionId = (string) Str::uuid();
    $origin = 'https://ping-upsert.'.config('partna.public_domain');

    $base = [
        'site_id' => $tenant->site->id,
        'session_id' => $sessionId,
        'referrer' => 'https://instagram.com/',
    ];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/ping', $base + ['seconds' => 5])->assertStatus(200);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/ping', $base + ['seconds' => 30])->assertStatus(200);
    // Late/replayed smaller ping must not shrink the recorded duration.
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/ping', $base + ['seconds' => 10])->assertStatus(200);

    $rows = DB::connection('pgsql')->table('analytics.site_sessions')->get();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->duration_seconds)->toBe(30)
        ->and($rows[0]->referrer)->toBe('https://instagram.com/');
});

it('silently accepts but does not record bot pings', function () {
    $tenant = createBrandTenant('ping-bot');

    $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1',
        'Origin' => 'https://ping-bot.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/ping', [
        'site_id' => $tenant->site->id,
        'session_id' => (string) Str::uuid(),
        'seconds' => 10,
    ])->assertStatus(200);

    expect(DB::connection('pgsql')->table('analytics.site_sessions')->count())->toBe(0);
});
