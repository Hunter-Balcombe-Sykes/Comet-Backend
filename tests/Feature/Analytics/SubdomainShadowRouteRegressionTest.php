<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Regression for the bug where /api/public/analytics/pageviews and /clicks were
// registered both inside routes/api/publicSite.php's {subdomain}.partna.au group
// AND in routes/api.php at the top level. The subdomain group was require'd first,
// so the URL host (e.g. dev-api.partna.au, where 'dev-api' greedy-matches the
// {subdomain} placeholder) won the route match. ResolvesPublicSiteSubdomain then
// overwrote the payload's `subdomain` field with the captured URL segment, breaking
// site resolution. Hydrogen storefronts surfaced this — their analytics proxy hits
// dev-api.partna.au directly with the affiliate slug in the body, no header.

beforeEach(function (): void {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupLinkClicksTable();

    setupSiteVisitsTable();
});

it('does not register a subdomain-scoped pageview or click route', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains((string) $r->uri(), 'public/analytics/'))
        ->map(fn ($r) => [
            'uri' => $r->uri(),
            'domain' => $r->getDomain(),
        ])
        ->values()
        ->all();

    $shadowed = array_filter(
        $routes,
        fn ($r) => $r['domain'] !== null && str_contains((string) $r['domain'], '{subdomain}'),
    );

    expect($shadowed)->toBeEmpty(
        'Analytics ingest routes must not be registered inside {subdomain}.* groups — '.
        'doing so makes the URL host greedy-match the placeholder and overwrite the '.
        'payload subdomain field, breaking site resolution from Hydrogen storefronts.',
    );
});

it('resolves the site from the payload subdomain on POST /api/public/analytics/pageviews', function (): void {
    $tenant = createTenant('hydrogen-affiliate');

    // Hydrogen proxy sends the subdomain in the body and must include an Origin header
    // matching the mini-site page it's serving (browser origin check). Server-side callers
    // with no Origin must provide both site_id + subdomain (the approved fallback).
    $response = $this->withHeader('Origin', 'https://hydrogen-affiliate.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/pageviews', [
            'subdomain' => 'hydrogen-affiliate',
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('resolves the site from the payload subdomain on POST /api/public/analytics/clicks', function (): void {
    $tenant = createTenant('hydrogen-affiliate-2');
    $block = createLinkBlockFor($tenant);

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
        'Origin' => 'https://hydrogen-affiliate-2.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/clicks', [
        'subdomain' => 'hydrogen-affiliate-2',
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1);
});
