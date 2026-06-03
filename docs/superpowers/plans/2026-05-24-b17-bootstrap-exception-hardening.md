# B17: Bootstrap & Exception Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Harden `bootstrap/app.php` and adjacent code by: giving domain exceptions a typed HTTP contract (#P2-21), consolidating auth middleware into a named group (#P2-22), extracting the CORS guard into a single canonical helper (#P3-11), and surfacing Redis lock-release failures with a counter (#P3-05).

**Architecture:** Introduce `App\Contracts\HttpStatusCodeInterface` so the exception renderer can handle any domain exception without bespoke `instanceof` checks. Create an `auth.api` middleware alias group for the three-middleware chain used by user routes. Promote `SecureHeaders::applyCors()` to a static helper called from both the middleware and the exception renderer.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Redis

---

## Files

| Action | File |
|--------|------|
| Create | `app/Contracts/HttpStatusCodeInterface.php` |
| Modify | `app/Exceptions/Streaming/KickRateLimitException.php` |
| Modify | `app/Exceptions/Gdpr/DataExportInProgressException.php` |
| Modify | `app/Http/Middleware/SecureHeaders.php` |
| Modify | `bootstrap/app.php` |
| Modify | `app/Services/Cache/CacheLockService.php` |
| Create | `tests/Feature/Bootstrap/ExceptionRendererTest.php` |
| Create | `tests/Feature/Bootstrap/AuthApiGroupTest.php` |

---

## Task 1: `HttpStatusCodeInterface` contract

**Files:**
- Create: `app/Contracts/HttpStatusCodeInterface.php`

- [x] **Step 1: Write the failing test**

```php
// tests/Feature/Bootstrap/ExceptionRendererTest.php
<?php

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Streaming\KickRateLimitException;

it('renders KickRateLimitException as 429 with Retry-After header', function () {
    $this->withoutExceptionHandling([]); // let app exception handler run

    // Bind a route that throws the exception
    Route::get('/__test/kick-rate-limit', function () {
        throw new KickRateLimitException(retryAfter: 30);
    });

    $response = $this->getJson('/__test/kick-rate-limit');

    $response->assertStatus(429);
    $response->assertHeader('Retry-After', '30');
    $response->assertJson(['message' => 'Kick API rate limit exceeded.']);
});

it('renders DataExportInProgressException as 409', function () {
    Route::get('/__test/data-export', function () {
        throw new DataExportInProgressException(existingExportId: 'abc-123');
    });

    $response = $this->getJson('/__test/data-export');

    $response->assertStatus(409);
    $response->assertJson(['message' => 'A data export is already in progress for this professional.']);
});
```

- [x] **Step 2: Run test to confirm it fails**

```bash
./vendor/bin/pest tests/Feature/Bootstrap/ExceptionRendererTest.php --no-coverage
```

Expected: FAIL — both return 500 (domain exceptions fall to generic handler)

- [x] **Step 3: Create the interface**

```php
// app/Contracts/HttpStatusCodeInterface.php
<?php

namespace App\Contracts;

/**
 * Domain exceptions implement this to declare their own HTTP status code and
 * response headers. The exception renderer in bootstrap/app.php handles any
 * HttpStatusCodeInterface in the else-branch, so new domain exceptions get
 * correct HTTP semantics without bespoke instanceof checks.
 */
interface HttpStatusCodeInterface
{
    /** HTTP status code this exception maps to (e.g. 429, 409, 423). */
    public function getHttpStatusCode(): int;

    /**
     * Additional HTTP headers to include in the response (e.g. ['Retry-After' => 30]).
     *
     * @return array<string, string|int>
     */
    public function getHttpHeaders(): array;
}
```

- [x] **Step 4: Implement on `KickRateLimitException`**

```php
// app/Exceptions/Streaming/KickRateLimitException.php
<?php

namespace App\Exceptions\Streaming;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;

/** Thrown by KickApiClient when Kick returns HTTP 429. */
class KickRateLimitException extends RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct('Kick API rate limit exceeded.');
    }

    public function getHttpStatusCode(): int
    {
        return 429;
    }

    public function getHttpHeaders(): array
    {
        return $this->retryAfter !== null
            ? ['Retry-After' => $this->retryAfter]
            : [];
    }
}
```

