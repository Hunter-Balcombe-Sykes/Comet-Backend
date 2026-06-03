<?php

use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    setupSubdomainAliasesTable();
    Cache::flush();
    Config::set('partna.throttle.enabled', false);

    // Bind a mock SiteCacheService that always returns null (cache miss / no site found).
    // This lets us test the controller's header-validation logic in isolation without
    // needing the site.public_site_payload DB view that doesn't exist in the SQLite test
    // DB. Tests that need a live payload hit should use a separate test class.
    $mock = Mockery::mock(SiteCacheService::class);
    $mock->shouldReceive('getPublicSitePayload')->andReturn(null);
    app()->instance(SiteCacheService::class, $mock);
});

// ── Validation tests for X-Site-Subdomain header in showByHeader ──────────────

it('returns 400 when X-Site-Subdomain header is missing', function () {
    $this->getJson('/api/public/site-by-slug')->assertStatus(400);
});

it('returns 400 for a subdomain that is too long (>63 chars)', function () {
    $longSubdomain = str_repeat('a', 64); // 64 chars — one over the limit

    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => $longSubdomain,
    ])->assertStatus(400);
});

it('returns 400 for a subdomain with invalid chars (underscore)', function () {
    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'invalid_handle',
    ])->assertStatus(400);
});

it('returns 400 for a subdomain with a forward slash', function () {
    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'some/path',
    ])->assertStatus(400);
});

it('returns 400 for a 4 KB garbage header', function () {
    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => str_repeat('x', 4096),
    ])->assertStatus(400);
});

it('returns 400 for a subdomain containing a dot', function () {
    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'sub.domain',
    ])->assertStatus(400);
});

it('passes validation and reaches the lookup for a valid subdomain', function () {
    // No site seeded, so we expect 404 (site not found) — not 400.
    // This confirms the header passed validation and the lookup ran.
    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'valid-handle',
    ])->assertStatus(404);
});

it('accepts a 63-char subdomain (maximum allowed length)', function () {
    $maxSubdomain = str_repeat('a', 63); // exactly 63 — should pass validation, hit 404

    $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => $maxSubdomain,
    ])->assertStatus(404);
});
