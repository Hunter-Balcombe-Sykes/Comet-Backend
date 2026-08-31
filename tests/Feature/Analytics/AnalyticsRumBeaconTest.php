<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The RUM beacon's two senders, and the trust boundary that was missing beneath them.
//
// apps/pages ships TWO real-user-monitoring beacons from the same document: an
// inline script in [...path].astro that sends {handle, ttfb, dom, load, fcp,
// lkg}, and the analytics module (tracker.ts) that sends {ttfb, fcp, lcp}
// through beacon.ts — which attaches `subdomain`, never `handle`. rum() read
// `handle` only, so 100% of the module's beacons were dropped at the identity
// gate and answered 200, and LCP — the field metric the sitepage is actually
// judged on — has never been recorded once.
//
// The identity gate accepts either key (they carry the same value: the site's
// subdomain IS its handle), and an unidentified beacon now leaves a warning
// instead of vanishing. The fake-200 stays: a visitor's browser must never
// learn anything from an analytics response.
//
// #W3-SEC-1 (2026-09-01): accepting the identity was as far as it went. rum()
// then TRUSTED it — no resolvePublishedSite(), no originAllowed(), the only
// public ingest route skipping both — so any caller could attribute performance
// rows to any handle they could guess, and a handle is public. The gates below
// are the same two every sibling route runs. Only the response differs, and
// deliberately: every reject path still answers 200, so the tests assert on the
// LOG, which is the only place a rejected beacon is now visible.

beforeEach(function () {
    tenantHelpersEnsureTables();
    // resolveSiteFromData() consults the alias table before falling back to the
    // user handle; without it that path fatals instead of missing.
    setupSubdomainAliasesTable();
});

it('accepts the tracker module beacon, which identifies the site by subdomain', function () {
    createTenant('module-beacon');

    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['handle'])->toBe(hash('sha256', 'module-beacon'));
            expect($context['ttfb_ms'])->toBe(120);
            expect($context['fcp_ms'])->toBe(340);

            return true;
        }));

    $this->withHeader('Origin', 'https://module-beacon.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/rum', [
            'subdomain' => 'module-beacon',
            'ttfb' => 120,
            'fcp' => 340,
        ])->assertStatus(200);
});

it('records lcp, the metric only the module beacon can measure', function () {
    createTenant('lcp-beacon');

    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['lcp_ms'])->toBe(1820);

            return true;
        }));

    $this->withHeader('Origin', 'https://lcp-beacon.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/rum', [
            'subdomain' => 'lcp-beacon',
            'lcp' => 1820,
        ])->assertStatus(200);
});

it('still reads handle, the key the inline [...path].astro beacon sends', function () {
    createTenant('inline-beacon');

    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['handle'])->toBe(hash('sha256', 'inline-beacon'));
            expect($context['load_ms'])->toBe(900);
            expect($context['lkg'])->toBeTrue();

            return true;
        }));

    $this->withHeader('Origin', 'https://inline-beacon.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/rum', [
            'handle' => 'inline-beacon',
            'load' => 900,
            'lkg' => true,
        ])->assertStatus(200);
});

it('warns rather than silently discarding a beacon with no site identity', function () {
    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.rum_unidentified', Mockery::type('array'));

    $this->postJson('/api/public/analytics/rum', ['ttfb' => 120])
        ->assertStatus(200);
});

// --- #W3-SEC-1: the body no longer decides which site a timing belongs to -------

it('refuses a beacon naming a site that does not exist', function () {
    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.rum_unverified', Mockery::on(function (array $context) {
            // Nothing resolved, so nobody's site was impersonated — a stale
            // subdomain or a fishing sweep, not a spoof of a live tenant.
            expect($context['resolved'])->toBeFalse();

            return true;
        }));

    $this->withHeader('Origin', 'https://no-such-site.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/rum', [
            'subdomain' => 'no-such-site',
            'ttfb' => 120,
        ])->assertStatus(200);
});

it('refuses a beacon for a real site sent from somebody else\'s origin', function () {
    createTenant('victim-site');

    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.rum_unverified', Mockery::on(function (array $context) {
            // Resolved but not vouched for: this is the shape of an actual
            // attempt to attribute timings to a live tenant.
            expect($context['resolved'])->toBeTrue();

            return true;
        }));

    $this->withHeader('Origin', 'https://attacker.example.com')
        ->postJson('/api/public/analytics/rum', [
            'subdomain' => 'victim-site',
            'ttfb' => 9999,
        ])->assertStatus(200);
});

it('refuses a beacon for a real site that carries no origin at all', function () {
    createTenant('no-origin-site');

    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.rum_unverified', Mockery::type('array'));

    // Fails closed, matching originAllowed() everywhere else: every legitimate
    // caller is a browser POSTing from the sitepage, and a browser always emits
    // Origin on a POST. Absence means the caller is not one.
    $this->postJson('/api/public/analytics/rum', [
        'subdomain' => 'no-origin-site',
        'ttfb' => 120,
    ])->assertStatus(200);
});

it('refuses a malformed handle at the shape gate, before the database is consulted', function (string $handle) {
    Log::shouldReceive('info')->never();
    // rum_unidentified, NOT rum_unverified: the two labels are the difference
    // between "a sender is broken" and "someone is naming other people's sites",
    // and a malformed handle can only ever be the first.
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.rum_unidentified', Mockery::type('array'));

    DB::connection('pgsql')->flushQueryLog();
    DB::connection('pgsql')->enableQueryLog();

    $this->withHeader('Origin', 'https://shape-gate.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/rum', [
            'subdomain' => $handle,
            'ttfb' => 120,
        ])->assertStatus(200);

    // The point of a shape gate is that visitor-authored text never becomes a
    // query predicate. Drop it and arbitrary body content reaches site.sites.
    $touchedSites = collect(DB::connection('pgsql')->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'sites') || str_contains($sql, 'users'))
        ->all();

    expect($touchedSites)->toBe([]);
})->with([
    'spaces' => 'not a handle',
    'sql-ish punctuation' => "x' or '1'='1",
    'path traversal' => '../../etc/passwd',
    // 63 is the DNS label ceiling; 64 is a string no real subdomain can be.
    'longer than a DNS label' => str_repeat('a', 64),
]);

it('logs the resolved site\'s canonical subdomain, not the spelling the beacon sent', function () {
    $tenant = createTenant('drifted-handle');

    // Handle and subdomain are the same value at signup, but a subdomain change
    // moves one and not the other, and the inline beacon keeps sending `handle`.
    // resolveSiteFromData()'s user-handle fallback is what still finds the site;
    // the log line must bucket under the site, or one tenant's timings split in
    // two and neither series is comparable to itself across the rename.
    DB::connection('pgsql')->table('site.sites')
        ->where('id', $tenant->site->id)
        ->update(['subdomain' => 'renamed-site']);

    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['handle'])->toBe(hash('sha256', 'renamed-site'));

            return true;
        }));

    $this->withHeader('Origin', 'https://renamed-site.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/rum', [
            'handle' => 'drifted-handle',
            'ttfb' => 120,
        ])->assertStatus(200);
});