- [x] **Step 5: Implement on `DataExportInProgressException`**

```php
// app/Exceptions/Gdpr/DataExportInProgressException.php
<?php

namespace App\Exceptions\Gdpr;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;

class DataExportInProgressException extends RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(public string $existingExportId)
    {
        parent::__construct('A data export is already in progress for this professional.');
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getHttpHeaders(): array
    {
        return [];
    }
}
```

- [x] **Step 6: Wire the interface into the exception renderer in `bootstrap/app.php`**

In the `else` block (line 144), add an `elseif` branch *before* the generic HttpException check:

```php
// After the `elseif ($e instanceof HttpResponseException)` block, before the generic else:
elseif ($e instanceof \App\Contracts\HttpStatusCodeInterface) {
    // Domain exception with a declared HTTP contract — render it directly.
    $response = response()->json(
        ['message' => $e->getMessage()],
        $e->getHttpStatusCode()
    );
    foreach ($e->getHttpHeaders() as $header => $value) {
        $response->headers->set($header, (string) $value);
    }
}
```

The full `else` chain in `bootstrap/app.php` should be ordered:
1. `ValidationException` → 422
2. `ModelNotFoundException` → 404
3. `NotFoundHttpException` → 404
4. `HttpException` (policy 404/423) → 404/423
5. `AccessDeniedHttpException` → 403
6. `HttpResponseException` → pass through
7. **`HttpStatusCodeInterface` → domain exception (NEW)**
8. `else` → generic 500

- [x] **Step 7: Run tests — both should now pass**

```bash
./vendor/bin/pest tests/Feature/Bootstrap/ExceptionRendererTest.php --no-coverage
```

Expected: PASS ×2

- [x] **Step 8: Commit**

```bash
git add app/Contracts/HttpStatusCodeInterface.php \
        app/Exceptions/Streaming/KickRateLimitException.php \
        app/Exceptions/Gdpr/DataExportInProgressException.php \
        bootstrap/app.php \
        tests/Feature/Bootstrap/ExceptionRendererTest.php
git commit -m "feat(exceptions): introduce HttpStatusCodeInterface for typed domain exception HTTP rendering (#P2-21)"
```

---

## Task 2: Extract CORS guard into `SecureHeaders::applyCors()` (#P3-11)

**Files:**
- Modify: `app/Http/Middleware/SecureHeaders.php`
- Modify: `bootstrap/app.php`

- [x] **Step 1: Add the static helper to `SecureHeaders`**

Replace the current inline CORS check in `handle()`:
```php
if (! $response->headers->has('Access-Control-Allow-Origin')) {
    $response->headers->set('Access-Control-Allow-Origin', '*');
}
```

With a call to a new static method. The full updated `SecureHeaders.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Security headers on all responses (XFO, CSP, HSTS in production, nosniff, referrer-policy, permissions-policy).
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        self::applyCors($response);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Horizon ships its dashboard as inline <style> / <script type="module"> blocks
        // (vendor/laravel/horizon/src/Horizon.php::css/js) plus a webfont from fonts.bunny.net.
        // The default `default-src 'none'` policy strips all of them and the dashboard
        // renders as un-styled, oversized SVG blobs. Loosen CSP for Horizon's path only —
        // the admin gate in AppServiceProvider::authorizeHorizonRequest contains the trust.
        if ($request->is('horizon', 'horizon/*')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; "
                ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
                // 'unsafe-eval' is required by Vue's runtime template compiler bundled
                // into Horizon's app.js — without it, mount() throws EvalError on every
                // screen render and the dashboard never appears.
                ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                ."font-src 'self' https://fonts.bunny.net; "
                .'img-src \'self\' data:; '
                ."connect-src 'self'; "
                ."frame-ancestors 'none'"
            );
        } else {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if (! app()->environment('local', 'testing')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Ensure CORS header is present on $response.
     *
     * HandleCors middleware adds this during normal request flow, but exceptions
     * that propagate past it — and some Laravel Cloud proxy paths — strip the header.
     * This static helper is the single source of truth: called from handle() above
     * AND from the exception renderer in bootstrap/app.php so both paths are covered.
     */
    public static function applyCors(Response $response): void
    {
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
    }
}
```

