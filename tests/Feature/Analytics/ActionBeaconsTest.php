<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Analytics v2 — public ingest endpoints POST /api/public/analytics/action-seen
// and /action-tap (2026-07-23 actions rebuild). Mirrors ItemSeenIngestTest's
// vertical: validates site, checks publication, rejects bots silently, binds
// origin, dedups within a window per (session|visitor)+action. Both beacons
// share identical security posture — every case below runs against both.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupActionEventsTable();
    Queue::fake();
});

dataset('actionBeaconEndpoints', [
    'action-seen' => ['action-seen', 'seen'],
    'action-tap' => ['action-tap', 'tap'],
]);

it('records an action event for a published site', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-happy');

    $response = $this->withHeader('Origin', 'https://action-'.$event.'-happy.'.config('partna.public_domain'))
        ->postJson("/api/public/analytics/{$path}", [
            'site_id' => $tenant->site->id,
            'action_id' => 'menu',
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(1);

    $row = DB::connection('pgsql')->table('analytics.action_events')->first();
    expect($row->action_id)->toBe('menu');
    expect($row->event)->toBe($event);
    expect($row->user_id)->toBe($tenant->id);
    expect($row->site_id)->toBe($tenant->site->id);
})->with('actionBeaconEndpoints');

it('accepts a dynamic-family action_id (ordering:<id> / custom:<key>)', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-family');

    $this->withHeader('Origin', 'https://action-'.$event.'-family.'.config('partna.public_domain'))
        ->postJson("/api/public/analytics/{$path}", [
            'site_id' => $tenant->site->id,
            'action_id' => 'custom:9b2f1c34-aaaa-bbbb-cccc-121212121212',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.action_events')->value('action_id'))
        ->toBe('custom:9b2f1c34-aaaa-bbbb-cccc-121212121212');
})->with('actionBeaconEndpoints');

it('rejects an action_id outside the fixed vocabulary', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-badid');

    $this->withHeader('Origin', 'https://action-'.$event.'-badid.'.config('partna.public_domain'))
        ->postJson("/api/public/analytics/{$path}", [
            'site_id' => $tenant->site->id,
            'action_id' => 'not-a-real-action',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(0);
})->with('actionBeaconEndpoints');

it('requires action_id', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-noid');

    $this->withHeader('Origin', 'https://action-'.$event.'-noid.'.config('partna.public_domain'))
        ->postJson("/api/public/analytics/{$path}", [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422);
})->with('actionBeaconEndpoints');

it('deduplicates a repeat event for the same action by the same session within the dedup window', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-dedup');
    $sessionId = (string) Str::uuid();
    $origin = 'https://action-'.$event.'-dedup.'.config('partna.public_domain');

    $payload = [
        'site_id' => $tenant->site->id,
        'action_id' => 'contact',
        'session_id' => $sessionId,
    ];

    $this->withHeader('Origin', $origin)->postJson("/api/public/analytics/{$path}", $payload)->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson("/api/public/analytics/{$path}", $payload)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(1);
})->with('actionBeaconEndpoints');

it('records different actions under the same session independently', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-multi');
    $sessionId = (string) Str::uuid();
    $origin = 'https://action-'.$event.'-multi.'.config('partna.public_domain');

    $this->withHeader('Origin', $origin)->postJson("/api/public/analytics/{$path}", [
        'site_id' => $tenant->site->id, 'action_id' => 'menu', 'session_id' => $sessionId,
    ])->assertStatus(201);

    $this->withHeader('Origin', $origin)->postJson("/api/public/analytics/{$path}", [
        'site_id' => $tenant->site->id, 'action_id' => 'contact', 'session_id' => $sessionId,
    ])->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(2);
})->with('actionBeaconEndpoints');

it('a seen and a tap for the same action+session both record — the two beacons dedup independently', function () {
    $tenant = createTenant('action-cross-dedup');
    $sessionId = (string) Str::uuid();
    $origin = 'https://action-cross-dedup.'.config('partna.public_domain');
    $payload = ['site_id' => $tenant->site->id, 'action_id' => 'menu', 'session_id' => $sessionId];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/action-seen', $payload)->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/action-tap', $payload)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(2);
    expect(DB::connection('pgsql')->table('analytics.action_events')->pluck('event')->sort()->values()->all())
        ->toBe(['seen', 'tap']);
});

it('rejects a request whose Origin does not match the resolved site (IDOR defence)', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-badorigin');

    $this->withHeader('Origin', 'https://someone-elses-site.'.config('partna.public_domain'))
        ->postJson("/api/public/analytics/{$path}", [
            'site_id' => $tenant->site->id,
            'action_id' => 'menu',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(404);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(0);
})->with('actionBeaconEndpoints');

it('returns 404 when site is unpublished (does not leak existence)', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-unpub');
    DB::connection('pgsql')->table('site.sites')
        ->where('id', $tenant->site->id)
        ->update(['is_published' => 0]);

    $this->withHeader('Origin', 'https://action-'.$event.'-unpub.'.config('partna.public_domain'))
        ->postJson("/api/public/analytics/{$path}", [
            'site_id' => $tenant->site->id,
            'action_id' => 'menu',
            'session_id' => (string) Str::uuid(),
        ])
        ->assertStatus(404);
})->with('actionBeaconEndpoints');

it('silently ignores bot user-agents (200 not 201)', function (string $path, string $event) {
    $tenant = createTenant('action-'.$event.'-bot');

    $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Origin' => 'https://action-'.$event.'-bot.'.config('partna.public_domain'),
    ])->postJson("/api/public/analytics/{$path}", [
        'site_id' => $tenant->site->id,
        'action_id' => 'menu',
        'session_id' => (string) Str::uuid(),
    ])->assertStatus(200);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(0);
})->with('actionBeaconEndpoints');
