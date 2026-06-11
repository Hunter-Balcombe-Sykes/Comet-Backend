<?php

use App\Http\Middleware\SecureHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| SecureHeaders middleware + exception-path coverage
|--------------------------------------------------------------------------
| Pins three things:
|   1. CSP shape (default-src 'none' for public API, relaxed for Horizon).
|   2. CORS allowlist + pattern matching, plus Vary: Origin discipline.
|   3. Error responses get the full header set (regression guard for #P2-40,
|      which historically shipped 4xx/5xx un-headered because the exception
|      renderer ran after middleware unwound).
*/

function runSecureHeaders(string $path, array $headers = []): Response
{
    $middleware = new SecureHeaders;
    $request = Request::create($path, 'GET', server: collect($headers)
        ->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])
        ->all());
    $next = fn () => new Response('ok');

    return $middleware->handle($request, $next);
}

it('locks down CSP to default-src none on non-horizon paths and includes form-action + base-uri', function () {
    // report-uri is intentionally absent: JSON API responses never trigger CSP,
    // so it would only add bytes for zero reports.
    $csp = runSecureHeaders('/api/health')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'none'")
        ->toContain("form-action 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('report-uri');
});

it('relaxes CSP for the horizon root path and pins form-action, base-uri, and report-uri', function () {
    // Horizon is the only HTML surface, so it is the only place where CSP
    // evaluates and where report-uri can ever produce traffic.
    $csp = runSecureHeaders('/horizon')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("'unsafe-inline'")
        ->toContain('fonts.bunny.net')
        ->toContain('data:')
        ->toContain("frame-ancestors 'none'")
        ->toContain("form-action 'self'")
        ->toContain("base-uri 'self'")
        ->toContain('report-uri /api/internal/csp-report');
});

it("includes 'unsafe-eval' in horizon script-src for Vue's runtime template compiler", function () {
    // Horizon's app.js compiles Vue templates at runtime via dynamic code construction.
    // Without 'unsafe-eval' in script-src, the dashboard throws EvalError at mount.
    $csp = runSecureHeaders('/horizon/dashboard')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");
});

it('sets the other security headers identically on both API and Horizon paths', function () {
    foreach (['/api/health', '/horizon', '/horizon/jobs/pending'] as $path) {
        $headers = runSecureHeaders($path)->headers;

        expect($headers->get('X-Frame-Options'))->toBe('DENY');
        expect($headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
        expect($headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()');
    }
});

it('omits HSTS in the testing environment (keeps tests env-agnostic)', function () {
    $headers = runSecureHeaders('/api/health')->headers;

    expect($headers->has('Strict-Transport-Security'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CORS allowlist + pattern matching
|--------------------------------------------------------------------------
*/

it('echoes back an allowlisted exact origin and adds Vary: Origin', function () {
    config()->set('partna.frontend_origins', ['https://app.partna.au']);

    $headers = runSecureHeaders('/api/health', ['Origin' => 'https://app.partna.au'])->headers;

    expect($headers->get('Access-Control-Allow-Origin'))->toBe('https://app.partna.au');
    expect($headers->get('Vary'))->toContain('Origin');
});

it('echoes back an origin matched by a wildcard pattern', function () {
    config()->set('partna.frontend_origins', []);
    config()->set('cors.allowed_origins_patterns', ['#^https://[a-z0-9-]+\.partna\.au$#i']);

    $headers = runSecureHeaders('/api/health', ['Origin' => 'https://joshs-handle.partna.au'])->headers;

    expect($headers->get('Access-Control-Allow-Origin'))->toBe('https://joshs-handle.partna.au');
    expect($headers->get('Vary'))->toContain('Origin');
});

it('does NOT echo back an origin that is not in the allowlist or patterns', function () {
    config()->set('partna.frontend_origins', ['https://app.partna.au']);
    config()->set('cors.allowed_origins_patterns', ['#^https://[a-z0-9-]+\.partna\.au$#i']);

    $headers = runSecureHeaders('/api/health', ['Origin' => 'https://evil.example.com'])->headers;

    expect($headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    // Vary: Origin is still set, so caches don't bleed across origins.
    expect($headers->get('Vary'))->toContain('Origin');
});

it('rejects subdomain-confusion attempts against the *.partna.au pattern', function () {
    config()->set('partna.frontend_origins', []);
    config()->set('cors.allowed_origins_patterns', ['#^https://[a-z0-9-]+\.partna\.au$#i']);

    $headers = runSecureHeaders('/api/health', ['Origin' => 'https://evil.partna.au.attacker.com'])->headers;

    expect($headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('adds no CORS headers when the request has no Origin', function () {
    config()->set('partna.frontend_origins', ['https://app.partna.au']);

    $headers = runSecureHeaders('/api/health')->headers;

    expect($headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    expect($headers->has('Vary'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Exception path — error responses must carry the full header set (#P2-40)
|--------------------------------------------------------------------------
| Drives the actual HTTP stack so the bootstrap/app.php exception renderer
| runs. Hits a route guaranteed not to exist; expects 404 + headers.
*/

it('emits security headers on 404 error responses', function () {
    $response = $this->getJson('/api/this-route-does-not-exist');

    $response->assertStatus(404);
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('emits CORS headers on error responses for an allowlisted origin (#P3-11 regression)', function () {
    config()->set('partna.frontend_origins', ['https://app.partna.au']);

    $response = $this->getJson('/api/this-route-does-not-exist', [
        'Origin' => 'https://app.partna.au',
    ]);

    $response->assertStatus(404);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.partna.au');
    expect($response->headers->get('Vary'))->toContain('Origin');
});

/*
|--------------------------------------------------------------------------
| CSP report endpoint
|--------------------------------------------------------------------------
*/

it('accepts CSP violation reports, logs at error level, and returns 204', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('csp.violation', Mockery::on(fn ($ctx) => isset($ctx['report']) && is_array($ctx['report'])));

    $payload = ['csp-report' => ['violated-directive' => "script-src 'self'", 'blocked-uri' => 'https://evil.example.com/x.js']];

    $response = $this->postJson('/api/internal/csp-report', $payload);

    $response->assertStatus(204);
});

it('truncates oversized CSP report string fields before logging', function () {
    $longUri = 'https://evil.example.com/'.str_repeat('a', 5000);
    $logged = null;
    Log::shouldReceive('error')
        ->once()
        ->with('csp.violation', Mockery::on(function ($ctx) use (&$logged) {
            $logged = $ctx;

            return true;
        }));

    $this->postJson('/api/internal/csp-report', ['blocked-uri' => $longUri])
        ->assertStatus(204);

    // Cap is 2048 chars + ellipsis (3-byte UTF-8) — proves the payload normaliser ran.
    expect(strlen($logged['report']['blocked-uri']))->toBeLessThanOrEqual(2051);
    expect($logged['report']['blocked-uri'])->toEndWith('…');
});
