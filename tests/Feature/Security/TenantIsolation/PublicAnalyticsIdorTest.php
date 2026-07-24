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

    $response = $this->withHeaders([
        'X-Site-Subdomain' => 'legit',
        'Origin' => 'https://legit.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
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

// (e) No-Origin + no-Referer + both site_id and subdomain present and matching → rejected.
// This is the SEC-1 regression test: site_id and subdomain are both public values
// (exposed in every page payload), so matching them can never substitute for a
// browser-issued Origin/Referer.
it('rejects a pageview with no Origin and no Referer even when site_id and subdomain both match the site (SEC-1)', function () {
    $tenant = createTenant('idor-both-e');

    // No Origin or Referer at all — a scripted caller with only the public pair.
    $response = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
        'subdomain' => 'idor-both-e',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(404);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});

// (e2) No-Origin + no-Referer + subdomain only → rejected (SEC-1).
it('rejects a pageview with no Origin and no Referer when only subdomain is provided', function () {
    $tenant = createTenant('idor-subdomain-only-e2');

    $response = $this->postJson('/api/public/analytics/pageviews', [
        'subdomain' => 'idor-subdomain-only-e2',
        'session_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(404);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
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

// ── IDOR: site_id/subdomain mismatch still fails independent of Origin ───────

// (g) site_id of one real site paired with the subdomain of a DIFFERENT real site,
// no Origin/Referer either → rejected inside resolveSiteFromData()'s own cross-check,
// before originAllowed() is ever reached. Unaffected by the SEC-1 fallback removal.
it('rejects a pageview with no Origin when site_id and subdomain resolve to different sites', function () {
    $siteA = createTenant('idor-mismatch-a');
    $siteB = createTenant('idor-mismatch-b');

    $response = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $siteA->site->id,
        'subdomain' => 'idor-mismatch-b',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);

    // 422 — site_id was supplied but the pair didn't resolve (matches the
    // resolvePublishedSite() status convention for a supplied-but-wrong site_id).
    $response->assertStatus(422);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);

    // Neither site's row leaked an event either.
    expect(DB::connection('pgsql')->table('analytics.site_visits')->where('site_id', $siteA->site->id)->count())->toBe(0);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->where('site_id', $siteB->site->id)->count())->toBe(0);
});

// (h) Origin absent, Referer present and matching → accepted. Covers the surviving
// fallback in parseOriginHost(), which would otherwise go untested now that (e)
// proves site_id+subdomain alone can no longer authenticate a header-less caller.
it('accepts a pageview when Origin is absent but Referer matches the site host', function () {
    $tenant = createTenant('idor-match-h');

    $response = $this->withHeader('Referer', 'https://idor-match-h.'.config('partna.public_domain').'/some/path')
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
            'subdomain' => 'idor-match-h',
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.site_visits')->where('site_id', $tenant->site->id)->count())->toBe(1);
});
