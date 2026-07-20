<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Analytics v2 — public ingest endpoint POST /api/public/analytics/item-seen.
// Mirrors the section-seen vertical: validates site, checks publication, rejects
// bots silently, dedups within a 5min window per (session|visitor)+item.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupItemViewsTable();
    Queue::fake();
});

it('records an item-seen event for a published site', function () {
    $tenant = createTenant('item-seen-happy');

    $response = $this->withHeader('Origin', 'https://item-seen-happy.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/item-seen', [
            'site_id' => $tenant->site->id,
            'item_type' => 'shop_product',
            'item_id' => 'prod-123',
            'item_title' => 'Cool Hoodie',
            'section_key' => 'shop',
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(1);

    $row = DB::connection('pgsql')->table('analytics.item_views')->first();
    expect($row->item_type)->toBe('shop_product');
    expect($row->item_id)->toBe('prod-123');
    expect($row->item_title)->toBe('Cool Hoodie');
    expect($row->section_key)->toBe('shop');
    expect($row->user_id)->toBe($tenant->id);
    expect($row->site_id)->toBe($tenant->site->id);
});

it('rejects an item_type outside the scored taxonomy', function () {
    $tenant = createTenant('item-seen-badtype');

    $this->withHeader('Origin', 'https://item-seen-badtype.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/item-seen', [
            'site_id' => $tenant->site->id,
            'item_type' => 'page', // 'page' is the section grain, not a scored item
            'item_id' => 'home',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(0);
});

it('requires item_id', function () {
    $tenant = createTenant('item-seen-noid');

    $this->withHeader('Origin', 'https://item-seen-noid.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/item-seen', [
            'site_id' => $tenant->site->id,
            'item_type' => 'service',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422);
});

it('deduplicates a repeat view of the same item by the same session within 5 minutes', function () {
    $tenant = createTenant('item-seen-dedup');
    $sessionId = (string) Str::uuid();
    $origin = 'https://item-seen-dedup.'.config('partna.public_domain');

    $payload = [
        'site_id' => $tenant->site->id,
        'item_type' => 'menu_item',
        'item_id' => 'item-9',
        'session_id' => $sessionId,
    ];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', $payload)->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', $payload)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(1);
});

it('records different items under the same session independently', function () {
    $tenant = createTenant('item-seen-multi');
    $sessionId = (string) Str::uuid();
    $origin = 'https://item-seen-multi.'.config('partna.public_domain');

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', [
        'site_id' => $tenant->site->id, 'item_type' => 'shop_product', 'item_id' => 'a', 'session_id' => $sessionId,
    ])->assertStatus(201);

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', [
        'site_id' => $tenant->site->id, 'item_type' => 'shop_product', 'item_id' => 'b', 'session_id' => $sessionId,
    ])->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(2);
});

it('returns 404 when site is unpublished (does not leak existence)', function () {
    $tenant = createTenant('item-seen-unpub');
    DB::connection('pgsql')->table('site.sites')
        ->where('id', $tenant->site->id)
        ->update(['is_published' => 0]);

    $this->withHeader('Origin', 'https://item-seen-unpub.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/item-seen', [
            'site_id' => $tenant->site->id,
            'item_type' => 'gallery_item',
            'item_id' => 'media-1',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(404);
});

it('silently ignores bot user-agents (200 not 201)', function () {
    $tenant = createTenant('item-seen-bot');

    $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Origin' => 'https://item-seen-bot.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/item-seen', [
        'site_id' => $tenant->site->id,
        'item_type' => 'shop_product',
        'item_id' => 'prod-1',
        'session_id' => (string) Str::uuid(),
    ])->assertStatus(200);

    expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(0);
});
