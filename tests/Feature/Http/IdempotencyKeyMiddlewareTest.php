<?php

use App\Http\Middleware\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    // Array cache driver in tests (per phpunit.xml CACHE_STORE=array) — flush
    // between cases so cached idempotency responses don't leak across tests.
    Cache::flush();
});

/**
 * Build a Request that looks like one a route would dispatch through the middleware:
 *   - HTTP method (default POST so the middleware doesn't bypass on GET)
 *   - Optional Idempotency-Key header
 *   - supabase_uid already populated (as VerifySupabaseJwt would have done upstream)
 */
function idempotentRequest(string $uri, ?string $key, string $method = 'POST', string $userId = 'user-1'): Request
{
    $req = Request::create($uri, $method);
    if ($key !== null) {
        $req->headers->set('Idempotency-Key', $key);
    }
    $req->attributes->set('supabase_uid', $userId);

    return $req;
}

it('replays the cached response on a duplicate idempotency key', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['order_id' => 'A1B2'], 201);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    $r1 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $r2 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(1);
    expect($r2->getStatusCode())->toBe(201);
    expect(json_decode($r2->getContent(), true))->toBe(['order_id' => 'A1B2']);
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');
    // First response should NOT carry the replay header — only the cached replays.
    expect($r1->headers->get('Idempotency-Replayed'))->toBeNull();
});

it('rejects non-UUID-v4 keys with 400 idempotency_invalid_key', function () {
    $middleware = new IdempotencyKey;
    $handler = fn () => response()->json(['ok' => true]);

    foreach ([
        'not-a-uuid',
        '11111111-2222-3333-4444-555555555555',          // version nibble = 3, not 4
        '11111111-2222-4333-c444-555555555555',          // variant nibble = c, not 8-b
        '1111111122224333844455555555555',               // missing dashes
        '',                                              // already filtered as bypass; only test via header below
    ] as $bad) {
        if ($bad === '') {
            continue;
        }
        $response = $middleware->handle(idempotentRequest('/api/x', $bad), $handler);
        expect($response->getStatusCode())->toBe(400);
        expect(json_decode($response->getContent(), true))
            ->toMatchArray(['code' => 'idempotency_invalid_key']);
    }
});

it('bypasses idempotency for non-mutating methods (GET/HEAD/OPTIONS)', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['ok' => true]);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
        $middleware->handle(idempotentRequest('/api/x', $key, $method), $handler);
        $middleware->handle(idempotentRequest('/api/x', $key, $method), $handler);
    }

    // Three methods × two calls each = six handler invocations; nothing cached.
    expect($callCount)->toBe(6);
});

it('caches on mutating methods (POST/PUT/PATCH/DELETE)', function () {
    $middleware = new IdempotencyKey;

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $callCount = 0;
        $handler = function () use (&$callCount) {
            $callCount++;

            return response()->json(['ok' => true]);
        };

        $key = '11111111-2222-4333-8444-'.bin2hex(random_bytes(6));
        $middleware->handle(idempotentRequest('/api/x', $key, $method), $handler);
        $middleware->handle(idempotentRequest('/api/x', $key, $method), $handler);

        expect($callCount)->toBe(1, "method {$method} should cache");
    }
});

it('isolates the same key between different users', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['caller' => $callCount]);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    $rA = $middleware->handle(idempotentRequest('/api/x', $key, userId: 'user-a'), $handler);
    $rB = $middleware->handle(idempotentRequest('/api/x', $key, userId: 'user-b'), $handler);

    // Two distinct users — both should hit the handler (no cross-tenant replay).
    expect($callCount)->toBe(2);
    expect(json_decode($rA->getContent(), true))->toBe(['caller' => 1]);
    expect(json_decode($rB->getContent(), true))->toBe(['caller' => 2]);
    expect($rB->headers->get('Idempotency-Replayed'))->toBeNull();
});

it('isolates the same key between different routes for the same user', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['caller' => $callCount]);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    $rRequest = $middleware->handle(idempotentRequest('/api/me/deletion/request', $key), $handler);
    $rConfirm = $middleware->handle(idempotentRequest('/api/me/deletion/confirm', $key), $handler);

    // Same user, same key, different routes — must not cross-contaminate.
    expect($callCount)->toBe(2);
    expect(json_decode($rConfirm->getContent(), true))->toBe(['caller' => 2]);
    expect($rConfirm->headers->get('Idempotency-Replayed'))->toBeNull();
});

