<?php

// COV-JWT-1/2/3 (audits/consolidation/2026-08-03-assurance-coverage/CONSOLIDATED.md):
// the 60s SUPABASE_JWT_LEEWAY_SECONDS grace (VerifySupabaseJwt.php:367-373) and the
// missing-kid throw (:358-360) had zero test coverage — `grep -rn "'exp' =>" tests/`
// found 10 hits, all `time() + 3600`; `grep -rn "'nbf'"` found zero hits repo-wide.
//
// The audit's prescribed COV-JWT-1 value is WRONG and must not be copied: it names
// `exp => time() - 10` as a case that should 401. firebase/php-jwt checks expiry as
// `($now - $leeway) >= $exp` (vendor/firebase/php-jwt/src/JWT.php:187), so with this
// middleware's 60s leeway that claim is ACCEPTED (200), not rejected. That value is
// kept here as case 1b — an asserted 200 — because it's the missing other half of
// COV-JWT-2 (leeway applies to `exp` as well as `nbf`, which the finding didn't name).
//
// Layer: route-level, through the real HTTP kernel on an ad-hoc route carrying only
// `supabase.jwt` — a 401 from this middleware and a 401 from `RequireAal2` are
// indistinguishable by status code alone, so nothing else may sit in the pipeline.
//
// ⚠️ NEVER use actingAsUser()/actingAsStaff() in this file. Both rebind
// app()->bind(VerifySupabaseJwt::class, ...) to a stub (tests/Pest.php:136, :218) —
// using either would silently replace the exact class under test with something that
// never parses a claim, making every assertion below vacuous. Mocks are injected via
// app()->instance(CacheLockService::class, ...) / app()->instance(TokenRevocationService::class, ...)
// instead, which the container hands to the REAL VerifySupabaseJwt constructor.

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Services\Auth\TokenRevocationService;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    // Private static memo of resolved Key objects — not reset by the per-test app
    // refresh (it's not instance state). A stale kid here would let one test's
    // signing key silently answer another test's request.
    $ref = new ReflectionClass(VerifySupabaseJwt::class);
    $prop = $ref->getProperty('keysByKid');
    $prop->setValue(null, []);

    config([
        'supabase.jwks_url' => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer' => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        // Production default. With false, a JWKS failure falls through to the
        // Auth-Server fallback (an Http::get) — a 401 there would be attributable
        // to that fallback, not to the claim under test.
        'supabase.jwks_fail_closed' => true,
        'supabase.require_session_id' => true,
        // jwt_leeway_seconds is deliberately NOT pinned here — it stays at its
        // shipped config default so case 0b can assert that default directly.
    ]);

    Route::middleware(['supabase.jwt'])
        ->get('/__test/jwt-claim-timing', fn () => response()->json(['ok' => true]));
});

/**
 * Build a base64url-encoded RS256 JWT signed with $privPem, from an explicit header
 * array. Case 4 needs a header with no `kid` key at all (not an empty one) to hit the
 * `! $kid` throw at VerifySupabaseJwt.php:358 before any JWKS lookup happens.
 */
function buildClaimTimingRs256JwtFromHeader(array $header, string $privPem, array $claims): string
{
    // Builders strip the base64 `=` padding; the middleware's b64urlDecode (:684-693)
    // re-adds it before decoding. Do not "fix" this — a padded segment is not a valid JWT.
    $h = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    $sigInput = $h.'.'.$p;
    openssl_sign($sigInput, $sig, $privPem, OPENSSL_ALGO_SHA256);

    return $sigInput.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

/** Build a standard RS256 JWT header carrying $kid. */
function buildClaimTimingRs256Jwt(string $kid, string $privPem, array $claims): string
{
    return buildClaimTimingRs256JwtFromHeader(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid], $privPem, $claims);
}

/** Build the JWK entry for an RSA public key (n/e extracted from $privKey). */
function buildClaimTimingRsaJwk(string $kid, OpenSSLAsymmetricKey $privKey): array
{
    $details = openssl_pkey_get_details($privKey);

    return [
        'kty' => 'RSA',
        'kid' => $kid,
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
        'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
    ];
}

/**
 * Fresh RSA keypair + single-key JWKS per call — every case gets its own kid so the
 * static keysByKid memo (reset in beforeEach, but shared process-wide otherwise)
 * can never leak a key across cases.
 *
 * @return array{0: string, 1: string, 2: array}
 */
function claimTimingKeypair(): array
{
    $kid = 'kid-'.Str::uuid();
    $priv = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($priv, $privPem);
    $jwks = ['keys' => [buildClaimTimingRsaJwk($kid, $priv)]];

    return [$kid, $privPem, $jwks];
}

/** Base claims shared by every case; each case overrides exactly one key. */
function claimTimingBaseClaims(): array
{
    return [
        'sub' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
        'iat' => time(),
        'exp' => time() + 3600,
        'session_id' => 'sess-claim-timing',
    ];
}

it('case 0: baseline — a well-formed JWT passes through with 200 {"ok":true} — the anchor every 401 below differs from by exactly one claim', function () {
    [$kid, $privPem, $jwks] = claimTimingKeypair();
    $jwt = buildClaimTimingRs256Jwt($kid, $privPem, claimTimingBaseClaims());

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldReceive('isRevoked')->andReturn(false);
    $revocation->shouldReceive('trackForUser');
    app()->instance(TokenRevocationService::class, $revocation);

    // If the route, the mocked JWKS, iss/aud config, sub, or the SEC-2 session_id
    // gate were wrong, THIS goes red — so a 401 in any case below can't be a silent
    // artefact of broken scaffolding. Asserting the body, not just the status, is
    // load-bearing: a 401 with an accidentally-200-shaped status check would pass.
    $this->getJson('/__test/jwt-claim-timing', ['Authorization' => 'Bearer '.$jwt])
        ->assertStatus(200)
        ->assertExactJson(['ok' => true]);
});

