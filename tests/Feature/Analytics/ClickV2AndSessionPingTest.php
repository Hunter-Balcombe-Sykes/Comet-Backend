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
    $tenant = createTenant('v2-click');

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
    $tenant = createTenant('v2-click-invalid');

    // 422 is the validation rejection (no block_id or url) — origin check is irrelevant.
    $this->withHeader('Origin', 'https://v2-click-invalid.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'platform' => 'instagram',
        ])->assertStatus(422);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('deduplicates rapid v2 clicks on the same destination from the same visitor', function () {
    $tenant = createTenant('v2-click-dedup');
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
    $tenant = createTenant('ping-upsert');
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

it('records independent session rows when two different sites receive a ping with the same session id', function () {
    // #DINT-1 regression: analytics.site_sessions used to be keyed PRIMARY KEY (id)
    // alone, and upsertSession() paired that with `WHERE site_sessions.site_id =
    // EXCLUDED.site_id` — so a second site's ping sharing a session id (one visitor
    // browsing two Partna sites, if the tracker ever reuses an id) conflicted on the
    // PK, failed the WHERE guard, and Postgres skipped BOTH the UPDATE and the
    // blocked INSERT: the second site's heartbeat silently vanished. The fix keys
    // the conflict target on (id, site_id), so each site gets its own row.
    $siteA = createTenant('cross-site-a');
    $siteB = createTenant('cross-site-b');
    $sharedSessionId = (string) Str::uuid();

    $this->withHeader('Origin', 'https://cross-site-a.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/ping', [
            'site_id' => $siteA->site->id,
            'session_id' => $sharedSessionId,
            'seconds' => 5,
        ])->assertStatus(200);

    $this->withHeader('Origin', 'https://cross-site-b.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/ping', [
            'site_id' => $siteB->site->id,
            'session_id' => $sharedSessionId,
            'seconds' => 8,
        ])->assertStatus(200);

    // Both pings must land as SEPARATE rows — one per site — neither dropped.
    $rows = DB::connection('pgsql')->table('analytics.site_sessions')
        ->where('id', $sharedSessionId)
        ->get();

    expect($rows)->toHaveCount(2);

    $bySite = $rows->keyBy('site_id');
    expect($bySite->has($siteA->site->id))->toBeTrue()
        ->and($bySite->has($siteB->site->id))->toBeTrue()
        ->and((int) $bySite[$siteA->site->id]->duration_seconds)->toBe(5)
        ->and((int) $bySite[$siteB->site->id]->duration_seconds)->toBe(8);
});

it('silently accepts but does not record bot pings', function () {
    $tenant = createTenant('ping-bot');

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
