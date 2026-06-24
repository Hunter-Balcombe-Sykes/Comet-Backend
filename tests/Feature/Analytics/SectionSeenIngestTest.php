<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Phase 5 — public ingest endpoint POST /api/public/analytics/section-seen.
// Mirrors the pageview/click ingest pattern: validates site, checks publication,
// rejects bots silently, dedups within a 5min window per (session|visitor)+section.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSectionViewsTable();
    Queue::fake();
});

it('records a section-seen event for a published site', function () {
    $tenant = createBrandTenant('section-seen-happy');

    $response = $this->withHeader('Origin', 'https://section-seen-happy.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-seen', [
            'site_id' => $tenant->site->id,
            'section_key' => 'products',
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);

    $row = DB::connection('pgsql')->table('analytics.section_views')->first();
    expect($row->section_key)->toBe('products');
    expect($row->user_id)->toBe($tenant->id);
    expect($row->site_id)->toBe($tenant->site->id);
});

it('deduplicates a repeat view of the same section by the same session within 5 minutes', function () {
    $tenant = createBrandTenant('section-seen-dedup');
    $sessionId = (string) Str::uuid();
    $origin = 'https://section-seen-dedup.'.config('partna.public_domain');

    $payload = [
        'site_id' => $tenant->site->id,
        'section_key' => 'about',
        'session_id' => $sessionId,
    ];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', $payload)->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', $payload)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);
});

it('allows a second view after the 5-minute dedup window expires', function () {
    $tenant = createBrandTenant('section-seen-window');
    $sessionId = (string) Str::uuid();

    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'section_key' => 'products',
        'session_id' => $sessionId,
        'occurred_at' => now()->subMinutes(6)->toDateTimeString(),
        'created_at' => now()->subMinutes(6)->toDateTimeString(),
    ]);

    $response = $this->withHeader('Origin', 'https://section-seen-window.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-seen', [
            'site_id' => $tenant->site->id,
            'section_key' => 'products',
            'session_id' => $sessionId,
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(2);
});

it('records different sections under the same session independently', function () {
    $tenant = createBrandTenant('section-seen-multi-section');
    $sessionId = (string) Str::uuid();
    $origin = 'https://section-seen-multi-section.'.config('partna.public_domain');

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', [
        'site_id' => $tenant->site->id,
        'section_key' => 'hero',
        'session_id' => $sessionId,
    ])->assertStatus(201);

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', [
        'site_id' => $tenant->site->id,
        'section_key' => 'products',
        'session_id' => $sessionId,
    ])->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(2);
});

it('returns 404 when site is unpublished (does not leak existence)', function () {
    $tenant = createBrandTenant('section-seen-unpublished');
    DB::connection('pgsql')->table('site.sites')
        ->where('id', $tenant->site->id)
        ->update(['is_published' => 0]);

    $response = $this->withHeader('Origin', 'https://section-seen-unpublished.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-seen', [
            'site_id' => $tenant->site->id,
            'section_key' => 'products',
            'session_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(404);
});

it('drops an optional block_id that belongs to another site (cross-site IDOR defence)', function () {
    // Full-decouple: the controller no longer cross-checks the block synchronously, so a
    // foreign block_id is accepted (201) and dropped by the writer. The security invariant
    // — NO cross-site row is ever written — is preserved at the writer.
    $tenant = createBrandTenant('section-seen-block-valid');
    $otherTenant = createBrandTenant('section-seen-other-tenant');
    $foreignBlock = createLinkBlockFor($otherTenant);

    $response = $this->withHeader('Origin', 'https://section-seen-block-valid.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-seen', [
            'site_id' => $tenant->site->id,
            'section_key' => 'products',
            'block_id' => $foreignBlock->id,
            'session_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(0);
});

it('silently ignores bot user-agents (200 not 201)', function () {
    $tenant = createBrandTenant('section-seen-bot');

    $response = $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Origin' => 'https://section-seen-bot.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/section-seen', [
        'site_id' => $tenant->site->id,
        'section_key' => 'products',
        'session_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(200);
    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(0);
});