it('case 0b: the shipped leeway default is 60s, unpinned by this file — anchors what cases 1-4 exercise behaviourally', function () {
    expect((int) config('supabase.jwt_leeway_seconds'))->toBe(60);
});

it('case 1: exp 120s in the past exceeds the 60s leeway — 401, before the revocation check ever runs', function () {
    Log::spy();

    [$kid, $privPem, $jwks] = claimTimingKeypair();
    $claims = array_merge(claimTimingBaseClaims(), ['exp' => time() - 120]);
    $jwt = buildClaimTimingRs256Jwt($kid, $privPem, $claims);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    // Proves the rejection happens at JWT::decode, upstream of the SEC-2 session_id
    // gate and revocation oracle — a token this rotten never reaches either.
    $revocation->shouldNotReceive('isRevoked');
    $revocation->shouldNotReceive('trackForUser');
    app()->instance(TokenRevocationService::class, $revocation);

    $this->getJson('/__test/jwt-claim-timing', ['Authorization' => 'Bearer '.$jwt])
        ->assertStatus(401);

    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn ($message, $context = []) => $message === 'JWT JWKS verification failed, falling back to auth server'
            && ($context['reason'] ?? null) === 'Expired token');
});

it('case 1b: exp 10s in the past is INSIDE the 60s leeway — 200 (the other, unproven half of COV-JWT-2)', function () {
    [$kid, $privPem, $jwks] = claimTimingKeypair();
    $claims = array_merge(claimTimingBaseClaims(), ['exp' => time() - 10]);
    $jwt = buildClaimTimingRs256Jwt($kid, $privPem, $claims);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldReceive('isRevoked')->once()->andReturn(false);
    $revocation->shouldReceive('trackForUser')->once();
    app()->instance(TokenRevocationService::class, $revocation);

    $this->getJson('/__test/jwt-claim-timing', ['Authorization' => 'Bearer '.$jwt])
        ->assertStatus(200)
        ->assertExactJson(['ok' => true]);
});

it('case 2: nbf 30s in the future is INSIDE the 60s leeway — 200; goes red if the leeway line is ever deleted', function () {
    [$kid, $privPem, $jwks] = claimTimingKeypair();
    $claims = array_merge(claimTimingBaseClaims(), ['nbf' => time() + 30]);
    $jwt = buildClaimTimingRs256Jwt($kid, $privPem, $claims);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    // Proves the token reached decode success and continued past it — isRevoked/
    // trackForUser only run once a claim set is accepted.
    $revocation->shouldReceive('isRevoked')->once()->andReturn(false);
    $revocation->shouldReceive('trackForUser')->once();
    app()->instance(TokenRevocationService::class, $revocation);

    $this->getJson('/__test/jwt-claim-timing', ['Authorization' => 'Bearer '.$jwt])
        ->assertStatus(200)
        ->assertExactJson(['ok' => true]);
});

it('case 3: nbf 300s in the future exceeds the 60s leeway — 401, before the revocation check ever runs', function () {
    Log::spy();

    [$kid, $privPem, $jwks] = claimTimingKeypair();
    $claims = array_merge(claimTimingBaseClaims(), ['nbf' => time() + 300]);
    $jwt = buildClaimTimingRs256Jwt($kid, $privPem, $claims);

    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')->andReturn($jwks);
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldNotReceive('isRevoked');
    $revocation->shouldNotReceive('trackForUser');
    app()->instance(TokenRevocationService::class, $revocation);

    $this->getJson('/__test/jwt-claim-timing', ['Authorization' => 'Bearer '.$jwt])
        ->assertStatus(401);

    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn ($message, $context = []) => $message === 'JWT JWKS verification failed, falling back to auth server'
            // The message interpolates a formatted timestamp — match the prefix only.
            && is_string($context['reason'] ?? null)
            && str_starts_with($context['reason'], 'Cannot handle token with nbf prior to '));
});

it('case 4: a header with no kid at all is rejected before any JWKS lookup — 401', function () {
    Log::spy();

    $priv = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($priv, $privPem);
    // No 'kid' key in the header (not '' — genuinely absent), so the ! $kid check at
    // VerifySupabaseJwt.php:358 throws before resolveSigningKey/JWKS is ever touched.
    $jwt = buildClaimTimingRs256JwtFromHeader(['alg' => 'RS256', 'typ' => 'JWT'], $privPem, claimTimingBaseClaims());

    $cacheLock = Mockery::mock(CacheLockService::class);
    // The throw at :359 precedes resolveSigningKey — a 401 from any other cause
    // would have consulted the JWKS first, so this proves it didn't.
    $cacheLock->shouldNotReceive('rememberLocked');
    app()->instance(CacheLockService::class, $cacheLock);

    $revocation = Mockery::mock(TokenRevocationService::class);
    $revocation->shouldNotReceive('isRevoked');
    $revocation->shouldNotReceive('trackForUser');
    app()->instance(TokenRevocationService::class, $revocation);

    $this->getJson('/__test/jwt-claim-timing', ['Authorization' => 'Bearer '.$jwt])
        ->assertStatus(401);

    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn ($message, $context = []) => $message === 'JWT JWKS verification failed, falling back to auth server'
            && ($context['reason'] ?? null) === 'JWT header missing kid');
});
