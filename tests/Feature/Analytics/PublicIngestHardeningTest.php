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

// --- PGR-18: UTM params sanitised before reaching the queue payload --------------

describe('PGR-18: queue payload UTM sanitisation', function () {
    beforeEach(function () {
        app()->bind(AnalyticsIngestor::class, QueuedIngestor::class);
        Queue::fake();
    });

    it('neutralises a UTM value carrying an email-like string on the dispatched pageview job payload', function () {
        $tenant = createTenant('pgr18-pageview');

        $this->withHeader('Origin', 'https://pgr18-pageview.'.config('partna.public_domain'))
            ->postJson('/api/public/analytics/pageviews', [
                'site_id' => $tenant->site->id,
                'utm_source' => 'newsletter-leak@example.com',
                'utm_medium' => 'email',
                'utm_campaign' => 'summer-sale',
            ])->assertStatus(201);

        Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) {
            return $job->payload['utm_source'] === null
                && $job->payload['utm_medium'] === 'email'
                && $job->payload['utm_campaign'] === 'summer-sale';
        });
    });
});

// --- PGR-20: userAgent sanitised before reaching the queue payload ---------------

describe('PGR-20: queue payload User-Agent sanitisation', function () {
    beforeEach(function () {
        app()->bind(AnalyticsIngestor::class, QueuedIngestor::class);
        Queue::fake();
    });

    it('reduces the raw User-Agent to family/major-version on the dispatched pageview job payload', function () {
        $tenant = createTenant('pgr20-pageview');
        $chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/141.0.7390.54 Safari/537.36';

        $this->withHeader('Origin', 'https://pgr20-pageview.'.config('partna.public_domain'))
            ->withHeader('User-Agent', $chromeUa)
            ->postJson('/api/public/analytics/pageviews', [
                'site_id' => $tenant->site->id,
            ])->assertStatus(201);

        Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) {
            return $job->payload['user_agent'] === 'Chrome/141';
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

// --- PRIV-1/PRIV-2: rum() UA now routes through the shared AnalyticsEventSanitizer,
// which reduces it to family/major-version rather than capping its length ---

it('reduces the rum beacon UA to family/major-version via the shared sanitiser (PRIV-2)', function () {
    $chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/141.0.7390.54 Safari/537.36';

    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['ua'])->toBe('Chrome/141');

            return true;
        }));

    $this->withHeaders(['User-Agent' => $chromeUa])
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

// --- #W2-SEC-10: x-visitor-ip is validated as an IP before it is believed -------
//
// The /t/* proxy forwards the ORIGINAL visitor's connecting IP under this header
// because the Worker subrequest's own is a shared Cloudflare colo IP. Nothing
// proves a given request came through that proxy — api.partna.au is a dns-only
// CNAME to Laravel Cloud, so a direct POST and the Worker's subrequest arrive
// over the same edge — so the header is treated as a HINT that must at least be
// well-formed, never as an authenticated claim. See visitorIp()'s docblock for
// the residual and what closing it would take.

/** The ip_hash the ingest job would carry for one beacon. */
function w2sec10IpHash(string $subdomain, array $headers): ?string
{
    $tenant = createTenant($subdomain);

    test()->withHeaders([
        'Origin' => 'https://'.$subdomain.'.'.config('partna.public_domain'),
        ...$headers,
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
        // Deliberately no visitor_id / session_id: that is the ONLY shape in
        // which the IP hash also becomes the dedup identifier.
    ])->assertStatus(201);

    $hash = null;
    Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) use (&$hash) {
        $hash = $job->payload['ip_hash'] ?? null;

        return true;
    });

    return $hash;
}

describe('W2-SEC-10: x-visitor-ip is validated, not trusted blindly', function () {
    beforeEach(function () {
        app()->bind(AnalyticsIngestor::class, QueuedIngestor::class);
        Queue::fake();
    });

    it('honours a well-formed forwarded IP', function () {
        $forwarded = w2sec10IpHash('sec10-good', ['x-visitor-ip' => '203.0.113.7']);

        expect($forwarded)->toBe(hash_hmac('sha256', '203.0.113.7', config('app.key')));
    });

    it('honours a well-formed forwarded IPv6 address', function () {
        $forwarded = w2sec10IpHash('sec10-v6', ['x-visitor-ip' => '2001:db8::1']);

        expect($forwarded)->toBe(hash_hmac('sha256', '2001:db8::1', config('app.key')));
    });

    it('ignores a forwarded value that is not an IP address at all', function () {
        // Pre-fix this string WAS the "IP": it reached hashIp() verbatim and the
        // resulting hash became both the stored ip_hash and the dedup identifier.
        $hash = w2sec10IpHash('sec10-junk', ['x-visitor-ip' => 'not-an-ip; DROP TABLE']);

        expect($hash)->not->toBe(hash_hmac('sha256', 'not-an-ip; DROP TABLE', config('app.key')));
        // Falls through to the connecting IP, exactly as an absent header does.
        expect($hash)->toBe(w2sec10IpHash('sec10-none', []));
    });

    it('ignores an empty or whitespace-only forwarded value', function () {
        expect(w2sec10IpHash('sec10-blank', ['x-visitor-ip' => '   ']))
            ->toBe(w2sec10IpHash('sec10-blank2', []));
    });
});