- [x] **Step 2: Update the exception renderer in `bootstrap/app.php` to call the static helper**

Find the inline CORS block at the end of the render closure (currently lines ~175–179):
```php
// Ensure CORS headers are present on all API error responses.
// HandleCors middleware adds these during normal flow, but when
// an exception propagates past it the rendered response skips
// the CORS header injection. Laravel Cloud's proxy also strips
// CORS headers on some error responses. This guard ensures the
// browser can always read the error body.
if ($response !== null
    && ! $response->headers->has('Access-Control-Allow-Origin')
) {
    $response->headers->set('Access-Control-Allow-Origin', '*');
}
```

Replace with:
```php
// Delegate to SecureHeaders — single source of truth for CORS injection (#P3-11).
if ($response !== null) {
    \App\Http\Middleware\SecureHeaders::applyCors($response);
}
```

Also add the `use` statement at the top of `bootstrap/app.php` with the other middleware imports:
```php
use App\Http\Middleware\SecureHeaders;
```

And update the call to:
```php
if ($response !== null) {
    SecureHeaders::applyCors($response);
}
```

- [x] **Step 3: Run the full test suite**

```bash
composer test
```

Expected: all passing

- [x] **Step 4: Commit**

```bash
git add app/Http/Middleware/SecureHeaders.php bootstrap/app.php
git commit -m "refactor(middleware): extract CORS guard to SecureHeaders::applyCors() — single source of truth (#P3-11)"
```

---

## Task 3: Create `auth.api` middleware group (#P2-22)

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `routes/api/professional.php`
- Create: `tests/Feature/Bootstrap/AuthApiGroupTest.php`

**Context:** `routes/api/professional.php` already applies `['supabase.jwt', 'require.email_verified', 'current.pro', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated']`. The `auth.api` group captures the first three as a named group so future routes get all three without repeating them.

- [x] **Step 1: Write a test that verifies `current.pro` loads on an authenticated route**

```php
// tests/Feature/Bootstrap/AuthApiGroupTest.php
<?php

use App\Models\Core\Professional\User;

it('resolves professional on any auth.api route', function () {
    // Use the existing professional routes as the test surface.
    // The /api/professional/me endpoint sits behind auth.api.
    $user = User::factory()->create();

    // Sign the request with a mocked Supabase JWT
    $this->actingAsSupabaseUser($user)
        ->getJson('/api/professional/me')
        ->assertOk();
    // If current.pro middleware were missing, LoadCurrentProfessional would not
    // fire and the controller would throw — so a 200 proves the group is wired.
});
```

> **Note:** This project uses `actingAsSupabaseUser()` — check `tests/TestCase.php` or `tests/Traits/` for the helper. If it doesn't exist, use the existing pattern in other Feature tests that mock the JWT middleware.

- [x] **Step 2: Run the test to confirm it passes today (baseline)**

```bash
./vendor/bin/pest tests/Feature/Bootstrap/AuthApiGroupTest.php --no-coverage
```

This should already pass — the test documents the expected behaviour. If it fails, investigate before proceeding.

- [x] **Step 3: Define the `auth.api` group in `bootstrap/app.php`**

Inside the `->withMiddleware()` callback, after the `$middleware->alias([...])` block, add:

```php
// Named group for the standard authenticated user route stack.
// Applies JWT verification, email verification, and professional resolution
// in one alias so route files stay readable and can't accidentally omit one.
$middleware->appendToGroup('auth.api', [
    'supabase.jwt',
    'require.email_verified',
    'current.pro',
]);
```

- [x] **Step 4: Update `routes/api/professional.php` to use the group**

Find the outer `Route::middleware([...])` wrapper. It currently reads:
```php
Route::middleware(['supabase.jwt', 'require.email_verified', 'current.pro', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
```

Replace with:
```php
Route::middleware(['auth.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
```

- [x] **Step 5: Run the full test suite**

```bash
composer test
```

