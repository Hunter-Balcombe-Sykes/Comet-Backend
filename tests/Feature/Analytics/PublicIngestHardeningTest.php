<?php

// Covers audit unit U3 (JOB-1 / WHK-1 / SEM-1 / PRIV-1): referrer PII reaching the
// queue payload, dedup being skipped when a beacon omits both identifiers, the click
// dedup key not lowercasing platform, and the rum() beacon's inline UA truncation.

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    setupSectionViewsTable();
    setupItemViewsTable();
});

// --- JOB-1: referrer sanitised before it reaches the Redis queue payload ---------

describe('JOB-1: queue payload referrer sanitisation', function () {
    beforeEach(function () {
        app()->bind(AnalyticsIngestor::class, QueuedIngestor::class);
        Queue::fake();
    });

    it('strips UTM-embedded PII from the referrer on the dispatched pageview job payload', function () {
        $tenant = createTenant('job1-pageview');

        $this->withHeader('Origin', 'https://job1-pageview.'.config('partna.public_domain'))
            ->postJson('/api/public/analytics/pageviews', [
                'site_id' => $tenant->site->id,
                'referrer' => 'https://mail.example.com/c?utm_content=user@example.com#frag',
            ])->assertStatus(201);

        Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) {
            return $job->payload['referrer'] === 'https://mail.example.com/c';
        });
    });

    it('strips UTM-embedded PII from the referrer on the dispatched click job payload', function () {
        $tenant = createTenant('job1-click');
        $block = createLinkBlockFor($tenant);

        $this->withHeader('Origin', 'https://job1-click.'.config('partna.public_domain'))
            ->postJson('/api/public/analytics/clicks', [
                'site_id' => $tenant->site->id,
                'block_id' => $block->id,
                'referrer' => 'https://mail.example.com/c?utm_content=user@example.com#frag',
            ])->assertStatus(201);

        Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) {
            return $job->payload['referrer'] === 'https://mail.example.com/c';
        });
    });
});

// --- Characterisation: pageview's malformed-referrer storage is unchanged --------

it('still stores a null referrer for pageview when the raw value is not a URL (characterisation)', function () {
    $tenant = createTenant('job1-pageview-malformed');

    $this->withHeader('Origin', 'https://job1-pageview-malformed.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'referrer' => 'NOT_A_VALID_URL',
        ])->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.site_visits')->first();
    expect($row)->not->toBeNull();
    expect($row->referrer)->toBeNull();
});

// --- WHK-1: dedup fallback when a beacon omits both visitor_id and session_id ----

describe('WHK-1: dedup fallback identifier', function () {
    it('dedups a repeat click that has neither visitor_id nor session_id (same IP)', function () {
        $tenant = createTenant('whk1-click-dedup');
        $block = createLinkBlockFor($tenant);
        $origin = 'https://whk1-click-dedup.'.config('partna.public_domain');
        $payload = ['site_id' => $tenant->site->id, 'block_id' => $block->id];

        $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);
        $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);

        expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
    });

    it('does NOT dedup identifier-less clicks from two different IPs', function () {
        $tenant = createTenant('whk1-click-diffip');
        $block = createLinkBlockFor($tenant);
        $origin = 'https://whk1-click-diffip.'.config('partna.public_domain');
        $payload = ['site_id' => $tenant->site->id, 'block_id' => $block->id];

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $payload)->assertStatus(201);

        expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(2);
    });

    it('dedups a repeat section-seen that has neither visitor_id nor session_id (same IP)', function () {
        $tenant = createTenant('whk1-section-dedup');
        $origin = 'https://whk1-section-dedup.'.config('partna.public_domain');
        $payload = ['site_id' => $tenant->site->id, 'section_key' => 'about'];

        $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', $payload)->assertStatus(201);
        $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', $payload)->assertStatus(201);

        expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);
    });

    it('does NOT dedup identifier-less section-seen beacons from two different IPs', function () {
        $tenant = createTenant('whk1-section-diffip');
        $origin = 'https://whk1-section-diffip.'.config('partna.public_domain');
        $payload = ['site_id' => $tenant->site->id, 'section_key' => 'about'];

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', $payload)->assertStatus(201);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
            ->withHeader('Origin', $origin)->postJson('/api/public/analytics/section-seen', $payload)->assertStatus(201);

        expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(2);
    });

    it('dedups a repeat item-seen that has neither visitor_id nor session_id (same IP)', function () {
        $tenant = createTenant('whk1-item-dedup');
        $origin = 'https://whk1-item-dedup.'.config('partna.public_domain');
        $payload = ['site_id' => $tenant->site->id, 'item_type' => 'shop_product', 'item_id' => 'sku-1'];

        $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', $payload)->assertStatus(201);
        $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', $payload)->assertStatus(201);

        expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(1);
    });

    it('does NOT dedup identifier-less item-seen beacons from two different IPs', function () {
        $tenant = createTenant('whk1-item-diffip');
        $origin = 'https://whk1-item-diffip.'.config('partna.public_domain');
        $payload = ['site_id' => $tenant->site->id, 'item_type' => 'shop_product', 'item_id' => 'sku-1'];

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
            ->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', $payload)->assertStatus(201);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.2'])
            ->withHeader('Origin', $origin)->postJson('/api/public/analytics/item-seen', $payload)->assertStatus(201);

        expect(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(2);
    });
});

// --- SEM-1: click dedup key lowercases platform to match what buildEvent() stores ---

it('dedups back-to-back clicks whose platform casing differs (Instagram vs instagram)', function () {
    $tenant = createTenant('sem1-platform-case');
    $visitorId = (string) Str::uuid();
    $origin = 'https://sem1-platform-case.'.config('partna.public_domain');
    $base = ['site_id' => $tenant->site->id, 'visitor_id' => $visitorId, 'url' => 'https://instagram.com/someone'];

    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $base + ['platform' => 'Instagram'])->assertStatus(201);
    $this->withHeader('Origin', $origin)->postJson('/api/public/analytics/clicks', $base + ['platform' => 'instagram'])->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
});

// --- PRIV-1: rum() UA now routes through the shared AnalyticsEventSanitizer cap ---

it('caps the rum beacon UA at 256 chars via the shared sanitiser (no ellipsis marker)', function () {
    $longUa = 'Mozilla/5.0 (TestBrowser) '.str_repeat('X', 400);

    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) use ($longUa) {
            expect($context['ua'])->toHaveLength(256);
            // No trailing '...' — AnalyticsEventSanitizer::userAgent() passes '' as
            // Str::limit's $end, matching the old substr() truncation exactly.
            expect($context['ua'])->toBe(substr($longUa, 0, 256));

            return true;
        }));

    $this->withHeaders(['User-Agent' => $longUa])
        ->postJson('/api/public/analytics/rum', ['handle' => 'sem1-platform-case'])
        ->assertStatus(200);
});

// --- B7/PRIV-3: rum() handle is hashed, not logged in the clear -------------------

it('hashes the rum beacon handle instead of logging it raw', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['handle'])->toBe(hash('sha256', 'priv3-handle-case'));
            expect($context['handle'])->not->toBe('priv3-handle-case');

            return true;
        }));

    $this->postJson('/api/public/analytics/rum', ['handle' => 'PRIV3-Handle-Case'])
        ->assertStatus(200);
});
