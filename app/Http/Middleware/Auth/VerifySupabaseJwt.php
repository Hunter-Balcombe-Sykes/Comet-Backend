<?php

namespace App\Http\Middleware\Auth;

use App\Exceptions\Auth\JwksUnavailableException;
use App\Services\Cache\CacheLockService;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// V2: JWT authentication via Supabase JWKS (asymmetric). Falls back to Auth Server query. All authenticated routes require this.
class VerifySupabaseJwt
{
    /**
     * Per-request memo of resolved Key objects keyed by kid. Avoids redundant
     * APCu lookups if the same JWT is verified twice within one request — short-lived
     * by definition, so no bounding is required.
     *
     * @var array<string, Key>
     */
    private static array $keysByKid = [];

    /**
     * APCu key prefix for cached PEM-encoded public keys. APCu is shared across
     * every PHP-FPM worker in the same container, so newly-spawned workers (a
     * common occurrence under Laravel Cloud autoscaling, where workers idle out
     * within seconds during low traffic) skip the 150-300ms JWK::parseKeySet()
     * path entirely. APCu can't store OpenSSLAsymmetricKey resources directly,
     * so we cache the PEM string and rebuild the Key object per-request — the
     * openssl_pkey_get_public() call inside JWT::decode is a few ms.
     */
    private const APCU_KEY_PREFIX = 'partna:jwks:pem:';

    /**
     * APCu TTL on cached PEMs. Independent of the JWKS Redis cache TTL — this
     * only bounds how long a kid lingers after Supabase retires it. Old kids
     * stop appearing in incoming JWTs (Supabase signs only with current keys),
     * so a stale entry is harmless until evicted.
     */
    private const APCU_TTL_SECONDS = 3600;