it('returns 409 idempotency_locked when a concurrent request holds the lock', function () {
    $middleware = new IdempotencyKey;
    $handlerCalled = false;
    $handler = function () use (&$handlerCalled) {
        $handlerCalled = true;

        return response()->json(['ok' => true]);
    };

    $key = '11111111-2222-4333-8444-555555555555';
    $routeScope = 'unnamed:'.sha1('POST /api/x');
    // Key shape must mirror IdempotencyKey::lockKey: idempotency:lock:{version}:{user}:{route}:{key}
    $version = (string) (config('app.version') ?: 'v0');
    $lockKey = "idempotency:lock:{$version}:user-1:{$routeScope}:{$key}";

    // Pre-seed the lock as if another in-flight request had already acquired it.
    $lock = Cache::lock($lockKey, 30);
    expect($lock->get())->toBeTrue();

    try {
        $response = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

        expect($handlerCalled)->toBeFalse();
        expect($response->getStatusCode())->toBe(409);
        expect($response->headers->get('Retry-After'))->toBe('1');
        expect(json_decode($response->getContent(), true))->toMatchArray([
            'code' => 'idempotency_locked',
        ]);
    } finally {
        $lock->release();
    }
});

it('caches 4xx responses (replay returns the same 4xx without re-executing handler)', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json([
            'message' => 'Validation failed',
            'errors' => ['email' => ['required']],
        ], 422);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    $r1 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $r2 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(1);
    expect($r2->getStatusCode())->toBe(422);
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');
    expect(json_decode($r2->getContent(), true))->toMatchArray([
        'message' => 'Validation failed',
    ]);
});

it('does NOT cache 5xx responses (handler re-runs on retry)', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['message' => 'transient db failure'], 500);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    $r1 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $r2 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(2);
    expect($r2->getStatusCode())->toBe(500);
    expect($r2->headers->get('Idempotency-Replayed'))->toBeNull();
});

it('does NOT cache StreamedResponse (handler runs every time)', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return new StreamedResponse(
            fn () => print 'chunk',
            200,
            ['Content-Type' => 'application/octet-stream'],
        );
    };

    $key = '11111111-2222-4333-8444-555555555555';
    $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(2);
});

it('does NOT cache responses larger than 256KB', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $bigBody = str_repeat('x', (256 * 1024) + 1);
    $handler = function () use (&$callCount, $bigBody) {
        $callCount++;

        return response($bigBody);
    };

    $key = '11111111-2222-4333-8444-555555555555';
    $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(2);
});

it('caches responses at exactly the 256KB boundary', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $boundaryBody = str_repeat('x', 256 * 1024);
    $handler = function () use (&$callCount, $boundaryBody) {
        $callCount++;

        return response($boundaryBody);
    };

    $key = '11111111-2222-4333-8444-555555555555';
    $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(1);
});

it('fails open when the cache lookup throws (Redis outage)', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['ok' => true]);
    };

    // Simulate Redis being unreachable on the lookup call.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('connection refused'));
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'Idempotency') && ($ctx['stage'] ?? null) === 'lookup');

    $key = '11111111-2222-4333-8444-555555555555';
    $response = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    // Handler ran, response returned — no 500 surfaced from the cache outage.
    expect($callCount)->toBe(1);
    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true))->toBe(['ok' => true]);
});

it('integrates via the "idempotent" alias and replays through the HTTP stack', function () {
    setupUsersTable();

    // Stub route guarded by both supabase.jwt (so supabase_uid is set) and
    // the idempotent alias under test. Each call returns a fresh random
    // payload — so a true replay must echo the FIRST call's value, not a
    // newly-generated one. That makes the replay assertion unambiguous.
    Route::middleware(['supabase.jwt', 'idempotent'])
        ->post('/__test/idempotent-route', fn () => response()->json(['nonce' => (string) Str::uuid()]));

    $pro = createTenant('idempotent-integration');
    $key = '11111111-2222-4333-8444-555555555555';
    $headers = ['Idempotency-Key' => $key];

    $r1 = actingAsUser($pro)->postJson('/__test/idempotent-route', [], $headers);
    $r2 = actingAsUser($pro)->postJson('/__test/idempotent-route', [], $headers);

    $r1->assertOk();
    $r2->assertOk();
    expect($r1->headers->get('Idempotency-Replayed'))->toBeNull();
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');
    expect($r2->json('nonce'))->toBe($r1->json('nonce'));
});

