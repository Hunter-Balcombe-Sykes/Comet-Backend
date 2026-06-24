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

it('deduplicates rapid double-clicks from the same visitor on the same block', function () {
    $tenant = createBrandTenant('dedup-visitor');
    $block = createLinkBlockFor($tenant);
    $visitorId = (string) Str::uuid();
    $origin = 'https://dedup-visitor.'.config('partna.public_domain');

    $payload = [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'visitor_id' => $visitorId,
    ];

    $first = $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload);
    $second = $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
});

it('deduplicates rapid double-clicks identified only by session_id', function () {
    $tenant = createBrandTenant('dedup-session');
    $block = createLinkBlockFor($tenant);
    $sessionId = (string) Str::uuid();
    $origin = 'https://dedup-session.'.config('partna.public_domain');

    $payload = [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'session_id' => $sessionId,
    ];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
});

it('allows a second click after the 3-second dedup window has expired', function () {
    $tenant = createBrandTenant('dedup-window');
    $block = createLinkBlockFor($tenant);
    $visitorId = (string) Str::uuid();

    // Seed a "stale" click older than the dedup window.
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'link_block_id' => $block->id,
        'visitor_id' => $visitorId,
        'occurred_at' => now()->subSeconds(4)->toDateTimeString(),
        'created_at' => now()->subSeconds(4)->toDateTimeString(),
    ]);

    $response = $this->withHeader('Origin', 'https://dedup-window.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'block_id' => $block->id,
            'visitor_id' => $visitorId,
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(2);
});

it('records clicks from different visitors on the same block independently', function () {
    $tenant = createBrandTenant('dedup-multi-visitor');
    $block = createLinkBlockFor($tenant);
    $origin = 'https://dedup-multi-visitor.'.config('partna.public_domain');

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ])->assertStatus(201);

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ])->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(2);
});