    public function __construct(private CacheLockService $cacheLock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->getBearerToken($request);
        if (! $token) {
            return response()->json(['message' => 'Missing Bearer token'], 401);
        }

        // Resolved once so both JWKS-fail and auth-server-fail logs in the same
        // request share the same value — critical for Nightwatch trace correlation.
        $requestId = $request->header('X-Request-Id', (string) str()->uuid());

        // 1) Try verify via JWKS (asymmetric signing)
        try {
            $claims = $this->verifyWithJwks($token);

            // Validate issuer/audience (extra safety)
            if (! $this->claimsMatchConfig($claims)) {
                return response()->json(['message' => 'Invalid token claims'], 401);
            }

            $uid = $claims['sub'] ?? null;
            if (! $uid) {
                return response()->json(['message' => 'Token missing sub'], 401);
            }

            $this->setSupabaseContext($request, $uid, $claims);

            return $next($request);
        } catch (\Throwable $e) {
            // Log every JWKS failure before falling back — repeated infra-level failures
            // (e.g. network blocking JWKS fetches) are security-relevant and must be visible.
            Log::warning('JWT JWKS verification failed, falling back to auth server', [
                'request_id' => $requestId,
                'operation' => __METHOD__,
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            // Fail-closed mode (default): refuse to fall back to Auth-Server
            // during a JWKS outage. Set SUPABASE_JWKS_FAIL_CLOSED=false only to
            // re-enable the reduced-security Auth-Server fallback (legacy
            // shared-secret projects); production refuses to boot when false.
            if (config('supabase.jwks_fail_closed', true)) {
                // A JWKS infrastructure outage is genuinely "service
                // unavailable" → 503 so the client retries. A token-level
                // failure (expired, bad signature, malformed) is the client's
                // fault → 401 so it re-authenticates instead of retrying.
                if ($e instanceof JwksUnavailableException) {
                    return response()->json(['message' => 'Service unavailable'], 503);
                }

                return response()->json(['message' => 'Invalid token'], 401);
            }

            // 2) Fallback for legacy/shared-secret setups:
            // Supabase recommends verifying by calling Auth server /user. :contentReference[oaicite:2]{index=2}
            try {
                $uid = $this->verifyWithAuthServer($token);
                if (! $uid) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                $this->setSupabaseContext($request, $uid);

                return $next($request);
            } catch (\Throwable $e2) {
                Log::warning('JWT verification failed', [
                    'request_id' => $requestId,
                    'operation' => __METHOD__,
                    'reason' => $e2->getMessage(),
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'Invalid token'], 401);
            }
        }
    }

    private function getBearerToken(Request $request): ?string
    {
        $auth = (string) $request->header('Authorization', '');
        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return trim(substr($auth, 7));
    }

    private function setSupabaseContext(Request $request, string $uid, ?array $claims = null): void
    {
        $request->attributes->set('supabase_uid', $uid);

        if ($claims !== null) {
            $request->attributes->set('supabase_claims', $claims);
            // Hot-accessed claims promoted to top-level attributes so policies
            // and middleware don't reparse $claims on every check.
            $request->attributes->set('supabase_aal', $claims['aal'] ?? 'aal1');
            $request->attributes->set('supabase_amr', $claims['amr'] ?? []);
            $request->attributes->set('supabase_session_id', $claims['session_id'] ?? null);
        } else {
            // Auth-Server fallback path: no claims available. Default to aal1
            // so downstream policies fail safe (treat as not-MFA-verified).
            $request->attributes->set('supabase_aal', 'aal1');
            $request->attributes->set('supabase_amr', []);
            $request->attributes->set('supabase_session_id', null);
        }

        // Nightwatch falls back to hidden context when no Laravel auth guard is resolved.
        if (class_exists(\Laravel\Nightwatch\Compatibility::class)) {
            \Laravel\Nightwatch\Compatibility::addUserIdToContext($uid);
        }
    }

    private function verifyWithJwks(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT format');
        }

        $header = json_decode($this->b64urlDecode($parts[0]), true) ?: [];

        // Reject non-asymmetric algorithms before key lookup to prevent algorithm confusion attacks
        // (e.g. HS256 signed with the public key as the HMAC secret). RS256 and ES256 are both
        // asymmetric — Supabase issues ES256 by default for projects on migrated signing keys, so
        // both must be accepted here. NEVER add HS256 to this allowlist.
        $alg = $header['alg'] ?? null;
        if (! in_array($alg, ['RS256', 'ES256'], true)) {
            throw new \RuntimeException('JWT alg must be RS256 or ES256, got: '.($alg ?? 'none'));
        }

        $kid = $header['kid'] ?? null;
        if (! $kid) {
            throw new \RuntimeException('JWT header missing kid');
        }

        $key = $this->resolveSigningKey((string) $kid, (string) $alg);

        // Decode + verify signature + exp/nbf automatically.
        // Restore leeway in finally — JWT::$leeway is process-wide static state that
        // would bleed into every subsequent JWT::decode in this worker without restoration.
        $priorLeeway = JWT::$leeway;
        JWT::$leeway = 60; // Supabase tokens can arrive with up to ~60s clock skew
        try {
            $decoded = JWT::decode($jwt, $key);
        } finally {
            JWT::$leeway = $priorLeeway;
        }

        return json_decode(json_encode($decoded), true) ?: [];
    }

    /**
     * Resolve a JWT signing key for a given kid using a layered cache:
     *
     *   1. Per-request static memo (~0ms)
     *   2. APCu shared memory (~0.05ms) — survives PHP-FPM worker recycling
     *   3. Redis JWKS cache + JWK::parseKeySet() (~150-300ms for ES256)
     *
     * The third layer only fires on a fresh container or when the kid has aged
     * out of APCu. Once it fires, *every* parsed kid is written back to APCu so
     * subsequent requests for any kid in the set skip parseKeySet entirely.
     *
     * Why cache by kid (not by JWKS hash): Supabase issues a unique kid per
     * signing key. A rotation produces a new kid; old kids simply stop appearing
     * in incoming JWTs. A forged token presenting a stale kid still fails
     * signature verification, so retired kids in APCu are harmless until evicted.
     */
    private function resolveSigningKey(string $kid, string $alg): Key
    {
        if (isset(self::$keysByKid[$kid])) {
            return self::$keysByKid[$kid];
        }

        $cached = $this->apcuFetch(self::APCU_KEY_PREFIX.$kid);
        if (is_array($cached) && isset($cached['pem'], $cached['alg']) && is_string($cached['pem'])) {
            $key = new Key($cached['pem'], (string) $cached['alg']);
            self::$keysByKid[$kid] = $key;

            return $key;
        }

        // Cold path: parseKeySet is the 150-300ms ES256 cost we're trying to avoid.
        $jwks = $this->fetchJwks();

        // A JWKS body that fetched + parsed as JSON but carries malformed key
        // material (bad n/e, unsupported kty) is still an infrastructure
        // failure, not a token problem — classify it as a JWKS outage so the
        // caller answers 503, not 401.
        try {
            $parsed = JWK::parseKeySet($jwks);
        } catch (\Throwable $e) {
            throw $this->jwksOutage($e);
        }

        // A token presenting a kid absent from the current JWKS is token-level
        // (forged kid, or a token signed by an already-retired key) — stays 401.
        if (! isset($parsed[$kid])) {
            throw new \RuntimeException('No matching JWKS key for kid');
        }

        // Warm APCu for every kid in the set — the next authenticated request,
        // regardless of which kid it presents, then hits APCu instead of parseKeySet.
        //
        // §28.17 SEC-3 fix: derive alg per-kid from the parsed Key, NOT from the
        // inbound JWT's $alg. A JWKS can mix RS256 + ES256 kids; warming every
        // entry with the inbound alg poisoned the cache for the other algorithm
        // and made signature verification fail for valid tokens until the cache
        // entry expired.
        foreach ($parsed as $parsedKid => $parsedKey) {
            $pem = $this->extractPemFromKey($parsedKey);
            if ($pem !== null) {
                $this->apcuStore(
                    self::APCU_KEY_PREFIX.$parsedKid,
                    ['pem' => $pem, 'alg' => $parsedKey->getAlgorithm()],
                );
            }
            self::$keysByKid[$parsedKid] = $parsedKey;
        }

        return $parsed[$kid];
    }

    /**
     * @return array{keys?: array<int, array<string, mixed>>}
     */
    private function fetchJwks(): array
    {
        try {
            return $this->fetchJwksOrThrow();
        } catch (\Throwable $e) {
            throw $this->jwksOutage($e);
        }
    }

    /**
     * Classify a JWKS infrastructure failure (fetch, network, bad response, or
     * malformed key material) as a typed exception, and report it to Nightwatch.
     *
     * Distinct from a bad/forged/expired token — those fail later at signature
     * or claims verification and stay 401. report() surfaces the outage to
     * Nightwatch as an alert (the Log::warning in handle() is breadcrumb-only
     * and would not); throttled to one report per minute so a sustained outage
     * doesn't flood the exception pipeline. The caller throws the result so
     * handle() can answer 503 instead of 401.
     */
    private function jwksOutage(\Throwable $cause): JwksUnavailableException
    {
        $outage = new JwksUnavailableException($cause);

        if (Cache::add('jwt:jwks-failure-reported', true, 60)) {
            report($outage);
        }

        return $outage;
    }

    /**
     * @return array{keys?: array<int, array<string, mixed>>}
     */
    private function fetchJwksOrThrow(): array
    {
        $jwksUrl = config('supabase.jwks_url');
        if (! $jwksUrl) {
            throw new \RuntimeException('Missing SUPABASE_JWKS_URL');
        }

        $jwks = $this->cacheLock->rememberLocked('supabase:jwks', config('supabase.jwks_cache_seconds', 300), function () use ($jwksUrl) {
            $res = Http::timeout(5)->get($jwksUrl);
            if (! $res->ok()) {
                throw new \RuntimeException('Failed to fetch JWKS');
            }

            $payload = $res->json();
            if (! is_array($payload)) {
                // Empty/invalid JSON body — never cache this. Throwing keeps the lock-release
                // path clean and lets the caller's error logging surface the infra problem.
                throw new \RuntimeException('JWKS response did not parse to an array');
            }

            return $payload;
        });

        // If your project isn't using asymmetric keys, JWKS may be empty.
        if (empty($jwks['keys'])) {
            throw new \RuntimeException('JWKS empty');
        }

        return $jwks;
    }

    /**
     * Extract the PEM-encoded public key from a parsed Key. Returns null if the
     * material isn't an OpenSSL key resource — defensive for future algorithm
     * additions; today's RS256/ES256 paths always produce OpenSSLAsymmetricKey.
     */
    private function extractPemFromKey(Key $key): ?string
    {
        $material = $key->getKeyMaterial();
        if (! $material instanceof \OpenSSLAsymmetricKey) {
            return null;
        }

        $details = openssl_pkey_get_details($material);
        if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key'])) {
            return null;
        }

        return $details['key'];
    }

