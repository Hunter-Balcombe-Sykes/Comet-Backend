<?php

use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use App\Services\Redis\Exceptions\RedisUnavailableException;
use App\Services\Redis\RedisRequestBreaker;
use Illuminate\Support\Facades\Route;

/**
 * C — selective fail-closed revocation (`revocation.strict`).
 *
 * The contract under test has TWO halves and both must hold, or the change is
 * worthless:
 *
 *   1. A strict route 503s when the revocation blocklist could not answer.
 *   2. A non-strict route STILL 200s under the identical condition.
 *
 * Half 2 is what separates this from blanket fail-closed, which the design
 * brief rejects outright — so it is asserted against the same throwing service
 * in the same file, not left implicit.
 *
 * These tests drive the REAL VerifySupabaseJwt through the REAL middleware
 * pipeline with a real signed JWT. They deliberately do NOT use actingAsUser(),
 * which stubs the verifier out and would make the whole file vacuous.
 */

/** Sign an RS256 JWT that passes genuine JWKS verification. */
function buildStrictRevocationJwt(string $kid, string $privPem, array $claims): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    $sigInput = $header.'.'.$payload;
    openssl_sign($sigInput, $sig, $privPem, OPENSSL_ALGO_SHA256);

    return $sigInput.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

/**
 * Mint a valid token, publish a matching JWKS, and bind a revocation service
 * that behaves as $isRevoked dictates — either returning a bool or throwing.
 *
 * @param  bool|Throwable  $isRevoked  what TokenRevocationService::isRevoked does
 */
function bindStrictRevocationAuth(bool|Throwable $isRevoked): string
{
    $kid = 'strict-kid-'.uniqid();
    $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($privKey, $privPem);
    $details = openssl_pkey_get_details($privKey);

    $jwks = ['keys' => [[
        'kty' => 'RSA',
        'kid' => $kid,
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
        'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
    ]]];

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $expectation = $revocation->shouldReceive('isRevoked');
    $isRevoked instanceof Throwable
        ? $expectation->andThrow($isRevoked)
        : $expectation->andReturn($isRevoked);
    // Session tracking is a separate best-effort side-effect; it must not be
    // what decides this test's outcome either way.
    $revocation->shouldReceive('trackForUser');
    app()->instance(TokenRevocationService::class, $revocation);

    return buildStrictRevocationJwt($kid, $privPem, [
        'sub' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'session_id' => 'sess-strict-'.uniqid(),
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
    ]);
}

beforeEach(function () {
    config([
        'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        'supabase.jwks_fail_closed' => true,
    ]);

    // The two halves of the contract, side by side on the same verifier.
    //
    // The `api/` prefix and the `api` group are BOTH load-bearing, not
    // decoration. bootstrap/app.php's exception renderer short-circuits on
    // `! $request->is('api/*')` and returns null, handing the exception to
    // Laravel's default renderer — which drops the 503 to a 500 and loses the
    // Retry-After header. A test route outside `api/` would therefore assert
    // the wrong contract for every real strict route, all of which live under
    // `api/`.
    Route::middleware(['api', 'supabase.jwt', 'revocation.strict'])
        ->get('/api/__test/strict', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'supabase.jwt'])
        ->get('/api/__test/lenient', fn () => response()->json(['ok' => true]));
});

it('503s on a strict route when the revocation store is unreachable', function () {
    $jwt = bindStrictRevocationAuth(new RedisException('Connection refused'));

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/strict')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5')
        ->assertJson(['message' => 'Service temporarily unavailable. Please try again shortly.']);
});

it('still serves a NON-strict route under the identical outage', function () {
    // Same throwing service, same token, same request — only the route's
    // middleware differs. This is the assertion that proves "selective".
    $jwt = bindStrictRevocationAuth(new RedisException('Connection refused'));

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/lenient')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('serves a strict route normally when the revocation check answers', function () {
    // Guards against the gate being trivially closed: if this ever 503s, the
    // two tests above are passing for the wrong reason.
    $jwt = bindStrictRevocationAuth(false);

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/strict')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('still 401s a genuinely revoked session on a strict route, not 503', function () {
    // A revoked session is an ANSWER, not an outage. Conflating the two would
    // tell the client to retry a session that will never work again.
    $jwt = bindStrictRevocationAuth(true);

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/strict')
        ->assertStatus(401)
        ->assertJson(['code' => 'session_revoked']);
});

