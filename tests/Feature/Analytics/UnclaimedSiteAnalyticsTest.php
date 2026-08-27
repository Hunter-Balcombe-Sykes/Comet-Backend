<?php

use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T9 (2026-08-27 unclaimed-signup quality plan, issue 4): unclaimed
 * pre-account sites render publicly by design (the profiles endpoint has no
 * publication gate; KV routes the subdomain), but every analytics ingest
 * endpoint 404'd them — resolvePublishedSite() rejected is_published=false,
 * which every unclaimed build is. Real visitors to demo sites produced only
 * repeated 404s (verified in the 2026-08-27 build-window logs), so no
 * engagement data ever existed for exactly the sites being shown off.
 *
 * The rule: renderable-as-unclaimed sites ingest analytics; a CLAIMED owner's
 * unpublished site stays 404 — for them the publish knob is the visibility
 * switch and analytics must not leak through it.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    app()->bind(AnalyticsIngestor::class, SyncIngestor::class);
});

function unclaimedTenant(string $handle): object
{
    $pro = createTenant($handle);
    $pro->forceFill(['status' => 'unclaimed'])->save();
    $pro->site->forceFill(['is_published' => false])->save();

    return $pro;
}

it('accepts a pageview for an unclaimed unpublished site', function () {
    $pro = unclaimedTenant('unclaimed-pv');

    $response = $this->withHeaders([
        'X-Site-Subdomain' => 'unclaimed-pv',
        'Origin' => 'https://unclaimed-pv.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $pro->site->id,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('still refuses a CLAIMED owner’s unpublished site (the publish knob holds)', function () {
    $pro = createTenant('claimed-unpub');
    $pro->site->forceFill(['is_published' => false])->save();

    $response = $this->withHeaders([
        'X-Site-Subdomain' => 'claimed-unpub',
        'Origin' => 'https://claimed-unpub.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $pro->site->id,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(404);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});

it('a published claimed site is unaffected', function () {
    $pro = createTenant('claimed-pub');

    $response = $this->withHeaders([
        'X-Site-Subdomain' => 'claimed-pub',
        'Origin' => 'https://claimed-pub.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $pro->site->id,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
});