Expected: all passing (auth routes still resolve `professional`)

- [x] **Step 6: Commit**

```bash
git add bootstrap/app.php routes/api/professional.php tests/Feature/Bootstrap/AuthApiGroupTest.php
git commit -m "feat(middleware): introduce auth.api group and apply to professional routes (#P2-22)"
```

---

## Task 4: Redis counter for silent lock-release failures (#P3-05)

**Files:**
- Modify: `app/Services/Cache/CacheLockService.php`

**Context:** There are three `catch (Throwable)` blocks in `CacheLockService` that silently swallow lock-release failures. The fix increments a Redis counter each time so ops can detect pathological lock-driver failures in Nightwatch / Redis monitoring.

- [x] **Step 1: Add counter increments to the three silent catch blocks**

There are three locations in `CacheLockService`:

**Location 1** — SWR branch (~line 111):
```php
try {
    $lock->release();
} catch (Throwable) {
    // ignore — lock may have auto-expired
}
```

Replace with:
```php
try {
    $lock->release();
} catch (Throwable) {
    // Lock may have auto-expired — track silently so ops can detect driver issues.
    $this->recordLockReleaseFailure();
}
```

**Location 2** — cold miss branch (~line 154):
```php
try {
    $lock->release();
} catch (Throwable) {
    // Lock already released or driver doesn't support release-after-expiry; ignore.
}
```

Replace with:
```php
try {
    $lock->release();
} catch (Throwable) {
    // Lock already released or driver doesn't support release-after-expiry.
    $this->recordLockReleaseFailure();
}
```

**Location 3** — `rememberLockedNullable` branch (~line 258):
```php
try {
    $lock->release();
} catch (Throwable) {
    // ignore
}
```

Replace with:
```php
try {
    $lock->release();
} catch (Throwable) {
    $this->recordLockReleaseFailure();
}
```

- [x] **Step 2: Add the `recordLockReleaseFailure()` private method**

At the bottom of `CacheLockService`, before the closing `}`:

```php
/**
 * Increment the lock-release failure counter in Redis.
 *
 * Key: cache:lock_release_failures (integer, no TTL — inspect with Redis CLI).
 * Counter never wraps below 0 because Redis INCR is atomic on a 64-bit int.
 * Swallows driver errors silently — a failure to count a failure must not cascade.
 */
private function recordLockReleaseFailure(): void
{
    try {
        \Illuminate\Support\Facades\Redis::incr('cache:lock_release_failures');
    } catch (\Throwable) {
        // Swallow — a failure to count must not cascade.
    }
}
```

- [x] **Step 3: Run the full test suite**

```bash
composer test
```

Expected: all passing (no behaviour change for normal paths)

- [x] **Step 4: Commit**

```bash
git add app/Services/Cache/CacheLockService.php
git commit -m "feat(cache): increment Redis counter on silent lock-release failures (#P3-05)"
```

---

## Final verification

- [x] **Run the full suite one last time**

```bash
composer test
```

Expected: all passing

- [x] **Summarise the diff**

```bash
git log --oneline -4
git diff HEAD~4 --stat
```

---

## Self-review

**Spec coverage:**
- #P2-21 `HttpStatusCodeInterface` → Task 1 ✓
- #P2-22 `auth.api` group → Task 3 ✓
- #P3-05 lock-release counter → Task 4 ✓
- #P3-11 CORS guard extraction → Task 2 ✓

**Ordering note:** Task 2 (CORS extraction) modifies `bootstrap/app.php`, as does Task 1 (renderer wire-up) and Task 3 (group definition). Implement in order 1 → 2 → 3 → 4 to avoid merge conflicts on `bootstrap/app.php`. Each task commits independently so the review session can inspect changes per-finding.

**Edge cases checked:**
- New domain exception added later: implements `HttpStatusCodeInterface`, renderer picks it up automatically — no new `elseif` needed.
- Exception renderer return-early risk: the CORS call only fires on `$response !== null` — guard preserved.
- `auth.api` group: `EnforcePendingDeletionReadOnly` and `throttle:authenticated` remain outside the group (they are route-specific, not universal auth requirements).
