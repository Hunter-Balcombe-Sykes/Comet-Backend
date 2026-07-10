<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Per-page dwell ingest — POST /api/public/analytics/section-dwell annotates the
// matching section impression row (site + section + visitor|session) with the
// client's CUMULATIVE visible-time. GREATEST-merge → idempotent under retries and
// out-of-order delivery (ping's pattern). UPDATE only, never INSERT: a dwell whose
// impression beacon was lost drops, so impression counts stay exact.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSectionViewsTable();
    Queue::fake();
});

/** Seed one section impression row (what a section-seen beacon would have written). */
function dwellSeedSectionView(object $tenant, string $sectionKey, string $visitorId, ?string $occurredAt = null): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => $id,
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'section_key' => $sectionKey,
        'visitor_id' => $visitorId,
        'occurred_at' => $occurredAt ?? now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    return $id;
}

it('annotates the matching impression row with dwell', function () {
    $tenant = createBrandTenant('dwell-happy');
    $visitorId = (string) Str::uuid();
    $rowId = dwellSeedSectionView($tenant, 'shop', $visitorId);

    $response = $this->withHeader('Origin', 'https://dwell-happy.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-dwell', [
            'site_id' => $tenant->site->id,
            'section_key' => 'shop',
            'duration_ms' => 12_000,
            'visitor_id' => $visitorId,
        ]);

    $response->assertStatus(200);
    $row = DB::connection('pgsql')->table('analytics.section_views')->where('id', $rowId)->first();
    expect((int) $row->duration_ms)->toBe(12_000);
});

it('GREATEST-merges cumulative reports — a larger value raises, a smaller/stale one never lowers', function () {
    $tenant = createBrandTenant('dwell-greatest');
    $visitorId = (string) Str::uuid();
    $rowId = dwellSeedSectionView($tenant, 'listen', $visitorId);
    $origin = 'https://dwell-greatest.'.config('partna.public_domain');

    $post = fn (int $ms) => $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/section-dwell', [
            'site_id' => $tenant->site->id,
            'section_key' => 'listen',
            'duration_ms' => $ms,
            'visitor_id' => $visitorId,
        ])->assertStatus(200);

    $post(8_000);   // first report
    $post(20_000);  // re-entry → larger cumulative → raises
    $post(5_000);   // out-of-order retry of an old report → must NOT lower

    $row = DB::connection('pgsql')->table('analytics.section_views')->where('id', $rowId)->first();
    expect((int) $row->duration_ms)->toBe(20_000);
});

it('drops a dwell with no matching impression row instead of inserting one', function () {
    $tenant = createBrandTenant('dwell-orphan');

    $response = $this->withHeader('Origin', 'https://dwell-orphan.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-dwell', [
            'site_id' => $tenant->site->id,
            'section_key' => 'watch',
            'duration_ms' => 9_000,
            'visitor_id' => (string) Str::uuid(),
        ]);

    // Accepted (the merge is best-effort) but nothing fabricated.
    $response->assertStatus(200);
    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(0);
});

it('never annotates another site\'s row (cross-site guard)', function () {
    $tenant = createBrandTenant('dwell-own');
    $otherTenant = createBrandTenant('dwell-other');
    $visitorId = (string) Str::uuid();
    // The only existing row for this section+visitor belongs to the OTHER site.
    $foreignRowId = dwellSeedSectionView($otherTenant, 'book', $visitorId);

    $this->withHeader('Origin', 'https://dwell-own.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-dwell', [
            'site_id' => $tenant->site->id,
            'section_key' => 'book',
            'duration_ms' => 15_000,
            'visitor_id' => $visitorId,
        ])->assertStatus(200);

    $foreign = DB::connection('pgsql')->table('analytics.section_views')->where('id', $foreignRowId)->first();
    expect($foreign->duration_ms)->toBeNull();
});

it('matches by session_id when no visitor_id is supplied', function () {
    $tenant = createBrandTenant('dwell-session');
    $sessionId = (string) Str::uuid();
    $rowId = (string) Str::uuid();
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => $rowId,
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'section_key' => 'contact',
        'session_id' => $sessionId,
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    $this->withHeader('Origin', 'https://dwell-session.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-dwell', [
            'site_id' => $tenant->site->id,
            'section_key' => 'contact',
            'duration_ms' => 30_000,
            'session_id' => $sessionId,
        ])->assertStatus(200);

    $row = DB::connection('pgsql')->table('analytics.section_views')->where('id', $rowId)->first();
    expect((int) $row->duration_ms)->toBe(30_000);
});

it('rejects a dwell with neither identifier (writer could never match a row)', function () {
    $tenant = createBrandTenant('dwell-no-id');

    $this->withHeader('Origin', 'https://dwell-no-id.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/section-dwell', [
            'site_id' => $tenant->site->id,
            'section_key' => 'shop',
            'duration_ms' => 9_000,
        ])->assertStatus(422);
});

it('rejects out-of-bounds durations (sub-second noise + >10min inflation)', function () {
    $tenant = createBrandTenant('dwell-bounds');
    $origin = 'https://dwell-bounds.'.config('partna.public_domain');
    $base = [
        'site_id' => $tenant->site->id,
        'section_key' => 'shop',
        'visitor_id' => (string) Str::uuid(),
    ];

    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/section-dwell', [...$base, 'duration_ms' => 500])
        ->assertStatus(422);
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/section-dwell', [...$base, 'duration_ms' => 900_000])
        ->assertStatus(422);
});

it('silently ignores bot user-agents (200, nothing written)', function () {
    $tenant = createBrandTenant('dwell-bot');
    $visitorId = (string) Str::uuid();
    $rowId = dwellSeedSectionView($tenant, 'shop', $visitorId);

    $this->withHeaders([
        'User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Origin' => 'https://dwell-bot.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/section-dwell', [
        'site_id' => $tenant->site->id,
        'section_key' => 'shop',
        'duration_ms' => 60_000,
        'visitor_id' => $visitorId,
    ])->assertStatus(200);

    $row = DB::connection('pgsql')->table('analytics.section_views')->where('id', $rowId)->first();
    expect($row->duration_ms)->toBeNull();
});
