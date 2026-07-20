<?php

use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();
    setupLinkClicksTable();

    // Use the sync ingestor so we can assert DB rows (or absence of them) inline.
    // PageviewRequest uses Rule::exists('pgsql.site.sites', 'id') via the real pgsql connection.
    app()->bind(AnalyticsIngestor::class, SyncIngestor::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// SEC-1 — analytics ingest IDOR / tenant isolation
//
// The vulnerability: resolveSiteFromData() only cross-checks site_id ↔ subdomain
// when subdomain is present. A site_id-only POST returns ANY published site,
// so an attacker who knows a victim's UUID (exposed in public page payloads) can
// inject fabricated events. Fix: bind each ingest request to the site's canonical
// Origin header. Browsers cannot forge Origin from JS.
// ─────────────────────────────────────────────────────────────────────────────

// ── Pre-existing IDOR tests (subdomain cross-check path) ─────────────────────

it('refuses to record a pageview when body site_id does not match the X-Site-Subdomain header', function () {
    $victim = createTenant('victim');
    $attacker = createTenant('attacker');

    // Attack: correct attacker subdomain in header, victim's site_id in body.
    // prepareForValidation() merges subdomain='attacker'. The cross-check must
    // detect the mismatch and reject the request.
    $response = $this->withHeaders(['X-Site-Subdomain' => 'attacker'])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $victim->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    expect($response->status())->toBe(422);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});

it('records a pageview when site_id matches the X-Site-Subdomain header', function () {
    $tenant = createTenant('legit');

    $response = $this->withHeaders(['X-Site-Subdomain' => 'legit'])
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    // 201 = pageview recorded successfully.
    // 404/500 acceptable if the aggregate job or cache service hits missing
    // infrastructure in the SQLite test environment.
    expect($response->status())->toBeIn([201, 404, 500]);
});

// ── Origin-binding tests (SEC-1 fix) ─────────────────────────────────────────

// (a) site_id-only POST with an attacker Origin (wrong site) → 404, no event written.
it('rejects a pageview when site_id-only POST carries an Origin from a different site', function () {
    $victim = createTenant('idor-victim-a');
    $attacker = createTenant('idor-attacker-a');
    $attackerOrigin = 'https://idor-attacker-a.'.config('partna.public_domain');

    $response = $this->withHeader('Origin', $attackerOrigin)
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $victim->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    // 404 — non-leaky rejection. No existence signal.
    $response->assertStatus(404);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});

// (b) site_id-only POST with the CORRECT Origin (victim's own page) → success.
it('accepts a pageview when site_id-only POST carries the correct Origin', function () {
    $tenant = createTenant('idor-legit-b');
    $correctOrigin = 'https://idor-legit-b.'.config('partna.public_domain');

    $response = $this->withHeader('Origin', $correctOrigin)
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->first()->site_id)->toBe($tenant->site->id);
});

// (c) site_id of site A + Origin of site B → rejected, no event.
it('rejects a click when site_id belongs to site A but Origin is site B', function () {
    $siteA = createTenant('idor-site-a-c');
    $siteB = createTenant('idor-site-b-c');
    $siteBOrigin = 'https://idor-site-b-c.'.config('partna.public_domain');

    $response = $this->withHeader('Origin', $siteBOrigin)
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $siteA->site->id,
            'url' => 'https://example.com/link',
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(404);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

// (d) Legitimate subdomain-path request with matching Origin → success.
it('accepts a pageview when subdomain is provided and Origin matches', function () {
    $tenant = createTenant('idor-sub-d');
    $matchingOrigin = 'https://idor-sub-d.'.config('partna.public_domain');

    $response = $this->withHeader('Origin', $matchingOrigin)
        ->postJson('/api/public/analytics/pageviews', [
            'subdomain' => 'idor-sub-d',
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

// (e) No-Origin + no-Referer + both site_id and subdomain present and matching → allowed.
it('allows a pageview with no Origin when both site_id and subdomain are present and match', function () {
    $tenant = createTenant('idor-both-e');

    // No Origin or Referer — server-side / synthetic caller. Both identifiers present.
    $response = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
        'subdomain' => 'idor-both-e',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

// (f) No-Origin + no-Referer + site_id only → rejected (the core IDOR attack vector).
it('rejects a pageview with no Origin when only site_id is provided (IDOR attack path)', function () {
    $victim = createTenant('idor-victim-f');

    // Attacker knows the victim's UUID (it's in every public page payload) but has
    // no browser page to generate a real Origin. Must be rejected.
    $response = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $victim->site->id,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(404);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});
