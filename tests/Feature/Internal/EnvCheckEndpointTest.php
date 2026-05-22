<?php

use App\Services\Diagnostics\EnvCheckService;
use Illuminate\Support\Facades\Config;

/*
 * Pre-populate every config path the report checks, so individual tests
 * only need to clear the keys they want to assert on. Mirrors the helper
 * used by tests/Feature/Commands/EnvCheckTest.php.
 */
function endpointPlaceholderFor(string $path): string
{
    return match ($path) {
        // Framework eager-resolves these during HTTP boot — junk values break
        // cookie encryption / cache resolution before the route is even hit.
        'app.key' => (string) config('app.key'),
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'session.driver' => 'array',
        default => 'set',
    };
}

beforeEach(function () {
    Config::set('partna.internal_env_check_token', 'test-secret');

    foreach (EnvCheckService::REQUIRED as $group => $entries) {
        foreach ($entries as $path => $envLabel) {
            Config::set($path, endpointPlaceholderFor($path));
        }
    }
    foreach (EnvCheckService::RECOMMENDED as $group => $entries) {
        foreach ($entries as $path => $envLabel) {
            Config::set($path, endpointPlaceholderFor($path));
        }
    }
});

it('returns 200 with status=ok JSON when token is correct and all required is set', function () {
    $response = $this->withHeader('X-Internal-Token', 'test-secret')
        ->getJson('/api/internal/env-check');

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'required_missing' => [],
        ]);
});

it('returns status=fail with the missing keys listed when required is missing', function () {
    Config::set('services.cloudflare.api_token', null);

    $response = $this->withHeader('X-Internal-Token', 'test-secret')
        ->getJson('/api/internal/env-check');

    $response->assertOk();
    expect($response->json('status'))->toBe('fail');
    expect($response->json('required_missing'))->toContain('services.cloudflare.api_token');
});

it('returns 403 when the token header is missing', function () {
    $response = $this->getJson('/api/internal/env-check');

    $response->assertForbidden();
});

it('returns 403 when the token header is wrong', function () {
    $response = $this->withHeader('X-Internal-Token', 'nope')
        ->getJson('/api/internal/env-check');

    $response->assertForbidden();
});

it('returns 503 when no token is configured server-side', function () {
    Config::set('partna.internal_env_check_token', null);

    $response = $this->withHeader('X-Internal-Token', 'anything')
        ->getJson('/api/internal/env-check');

    $response->assertStatus(503);
});

it('rejects an empty-string token even if the header is sent', function () {
    Config::set('partna.internal_env_check_token', 'test-secret');

    $response = $this->withHeader('X-Internal-Token', '')
        ->getJson('/api/internal/env-check');

    $response->assertForbidden();
});