    private function apcuFetch(string $key): mixed
    {
        if (! function_exists('apcu_fetch') || ! ini_get('apc.enabled')) {
            return null;
        }

        $value = apcu_fetch($key, $hit);

        return $hit ? $value : null;
    }

    private function apcuStore(string $key, mixed $value): void
    {
        if (! function_exists('apcu_store') || ! ini_get('apc.enabled')) {
            return;
        }

        @apcu_store($key, $value, self::APCU_TTL_SECONDS);
    }

    private function verifyWithAuthServer(string $jwt): ?string
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $anonKey = (string) config('supabase.anon_key');

        if (! $baseUrl || ! $anonKey) {
            throw new \RuntimeException('Missing SUPABASE_URL or SUPABASE_ANON_KEY');
        }

        // Validate iss/aud from the token payload before calling Auth-Server.
        // Auth-Server verifies the signature, but doesn't protect against cross-project
        // tokens (a valid token from another Supabase project would pass otherwise).
        $claims = $this->extractJwtPayloadClaims($jwt);
        if (! $this->claimsMatchConfig($claims)) {
            return null;
        }

        $res = Http::timeout(5)
            ->withHeaders([
                'apikey' => $anonKey,
                'Authorization' => 'Bearer '.$jwt,
            ])
            ->get($baseUrl.'/auth/v1/user');