it('fails a strict route CLOSED when the E request breaker is already open', function () {
    // The interaction the brief calls the easiest thing to get wrong: once the
    // breaker is open, GuardedPhpRedisConnection throws RedisUnavailableException
    // INSTEAD of issuing the command. The strict route must not inherit that
    // skip as "not revoked". Arm and trip the real singleton so the scenario is
    // the real one, and throw the exact exception type the guarded connection
    // raises — RedisUnavailableException extends RedisException, so a narrower
    // catch in VerifySupabaseJwt would still swallow it and this test pins that.
    $breaker = app(RedisRequestBreaker::class);
    $breaker->arm();
    $breaker->trip(new RedisException('read error on connection'), 'app', 'exists');
    expect($breaker->isOpen())->toBeTrue();

    $jwt = bindStrictRevocationAuth(
        RedisUnavailableException::forSkippedCommand('app', 'exists', 'breaker open'),
    );

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/strict')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

it('401s rather than 503s when the token itself is invalid', function () {
    // Ordering guard: an invalid token is the CLIENT's problem and must be
    // rejected by the verifier before the strict gate is reached. If this ever
    // returns 503 the middleware has been placed ahead of VerifySupabaseJwt.
    bindStrictRevocationAuth(false);

    $this->withHeader('Authorization', 'Bearer not-a-jwt')
        ->getJson('/api/__test/strict')
        ->assertStatus(401);
});

it('503s when the strict gate is reached with no verifier ahead of it', function () {
    // The bypass defence. The attribute defaults to false when ABSENT, not just
    // when false — so a strict route reachable by some path that skips
    // VerifySupabaseJwt fails closed instead of passing. This makes the
    // fail-safe a property of construction rather than of route-list hygiene.
    Route::middleware(['revocation.strict'])
        ->get('/api/__test/strict-unguarded', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/__test/strict-unguarded')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

/**
 * Wiring guard. The tests above prove the middleware BEHAVES correctly; this
 * proves it is actually ATTACHED to the eight surfaces Josh signed off, and —
 * just as important — that it has not crept onto routes deliberately left
 * fail-open. Without this, someone could drop `revocation.strict` from a route
 * file and every behavioural test above would still pass.
 *
 * Signed off 2026-08-05; see
 * docs/superpowers/plans/2026-08-05-auth-selective-failclosed-PLAN.md §3.
 */
it('applies revocation.strict to exactly the signed-off surfaces', function () {
    $strict = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('revocation.strict', $r->gatherMiddleware(), true))
        ->map(fn ($r) => $r->uri())
        ->unique();

    // Every non-staff surface on the list, by exact URI.
    expect($strict)->toContain('api/account/mfa/factors/{factorId}');
    expect($strict)->toContain('api/me/deletion/request');
    expect($strict)->toContain('api/me/deletion/confirm');
    expect($strict)->toContain('api/me/deletion/cancel');
    expect($strict)->toContain('api/me/data-export');
    expect($strict)->toContain('api/sessions/logout-others');
    expect($strict)->toContain('api/sessions/{sessionId}');

    // The whole staff group inherits it from one middleware list.
    expect($strict->filter(fn ($u) => str_starts_with($u, 'api/staff')))->not->toBeEmpty();

    // Deliberate exclusions. Asserted individually — `not->toContain($a, $b)` is
    // variadic and means "not BOTH", which passes vacuously whenever either is
    // absent, so one assertion per URI is the only honest form here.
    expect($strict)->not->toContain('api/site');                        // main dashboard save
    expect($strict)->not->toContain('api/sessions');                    // read-only
    expect($strict)->not->toContain('api/sessions/logout');             // logging yourself out isn't damage
    expect($strict)->not->toContain('api/me/site/reclaim-handle');      // recoverable identity change
    expect($strict)->not->toContain('api/site/custom-domain');          // reversible, DNS-gated
    expect($strict)->not->toContain('api/site/custom-domain/verify');
    expect($strict)->not->toContain('api/site/custom-domain/primary');
});
