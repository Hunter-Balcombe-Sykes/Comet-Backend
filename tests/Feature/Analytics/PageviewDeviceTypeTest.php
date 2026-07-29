<?php

// #SEM-6: pageview() is the ONLY analytics beacon with no bot filter of its
// own (deliberate — see AnalyticsController::pageview()'s comment; changing
// that is a separate metrics decision, out of scope here). It is therefore
// the one caller that reaches detectDeviceType() with UAs isBotUserAgent()
// already recognised as bots but detectDeviceType() previously missed. This
// fix only RELABELS device_type — pageview totals are unaffected: the row is
// still written either way.

use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();

    // Sync ingestor so the row is queryable inline, same pattern as
    // PublicAnalyticsIdorTest.
    app()->bind(AnalyticsIngestor::class, SyncIngestor::class);
});

it('still writes the pageview row for a curl UA (totals unchanged), now labelled bot', function () {
    $tenant = createTenant('sem6-curl');
    $origin = 'https://sem6-curl.'.config('partna.public_domain');

    $response = $this->withHeaders(['Origin' => $origin, 'User-Agent' => 'curl/8.4.0'])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.site_visits')->where('site_id', $tenant->site->id)->first();
    expect($row)->not->toBeNull();
    expect($row->device_type)->toBe('bot');
});

it('still writes the pageview row for a facebookexternalhit UA (totals unchanged), now labelled bot', function () {
    $tenant = createTenant('sem6-fb');
    $origin = 'https://sem6-fb.'.config('partna.public_domain');

    $response = $this->withHeaders(['Origin' => $origin, 'User-Agent' => 'facebookexternalhit/1.1'])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.site_visits')->where('site_id', $tenant->site->id)->first();
    expect($row)->not->toBeNull();
    expect($row->device_type)->toBe('bot');
});

it('still stores desktop for a real desktop browser UA — no over-classification', function () {
    $tenant = createTenant('sem6-desktop');
    $origin = 'https://sem6-desktop.'.config('partna.public_domain');
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

    $response = $this->withHeaders(['Origin' => $origin, 'User-Agent' => $ua])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.site_visits')->where('site_id', $tenant->site->id)->first();
    expect($row)->not->toBeNull();
    expect($row->device_type)->toBe('desktop');
});
