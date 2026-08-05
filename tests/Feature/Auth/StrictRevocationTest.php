<?php

use App\Http\Middleware\Auth\RequireVerifiedRevocation;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\IdempotencyKey;
use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use App\Services\Redis\Exceptions\RedisUnavailableException;
use App\Services\Redis\RedisRequestBreaker;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
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
 * Mint a valid token and publish a matching JWKS, WITHOUT touching the
 * revocation service — so a caller can leave the real one in place.
 */
function mintStrictRevocationJwt(): string
{
    return bindStrictRevocationAuth(null);
}

/**
 * Mint a valid token, publish a matching JWKS, and (unless $isRevoked is null)
 * bind a revocation service that behaves as $isRevoked dictates — either
 * returning a bool or throwing.
 *
 * @param  bool|Throwable|null  $isRevoked  what TokenRevocationService::isRevoked does;
 *                                          null leaves the REAL service bound
 */
function bindStrictRevocationAuth(bool|Throwable|null $isRevoked): string
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

    if ($isRevoked !== null) {
        $revocation = Mockery::mock(TokenRevocationService::class);
        $expectation = $revocation->shouldReceive('isRevoked');
        $isRevoked instanceof Throwable
            ? $expectation->andThrow($isRevoked)
            : $expectation->andReturn($isRevoked);
        // Session tracking is a separate best-effort side-effect; it must not be
        // what decides this test's outcome either way.
        $revocation->shouldReceive('trackForUser');
        app()->instance(TokenRevocationService::class, $revocation);
    }

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

it('fails a strict route CLOSED when a real Redis outage trips the E breaker', function () {
    // The interaction the brief calls the easiest thing to get wrong: once
    // RedisRequestBreaker is open, GuardedPhpRedisConnection throws
    // RedisUnavailableException INSTEAD of issuing the command, so isRevoked() is
    // SKIPPED rather than merely slow. The strict route must not inherit that skip
    // as "not revoked".
    //
    // This drives the REAL stack: the real TokenRevocationService (no mock), the
    // real GuardedPhpRedisConnection, against an unroutable Redis. Pre-tripping the
    // singleton by hand would prove nothing — ArmRedisRequestBreaker is prepended as
    // global middleware and arm() sets open=false, so the very first thing the
    // request does is close any breaker the test opened. An earlier version of this
    // test did exactly that and would have passed with all four breaker lines
    // deleted.
    config([
        'database.redis.app.host' => '127.0.0.1',
        'database.redis.app.port' => 1,      // nothing listens here
        'database.redis.app.timeout' => 0.2, // keep the test fast
    ]);

    $jwt = mintStrictRevocationJwt();   // real revocation service left bound

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/strict')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');

    // The load-bearing assertion: the breaker really tripped DURING the request,
    // off a genuine transport failure. Without this the test could pass on any
    // exception at all and would say nothing about the breaker.
    expect(app(RedisRequestBreaker::class)->isOpen())->toBeTrue();
});

it('still serves a NON-strict route when a real Redis outage trips the breaker', function () {
    // The selective property, proven against the real breaker rather than a mock.
    config([
        'database.redis.app.host' => '127.0.0.1',
        'database.redis.app.port' => 1,
        'database.redis.app.timeout' => 0.2,
    ]);

    $jwt = mintStrictRevocationJwt();

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/lenient')
        ->assertOk();
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

it('produces the 503 itself on a throttled strict route, and says so in the log', function () {
    // RevocationUnverifiableException and RateLimiterUnavailableException are
    // byte-identical on the wire — both 503, same message, same Retry-After: 5.
    // So on any throttled route the STATUS alone cannot say which layer answered.
    // Drill 03 nearly recorded a false PASS on exactly that, and the only honest
    // discriminator is the gate's own log line. This asserts it.
    //
    // SCOPE, STATED HONESTLY: this proves the gate answers when a throttle is
    // present on the route. It does NOT prove the gate runs BEFORE the throttle —
    // I could not make the limiter fail inside the test environment (four
    // approaches tried: array store, Cache::swap, a throwing inner RateLimiter,
    // and the documented facade clearResolvedInstance; the test passed with the
    // priority pin removed in every one). The ordering guarantee rests on the
    // priority-list assertion below, which IS mutation-proven: removing the pin
    // fails it. Do not read this test as an ordering guard, and do not weaken
    // that one on the assumption that this covers it.
    Route::middleware(['api', 'supabase.jwt', 'throttle:authenticated', 'revocation.strict'])
        ->get('/api/__test/strict-throttled', fn () => response()->json(['ok' => true]));

    $jwt = bindStrictRevocationAuth(new RedisException('Connection refused'));

    Log::spy();

    $this->withHeader('Authorization', 'Bearer '.$jwt)
        ->getJson('/api/__test/strict-throttled')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg, $ctx = []) => $msg === 'auth.revocation_unverified_on_strict_route')
        ->atLeast()->once();
});

/**
 * Ordering guard for the priority-list pin added 2026-08-05.
 *
 * `revocation.strict` used to be UNLISTED, which left it last — after both
 * throttles. Drill 03 measured the consequence: during a Redis outage every
 * strict route 503'd from FailOpenThrottleRequests before the gate ran, so the
 * protection observed in production was the rate limiter's, not this gate's, and
 * was one FAIL_OPEN_LIMITERS edit from vanishing.
 *
 * Three orderings matter and all three are asserted as RELATIVE positions rather
 * than fixed indices, so unrelated middleware moving does not break this:
 *
 *   VerifySupabaseJwt < RequireVerifiedRevocation   (gate reads its attribute)
 *   RequireVerifiedRevocation < IdempotencyKey      (a replay must not bypass it)
 *   RequireVerifiedRevocation < ThrottleRequests    (the drill finding)
 */
it('pins revocation.strict after the JWT verifier and before idempotency and throttling', function () {
    $kernel = app(Kernel::class);
    $method = new ReflectionMethod($kernel, 'getMiddlewarePriority');
    $method->setAccessible(true);
    $priority = array_values($method->invoke($kernel));

    $at = function (string $class) use ($priority) {
        $i = array_search($class, $priority, true);
        expect($i)->not->toBeFalse("{$class} is missing from the middleware priority list");

        return $i;
    };

    $jwt = $at(VerifySupabaseJwt::class);
    $gate = $at(RequireVerifiedRevocation::class);
    $idem = $at(IdempotencyKey::class);
    $throttle = $at(ThrottleRequests::class);

    expect($jwt)->toBeLessThan($gate);
    expect($gate)->toBeLessThan($idem);
    expect($gate)->toBeLessThan($throttle);
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

    // EVERY staff route, enumerated from the route table — not "at least one".
    //
    // The first draft of this change asserted `->not->toBeEmpty()` here and passed
    // green while 58 of 104 staff routes were unprotected, because routes/api/staff.php
    // has THREE top-level prefix('staff') groups and only one had been edited. A
    // presence check cannot detect a missing group; only an exhaustive one can. If a
    // fourth group is ever added without the gate, this fails and names the routes.
    $allStaff = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/staff'));
    $unprotected = $allStaff
        ->reject(fn ($r) => in_array('revocation.strict', $r->gatherMiddleware(), true))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
        ->values()
        ->all();

    expect($allStaff)->not->toBeEmpty();          // the filter itself must not be broken
    expect($unprotected)->toBe([]);               // and nothing may be left out

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