it('replays a 409 from POST /api/me/deletion/cancel on a duplicate key (covers #P2-43)', function () {
    // The real /cancel endpoint returns 409 when there's no pending deletion.
    // That 4xx is the perfect probe for the audit's headline race — without
    // the middleware, two concurrent cancels would each run the controller
    // (and each write an audit row in the request() / confirm() flows).
    // With the middleware, the second call must replay the first 409.
    setupUsersTable();
    $pro = createTenant('cancel-replay-pro');
    // status defaults to 'active' — so the controller returns 409 with
    // 'No pending deletion to cancel.' on both calls without the middleware.

    $key = '11111111-2222-4333-8444-555555555555';
    $headers = ['Idempotency-Key' => $key];

    $r1 = actingAsUser($pro)->postJson('/api/me/deletion/cancel', [], $headers);
    $r2 = actingAsUser($pro)->postJson('/api/me/deletion/cancel', [], $headers);

    $r1->assertStatus(409);
    $r2->assertStatus(409);
    expect($r1->headers->get('Idempotency-Replayed'))->toBeNull();
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');
    // Body shape preserved on the replay.
    expect($r2->json())->toEqual($r1->json());
});

it('caches 4xx rendered from a thrown ValidationException (production path uses $request->validate)', function () {
    // Controllers in this codebase throw ValidationException via $request->validate(...),
    // not return response()->json(..., 422). Laravel's Pipeline catches the throw and
    // renders it via the global exception handler — the rendered Response is then
    // the return value of $next($request), so the middleware DOES see and cache it.
    // This test pins that contract so a future Pipeline change can't silently regress.
    setupUsersTable();

    Route::middleware(['supabase.jwt', 'idempotent'])
        ->post('/__test/validation-throw', function (Request $request) {
            $request->validate(['email' => ['required', 'email']]);

            return response()->json(['ok' => true]);
        });

    $pro = createTenant('validation-throw-pro');
    $key = '11111111-2222-4333-8444-555555555555';
    $headers = ['Idempotency-Key' => $key];

    $r1 = actingAsUser($pro)->postJson('/__test/validation-throw', [], $headers);
    $r2 = actingAsUser($pro)->postJson('/__test/validation-throw', [], $headers);

    $r1->assertStatus(422);
    $r2->assertStatus(422);
    expect($r1->headers->get('Idempotency-Replayed'))->toBeNull();
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');
    expect($r2->json())->toEqual($r1->json());
});

it('preserves controller-set response headers on replay (Location, custom X-*)', function () {
    $middleware = new IdempotencyKey;
    $handler = function () {
        return response()->json(['ok' => true], 201, [
            'Location' => '/api/resources/abc',
            'X-Request-Id' => 'req-12345',
            'X-Custom-Header' => 'kept',
        ]);
    };

    $key = '11111111-2222-4333-8444-555555555555';

    $r1 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);
    $r2 = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($r2->headers->get('Location'))->toBe('/api/resources/abc');
    expect($r2->headers->get('X-Request-Id'))->toBe('req-12345');
    expect($r2->headers->get('X-Custom-Header'))->toBe('kept');
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');
    expect($r2->getStatusCode())->toBe(201);
});

it('ignores stale cache entries that lack the schema version (v field)', function () {
    $middleware = new IdempotencyKey;
    $callCount = 0;
    $handler = function () use (&$callCount) {
        $callCount++;

        return response()->json(['fresh' => $callCount]);
    };

    $key = '11111111-2222-4333-8444-555555555555';
    // Build the cache key the same way the middleware does (version + user + route + key).
    $version = (string) (config('app.version') ?: 'v0');
    $routeScope = 'unnamed:'.sha1('POST /api/x');
    $cacheKey = "idempotency:resp:{$version}:user-1:{$routeScope}:{$key}";

    // Pre-seed a pre-schema-version entry (no `v` field). The middleware should reject
    // it as invalid and fall through to executing the handler.
    Cache::put($cacheKey, [
        'status' => 200,
        'body' => '{"stale":true}',
        'content_type' => 'application/json',
    ], 60);

    $response = $middleware->handle(idempotentRequest('/api/x', $key), $handler);

    expect($callCount)->toBe(1);
    expect($response->headers->get('Idempotency-Replayed'))->toBeNull();
    expect(json_decode($response->getContent(), true))->toBe(['fresh' => 1]);
});

it('accepts canonical UUID v4 keys', function () {
    $middleware = new IdempotencyKey;
    $handler = fn () => response()->json(['ok' => true]);

    $valid = [
        '11111111-2222-4333-8444-555555555555',
        'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee',
        'AAAAAAAA-BBBB-4CCC-9DDD-EEEEEEEEEEEE',  // uppercase ok
    ];

    foreach ($valid as $key) {
        $response = $middleware->handle(idempotentRequest('/api/x', $key), $handler);
        expect($response->getStatusCode())->toBe(200);
    }
});