        if (! $res->ok()) {
            return null;
        }

        $user = $res->json();
        $uid = $user['id'] ?? null;

        // Reject if Supabase returns a non-UUID — guards against misconfig or unexpected response shapes.
        if (! $uid || ! $this->isValidUuid($uid)) {
            return null;
        }

        return $uid;
    }

    /** Decode JWT payload without signature verification — used only to read claims in the fallback path. */
    private function extractJwtPayloadClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        return json_decode($this->b64urlDecode($parts[1]), true) ?: [];
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function claimsMatchConfig(array $claims): bool
    {
        $issExpected = (string) config('supabase.jwt_issuer');
        $audExpected = (string) config('supabase.jwt_audience');

        // Fail closed on misconfiguration. A blank expected issuer or audience
        // means the Supabase config is missing/unloaded; the old behaviour
        // (skip the check when blank) let a token from ANY Supabase project
        // authenticate. AppServiceProvider::boot() also guards this at boot —
        // this is the defence-in-depth runtime check.
        if ($issExpected === '' || $audExpected === '') {
            return false;
        }

        if (($claims['iss'] ?? null) !== $issExpected) {
            return false;
        }

        $aud = $claims['aud'] ?? null;
        if (is_array($aud)) {
            if (! in_array($audExpected, $aud, true)) {
                return false;
            }
        } elseif ($aud !== $audExpected) {
            return false;
        }

        return true;
    }

    private function b64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($data) ?: '';
    }
}
