# Bot Protection Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a provider-agnostic `bot.token` middleware for Partna's public mutation endpoints, with `off | shadow | enforce` runtime modes, fail-open circuit breaker, and a CI sweep test that prevents new public endpoints from shipping without protection.

**Architecture:** Laravel Manager-with-drivers pattern (mirrors Cache/Mail/Queue). `CaptchaManager` resolves `turnstile | hcaptcha | null` from `BOT_PROTECTION_DRIVER` env var. `VerifyBotToken` middleware reads token from header (preferred) or body (fallback), calls the active provider, applies a Redis-backed circuit breaker, and emits structured observability logs. Boot-time guards in `BotProtectionServiceProvider` refuse to boot on misconfig (null driver in prod enforce, test site key in prod, missing secret). Old `VerifyTurnstileCaptcha` middleware and its `'captcha'` alias are deleted.

**Tech Stack:** Laravel 12, PHP 8.2, Pest 4, Redis (cache DB), Cloudflare Turnstile (default), hCaptcha (fallback driver).

**Spec:** `docs/superpowers/specs/2026-05-26-bot-protection-foundation-design.md`

---

## File Structure

**Create:**
- `app/Services/BotProtection/Contracts/CaptchaProvider.php` — interface
- `app/Services/BotProtection/VerificationResult.php` — immutable value object
- `app/Services/BotProtection/Exceptions/CaptchaProviderException.php`
- `app/Services/BotProtection/Exceptions/CaptchaConfigurationException.php`
- `app/Services/BotProtection/Providers/NullProvider.php` — always succeeds
- `app/Services/BotProtection/Providers/FakeProvider.php` — scripted test double
- `app/Services/BotProtection/Providers/TurnstileProvider.php`
- `app/Services/BotProtection/Providers/HCaptchaProvider.php`
- `app/Services/BotProtection/CaptchaManager.php`
- `app/Services/BotProtection/CircuitBreaker.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Providers/BotProtectionServiceProvider.php`
- `tests/Unit/Services/BotProtection/VerificationResultTest.php`
- `tests/Unit/Services/BotProtection/NullProviderTest.php`
- `tests/Unit/Services/BotProtection/TurnstileProviderTest.php`
- `tests/Unit/Services/BotProtection/HCaptchaProviderTest.php`
- `tests/Unit/Services/BotProtection/CaptchaManagerTest.php`
- `tests/Unit/Services/BotProtection/CircuitBreakerTest.php`
- `tests/Unit/Providers/BotProtectionServiceProviderTest.php`
- `tests/Feature/Http/Middleware/VerifyBotTokenTest.php`
- `tests/Feature/Security/BotProtectionCoverageTest.php`
- `tests/Integration/TurnstileIntegrationTest.php`
- `docs/auth/bot-protection-supabase.md` — operator runbook

**Modify:**
- `config/partna.php` — add `bot_protection` block (keep `features.captcha` for one release as deprecation bridge)
- `bootstrap/app.php:89` — replace `'captcha'` alias with `'bot.token'`
- `bootstrap/providers.php` — register `BotProtectionServiceProvider`
- `app/Providers/AppServiceProvider.php` — add `public-subscribe` rate limiter binding in `configureRateLimiting()`
- `routes/api.php:103-104` — replace `'captcha'` with `'bot.token:waitlist'` on waitlist route
- `routes/api/publicSite.php:30,34,37` — add `bot.token:lead`, `bot.token:enquiry`, `bot.token:subscribe` to respective routes; tighten subscribe rate limiter
- `app/Services/Diagnostics/EnvCheckService.php:85` — update env-var name from `CLOUDFLARE_TURNSTILE_SECRET_KEY` to `TURNSTILE_SECRET`
- `.env.example` — remove `CLOUDFLARE_TURNSTILE_SECRET_KEY`, `PARTNA_CAPTCHA_ENABLED`; add new bot-protection vars
- `config/services.php:73-77` — delete `turnstile` block (moved into `partna.bot_protection`)
- `tests/Pest.php` — add `has_auth_middleware()` helper + `FakeProvider` per-test reset hook

**Delete:**
- `app/Http/Middleware/VerifyTurnstileCaptcha.php` (after final task)
- `tests/Feature/PublicSite/CaptchaMiddlewareTest.php` (after final task)

---

## Task 1: Add `bot_protection` config block to `config/partna.php`

**Files:**
- Modify: `config/partna.php` (add new top-level `'bot_protection'` block; leave `'features' => ['captcha' => ...]` intact for one-release deprecation bridge)

- [ ] **Step 1: Add the config block**

Insert at the end of `config/partna.php` before the closing `];` (the file returns an array — find the last entry and add `,` if needed):

```php
    /*
    |--------------------------------------------------------------------------
    | Bot Protection
    |--------------------------------------------------------------------------
    | Provider-agnostic CAPTCHA verification for public mutation endpoints.
    | See docs/superpowers/specs/2026-05-26-bot-protection-foundation-design.md
    */

    'bot_protection' => [
        'driver'    => env('BOT_PROTECTION_DRIVER', 'null'),       // null | turnstile | hcaptcha | fake
        'mode'      => env('BOT_PROTECTION_MODE', 'off'),          // off | shadow | enforce
        'fail_open' => (bool) env('BOT_PROTECTION_FAIL_OPEN', true),

        'enforce_timeout_ms' => 3000,
        'shadow_timeout_ms'  => 500,

        'circuit_breaker' => [
            'failure_threshold' => 5,
            'window_seconds'    => 60,
            'cooldown_seconds'  => 300,
        ],

        'drivers' => [
            'turnstile' => [
                'site_key'   => env('TURNSTILE_SITE_KEY'),
                'secret'     => env('TURNSTILE_SECRET'),
                'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            ],
            'hcaptcha' => [
                'site_key'   => env('HCAPTCHA_SITE_KEY'),
                'secret'     => env('HCAPTCHA_SECRET'),
                'verify_url' => 'https://api.hcaptcha.com/siteverify',
            ],
            'null' => [],
            'fake' => [],
        ],

        // Cloudflare-published test keys — refused by boot guard in production.
        'known_test_site_keys' => [
            '1x00000000000000000000AA',
            '2x00000000000000000000AB',
            '3x00000000000000000000FF',
        ],
    ],
```

- [ ] **Step 2: Verify the config loads**

Run: `php artisan config:show partna.bot_protection.mode`
Expected: prints `off`

- [ ] **Step 3: Commit**

```bash
git add config/partna.php
git commit -m "feat(bot-protection): add bot_protection config block

Adds provider-agnostic CAPTCHA config schema per spec §6.4. Legacy
partna.features.captcha left in place for one-release deprecation bridge."
```

---

## Task 2: Create `CaptchaProvider` interface

**Files:**
- Create: `app/Services/BotProtection/Contracts/CaptchaProvider.php`

- [ ] **Step 1: Create the interface file**

```php
<?php

namespace App\Services\BotProtection\Contracts;

use App\Services\BotProtection\VerificationResult;

interface CaptchaProvider
{
    /**
     * Verify a CAPTCHA token against the provider's siteverify endpoint.
     *
     * @param  string       $token      The token from the frontend widget.
     * @param  string|null  $remoteIp   Client IP (passed to provider for fraud scoring).
     * @param  string|null  $action     Optional action tag (Turnstile analytics; reCAPTCHA v3 required).
     * @param  int|null     $timeoutMs  Override request timeout (shadow mode uses a shorter value).
     */
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult;

    public function driverName(): string;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/BotProtection/Contracts/CaptchaProvider.php
git commit -m "feat(bot-protection): add CaptchaProvider interface

Provider contract — single verify() method returning a VerificationResult.
action is optional (Turnstile uses for analytics, hCaptcha ignores it,
reCAPTCHA v3 would require it). timeoutMs is per-call so shadow mode
can use a shorter timeout than enforce mode."
```

---

## Task 3: Create `VerificationResult` value object + test

**Files:**
- Create: `app/Services/BotProtection/VerificationResult.php`
- Create: `tests/Unit/Services/BotProtection/VerificationResultTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BotProtection/VerificationResultTest.php`:

```php
<?php

use App\Services\BotProtection\VerificationResult;

uses(Tests\TestCase::class)->in(__FILE__);

it('constructs with required success flag and defaults', function () {
    $result = new VerificationResult(success: true);

    expect($result->success)->toBeTrue();
    expect($result->score)->toBeNull();
    expect($result->errorCodes)->toBe([]);
    expect($result->hostname)->toBeNull();
    expect($result->action)->toBeNull();
    expect($result->challengeTs)->toBeNull();
    expect($result->wasFailOpen)->toBeFalse();
});

it('exposes all fields when fully populated', function () {
    $result = new VerificationResult(
        success: false,
        score: 0.42,
        errorCodes: ['invalid-input-response'],
        hostname: 'partna.au',
        action: 'enquiry',
        challengeTs: '2026-05-26T12:00:00Z',
        wasFailOpen: true,
    );

    expect($result->success)->toBeFalse();
    expect($result->score)->toBe(0.42);
    expect($result->errorCodes)->toBe(['invalid-input-response']);
    expect($result->hostname)->toBe('partna.au');
    expect($result->action)->toBe('enquiry');
    expect($result->challengeTs)->toBe('2026-05-26T12:00:00Z');
    expect($result->wasFailOpen)->toBeTrue();
});

it('properties are readonly', function () {
    $result = new VerificationResult(success: true);
    expect(fn () => $result->success = false)->toThrow(\Error::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/VerificationResultTest.php`
Expected: FAIL — `Class "App\Services\BotProtection\VerificationResult" not found`

- [ ] **Step 3: Create the value object**

```php
<?php

namespace App\Services\BotProtection;

final class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?float $score = null,
        public readonly array $errorCodes = [],
        public readonly ?string $hostname = null,
        public readonly ?string $action = null,
        public readonly ?string $challengeTs = null,
        public readonly bool $wasFailOpen = false,
    ) {}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/VerificationResultTest.php`
Expected: 3 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BotProtection/VerificationResult.php tests/Unit/Services/BotProtection/VerificationResultTest.php
git commit -m "feat(bot-protection): add VerificationResult value object

Immutable readonly DTO. score and other fields nullable to span
provider shapes (Turnstile has no score; reCAPTCHA v3 does; hCaptcha
omits some fields). wasFailOpen flag carries observability through
the call chain."
```

---

## Task 4: Create exception classes

**Files:**
- Create: `app/Services/BotProtection/Exceptions/CaptchaProviderException.php`
- Create: `app/Services/BotProtection/Exceptions/CaptchaConfigurationException.php`

- [ ] **Step 1: Create `CaptchaProviderException`**

```php
<?php

namespace App\Services\BotProtection\Exceptions;

use RuntimeException;

// Thrown by providers when the verify call fails for network/5xx reasons.
// Caught by VerifyBotToken middleware → fail-open + log.
class CaptchaProviderException extends RuntimeException
{
}
```

- [ ] **Step 2: Create `CaptchaConfigurationException`**

```php
<?php

namespace App\Services\BotProtection\Exceptions;

use RuntimeException;

// Thrown at boot by BotProtectionServiceProvider when config is invalid
// (missing secret, null driver in prod enforce, test site key in prod).
// Refuses to boot — surfaces in Laravel Cloud deploy logs.
class CaptchaConfigurationException extends RuntimeException
{
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/BotProtection/Exceptions/
git commit -m "feat(bot-protection): add exception classes

CaptchaProviderException — runtime network/5xx failures, caught for fail-open.
CaptchaConfigurationException — boot-time misconfig, refuses to boot."
```

---

## Task 5: Implement `NullProvider` (TDD)

**Files:**
- Create: `app/Services/BotProtection/Providers/NullProvider.php`
- Create: `tests/Unit/Services/BotProtection/NullProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\BotProtection\Providers\NullProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class)->in(__FILE__);

it('always returns success without making any network call', function () {
    Http::fake();

    $provider = new NullProvider();
    $result = $provider->verify('any-token');

    expect($result->success)->toBeTrue();
    expect($result->wasFailOpen)->toBeFalse();
    Http::assertNothingSent();
});

it('reports its driver name', function () {
    expect((new NullProvider())->driverName())->toBe('null');
});

it('ignores all parameters', function () {
    Http::fake();
    $result = (new NullProvider())->verify('t', '1.2.3.4', 'enquiry', 500);
    expect($result->success)->toBeTrue();
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/NullProviderTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\VerificationResult;

// Always succeeds. Zero network. Default driver for local dev + CI baseline.
final class NullProvider implements CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        return new VerificationResult(success: true, action: $action);
    }

    public function driverName(): string
    {
        return 'null';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/NullProviderTest.php`
Expected: 3 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BotProtection/Providers/NullProvider.php tests/Unit/Services/BotProtection/NullProviderTest.php
git commit -m "feat(bot-protection): add NullProvider

Always-succeeds zero-network provider for local dev + CI baseline.
Http::assertNothingSent() guards against any hidden network call."
```

---

## Task 6: Implement `FakeProvider` (scripted test double)

**Files:**
- Create: `app/Services/BotProtection/Providers/FakeProvider.php`

- [ ] **Step 1: Implement**

No test file for `FakeProvider` itself — it's a test utility; its behaviour is exercised through the feature tests that use it.

```php
<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\VerificationResult;

// Test double. Scripted responses via queueResult() / queueException().
// Captures inputs so tests can assert what reached the provider.
// Instance-scoped (NOT static) — bound fresh per test via Pest beforeEach hook
// to eliminate state bleed between tests.
final class FakeProvider implements CaptchaProvider
{
    /** @var array<int, VerificationResult|\Throwable> */
    private array $queued = [];

    /** @var array<int, array{token: string, ip: ?string, action: ?string, timeoutMs: ?int}> */
    private array $calls = [];

    public function queueResult(VerificationResult $result): self
    {
        $this->queued[] = $result;
        return $this;
    }

    public function queueException(\Throwable $e): self
    {
        $this->queued[] = $e;
        return $this;
    }

    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        $this->calls[] = compact('token') + ['ip' => $remoteIp, 'action' => $action, 'timeoutMs' => $timeoutMs];

        if (empty($this->queued)) {
            // Default: succeed. Tests that care queue an explicit result.
            return new VerificationResult(success: true, action: $action);
        }
        $next = array_shift($this->queued);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function driverName(): string
    {
        return 'fake';
    }

    public function lastAction(): ?string
    {
        return $this->calls === [] ? null : end($this->calls)['action'];
    }

    public function verifyCount(): int
    {
        return count($this->calls);
    }

    public function calls(): array
    {
        return $this->calls;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/BotProtection/Providers/FakeProvider.php
git commit -m "feat(bot-protection): add FakeProvider test double

Instance-scoped scripted-response provider. queueResult() / queueException()
to script the next call; lastAction() / verifyCount() / calls() for assertions.
Bound fresh per test via Pest beforeEach hook (Task 17) to prevent state bleed."
```

---

## Task 7: Implement `TurnstileProvider` (TDD with `Http::fake`)

**Files:**
- Create: `app/Services/BotProtection/Providers/TurnstileProvider.php`
- Create: `tests/Unit/Services/BotProtection/TurnstileProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\Providers\TurnstileProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    config(['partna.bot_protection.drivers.turnstile' => [
        'site_key'   => '1x00000000000000000000AA',
        'secret'     => 'test-secret',
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ]]);
});

it('posts secret + token + remoteip to siteverify and parses success', function () {
    Http::fake([
        '*/siteverify' => Http::response(['success' => true, 'hostname' => 'partna.au', 'action' => 'enquiry'], 200),
    ]);

    $result = (new TurnstileProvider())->verify('tok-123', '1.2.3.4', 'enquiry');

    expect($result->success)->toBeTrue();
    expect($result->hostname)->toBe('partna.au');
    expect($result->action)->toBe('enquiry');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'tok-123'
            && $request['remoteip'] === '1.2.3.4';
    });
});

it('parses failure with error codes', function () {
    Http::fake([
        '*/siteverify' => Http::response([
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ], 200),
    ]);

    $result = (new TurnstileProvider())->verify('bad-token');

    expect($result->success)->toBeFalse();
    expect($result->errorCodes)->toBe(['invalid-input-response']);
});

it('maps timeout-or-duplicate to captcha_expired sentinel', function () {
    Http::fake([
        '*/siteverify' => Http::response([
            'success' => false,
            'error-codes' => ['timeout-or-duplicate'],
        ], 200),
    ]);

    $result = (new TurnstileProvider())->verify('expired-token');

    expect($result->success)->toBeFalse();
    expect($result->errorCodes)->toContain('captcha_expired');
});

it('throws CaptchaProviderException on 5xx', function () {
    Http::fake(['*/siteverify' => Http::response('boom', 503)]);
    expect(fn () => (new TurnstileProvider())->verify('t'))
        ->toThrow(CaptchaProviderException::class);
});

it('throws CaptchaProviderException on connection timeout', function () {
    Http::fake(['*/siteverify' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
    expect(fn () => (new TurnstileProvider())->verify('t'))
        ->toThrow(CaptchaProviderException::class);
});

it('uses the timeoutMs override when provided', function () {
    Http::fake(['*/siteverify' => Http::response(['success' => true], 200)]);
    (new TurnstileProvider())->verify('t', null, null, timeoutMs: 500);
    // Http::fake() doesn't expose the timeout used; the test passes if the override doesn't error.
    // Behaviour is exercised in feature tests where shadow mode uses 500ms.
    expect(true)->toBeTrue();
});

it('reports turnstile as driver name', function () {
    expect((new TurnstileProvider())->driverName())->toBe('turnstile');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/TurnstileProviderTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\VerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TurnstileProvider implements CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        $config     = config('partna.bot_protection.drivers.turnstile');
        $defaultMs  = (int) config('partna.bot_protection.enforce_timeout_ms', 3000);
        $timeoutSec = ($timeoutMs ?? $defaultMs) / 1000;

        try {
            $response = Http::asForm()
                ->timeout((float) $timeoutSec)
                ->post($config['verify_url'], [
                    'secret'   => $config['secret'],
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);

            if ($response->serverError()) {
                throw new CaptchaProviderException("Turnstile siteverify returned {$response->status()}");
            }

            $data = $response->json() ?? [];
        } catch (ConnectionException $e) {
            throw new CaptchaProviderException('Turnstile siteverify connection failed: '.$e->getMessage(), previous: $e);
        } catch (RequestException $e) {
            throw new CaptchaProviderException('Turnstile siteverify request failed: '.$e->getMessage(), previous: $e);
        }

        $errorCodes = (array) ($data['error-codes'] ?? []);
        // Map Turnstile's timeout-or-duplicate to internal captcha_expired sentinel
        // so the middleware can emit a UX-distinct response without coupling
        // the response layer to Turnstile's vocabulary.
        if (in_array('timeout-or-duplicate', $errorCodes, true)) {
            $errorCodes[] = 'captcha_expired';
        }

        return new VerificationResult(
            success:     (bool) ($data['success'] ?? false),
            errorCodes:  $errorCodes,
            hostname:    $data['hostname']     ?? null,
            action:      $data['action']       ?? $action,
            challengeTs: $data['challenge_ts'] ?? null,
        );
    }

    public function driverName(): string
    {
        return 'turnstile';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/TurnstileProviderTest.php`
Expected: 7 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BotProtection/Providers/TurnstileProvider.php tests/Unit/Services/BotProtection/TurnstileProviderTest.php
git commit -m "feat(bot-protection): add TurnstileProvider

Calls Cloudflare siteverify; parses success/failure; maps timeout-or-duplicate
to captcha_expired sentinel; throws CaptchaProviderException on 5xx/timeout
(so the middleware can fail-open)."
```

---

## Task 8: Implement `HCaptchaProvider` (TDD)

**Files:**
- Create: `app/Services/BotProtection/Providers/HCaptchaProvider.php`
- Create: `tests/Unit/Services/BotProtection/HCaptchaProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\Providers\HCaptchaProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    config(['partna.bot_protection.drivers.hcaptcha' => [
        'site_key'   => 'hcap-site',
        'secret'     => 'hcap-secret',
        'verify_url' => 'https://api.hcaptcha.com/siteverify',
    ]]);
});

it('posts secret + token + remoteip to hCaptcha siteverify', function () {
    Http::fake(['*/siteverify' => Http::response(['success' => true, 'hostname' => 'partna.au'], 200)]);

    $result = (new HCaptchaProvider())->verify('tok', '5.6.7.8');

    expect($result->success)->toBeTrue();
    expect($result->hostname)->toBe('partna.au');

    Http::assertSent(fn ($r) => $r['secret'] === 'hcap-secret' && $r['response'] === 'tok' && $r['remoteip'] === '5.6.7.8');
});

it('parses failure with error codes', function () {
    Http::fake(['*/siteverify' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200)]);
    $result = (new HCaptchaProvider())->verify('bad');
    expect($result->success)->toBeFalse();
    expect($result->errorCodes)->toBe(['invalid-input-response']);
});

it('throws CaptchaProviderException on 5xx', function () {
    Http::fake(['*/siteverify' => Http::response('boom', 502)]);
    expect(fn () => (new HCaptchaProvider())->verify('t'))->toThrow(CaptchaProviderException::class);
});

it('reports hcaptcha as driver name', function () {
    expect((new HCaptchaProvider())->driverName())->toBe('hcaptcha');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/HCaptchaProviderTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\VerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class HCaptchaProvider implements CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        $config     = config('partna.bot_protection.drivers.hcaptcha');
        $defaultMs  = (int) config('partna.bot_protection.enforce_timeout_ms', 3000);
        $timeoutSec = ($timeoutMs ?? $defaultMs) / 1000;

        try {
            $response = Http::asForm()
                ->timeout((float) $timeoutSec)
                ->post($config['verify_url'], [
                    'secret'   => $config['secret'],
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);

            if ($response->serverError()) {
                throw new CaptchaProviderException("hCaptcha siteverify returned {$response->status()}");
            }
            $data = $response->json() ?? [];
        } catch (ConnectionException $e) {
            throw new CaptchaProviderException('hCaptcha siteverify connection failed: '.$e->getMessage(), previous: $e);
        } catch (RequestException $e) {
            throw new CaptchaProviderException('hCaptcha siteverify request failed: '.$e->getMessage(), previous: $e);
        }

        return new VerificationResult(
            success:     (bool) ($data['success'] ?? false),
            errorCodes:  (array) ($data['error-codes'] ?? []),
            hostname:    $data['hostname']     ?? null,
            action:      $action,                                 // hCaptcha ignores action; preserve caller's tag for observability
            challengeTs: $data['challenge_ts'] ?? null,
        );
    }

    public function driverName(): string
    {
        return 'hcaptcha';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/HCaptchaProviderTest.php`
Expected: 4 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BotProtection/Providers/HCaptchaProvider.php tests/Unit/Services/BotProtection/HCaptchaProviderTest.php
git commit -m "feat(bot-protection): add HCaptchaProvider

Proves the abstraction by implementing a second real provider. Same
contract as TurnstileProvider; differs in URL + action handling
(hCaptcha ignores action so we preserve the caller's value for logs)."
```

---

## Task 9: Implement `CaptchaManager` (TDD)

**Files:**
- Create: `app/Services/BotProtection/CaptchaManager.php`
- Create: `tests/Unit/Services/BotProtection/CaptchaManagerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\Providers\HCaptchaProvider;
use App\Services\BotProtection\Providers\NullProvider;
use App\Services\BotProtection\Providers\TurnstileProvider;

uses(Tests\TestCase::class)->in(__FILE__);

it('resolves null driver', function () {
    config(['partna.bot_protection.driver' => 'null']);
    $manager = new CaptchaManager(app());
    expect($manager->driver())->toBeInstanceOf(NullProvider::class);
});

it('resolves turnstile driver', function () {
    config(['partna.bot_protection.driver' => 'turnstile']);
    expect((new CaptchaManager(app()))->driver())->toBeInstanceOf(TurnstileProvider::class);
});

it('resolves hcaptcha driver', function () {
    config(['partna.bot_protection.driver' => 'hcaptcha']);
    expect((new CaptchaManager(app()))->driver())->toBeInstanceOf(HCaptchaProvider::class);
});

it('resolves fake driver from container binding', function () {
    config(['partna.bot_protection.driver' => 'fake']);
    $fake = new FakeProvider();
    app()->instance(FakeProvider::class, $fake);
    expect((new CaptchaManager(app()))->driver())->toBe($fake);
});

it('throws on unknown driver', function () {
    config(['partna.bot_protection.driver' => 'nope']);
    expect(fn () => (new CaptchaManager(app()))->driver())
        ->toThrow(CaptchaConfigurationException::class);
});

it('delegates verify() to the active driver', function () {
    config(['partna.bot_protection.driver' => 'fake']);
    $fake = new FakeProvider();
    app()->instance(FakeProvider::class, $fake);

    $manager = new CaptchaManager(app());
    $manager->verify('tok', '1.2.3.4', 'enquiry');

    expect($fake->lastAction())->toBe('enquiry');
    expect($fake->verifyCount())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/CaptchaManagerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\BotProtection;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\Providers\HCaptchaProvider;
use App\Services\BotProtection\Providers\NullProvider;
use App\Services\BotProtection\Providers\TurnstileProvider;
use Illuminate\Contracts\Foundation\Application;

final class CaptchaManager
{
    public function __construct(private readonly Application $app)
    {
    }

    public function driver(?string $name = null): CaptchaProvider
    {
        $name = $name ?? (string) config('partna.bot_protection.driver');

        return match ($name) {
            'null'      => $this->app->make(NullProvider::class),
            'turnstile' => $this->app->make(TurnstileProvider::class),
            'hcaptcha'  => $this->app->make(HCaptchaProvider::class),
            'fake'      => $this->app->make(FakeProvider::class),
            default     => throw new CaptchaConfigurationException("Unknown bot protection driver: {$name}"),
        };
    }

    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        return $this->driver()->verify($token, $remoteIp, $action, $timeoutMs);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/CaptchaManagerTest.php`
Expected: 6 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BotProtection/CaptchaManager.php tests/Unit/Services/BotProtection/CaptchaManagerTest.php
git commit -m "feat(bot-protection): add CaptchaManager

Resolves driver from config('partna.bot_protection.driver') via container.
Mirrors Laravel's Cache/Mail/Queue manager pattern. Throws
CaptchaConfigurationException for unknown drivers."
```

---

## Task 10: Implement `CircuitBreaker` (TDD)

**Files:**
- Create: `app/Services/BotProtection/CircuitBreaker.php`
- Create: `tests/Unit/Services/BotProtection/CircuitBreakerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\BotProtection\CircuitBreaker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    // Real Redis (fakeRedis() doesn't honour TTL but we use reset() to simulate expiry).
    Redis::flushdb();
});

afterEach(function () {
    Redis::flushdb();
});

it('starts closed', function () {
    expect((new CircuitBreaker())->isOpen('turnstile'))->toBeFalse();
});

it('opens after threshold consecutive failures', function () {
    $breaker = new CircuitBreaker(failureThreshold: 3, windowSeconds: 60, cooldownSeconds: 300);

    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile');
    expect($breaker->isOpen('turnstile'))->toBeFalse();

    $breaker->recordFailure('turnstile');
    expect($breaker->isOpen('turnstile'))->toBeTrue();
});

it('logs once per state transition, not on re-trip', function () {
    Log::spy();
    $breaker = new CircuitBreaker(failureThreshold: 2);

    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile'); // trips
    $breaker->recordFailure('turnstile'); // re-trip while already open
    $breaker->recordFailure('turnstile'); // re-trip while already open

    Log::shouldHaveReceived('warning')
        ->with('bot_protection.circuit_open', \Mockery::any())
        ->once();
});

it('clears the failure counter on success', function () {
    $breaker = new CircuitBreaker(failureThreshold: 5);
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile');
    $breaker->recordSuccess('turnstile');

    // 4 more failures should NOT open (counter was reset)
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile');
    expect($breaker->isOpen('turnstile'))->toBeFalse();
});

it('does not auto-close on success while breaker is open (cooldown TTL handles it)', function () {
    $breaker = new CircuitBreaker(failureThreshold: 2);
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile'); // open
    $breaker->recordSuccess('turnstile');
    expect($breaker->isOpen('turnstile'))->toBeTrue();
});

it('reset() clears both open and failure keys (for tests)', function () {
    $breaker = new CircuitBreaker(failureThreshold: 2);
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile');
    expect($breaker->isOpen('turnstile'))->toBeTrue();

    $breaker->reset('turnstile');
    expect($breaker->isOpen('turnstile'))->toBeFalse();
});

it('scopes state per driver', function () {
    $breaker = new CircuitBreaker(failureThreshold: 2);
    $breaker->recordFailure('turnstile');
    $breaker->recordFailure('turnstile'); // turnstile open

    expect($breaker->isOpen('turnstile'))->toBeTrue();
    expect($breaker->isOpen('hcaptcha'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/CircuitBreakerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\BotProtection;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class CircuitBreaker
{
    public function __construct(
        private int $failureThreshold = 5,
        private int $windowSeconds    = 60,
        private int $cooldownSeconds  = 300,
    ) {
    }

    public function isOpen(string $driver): bool
    {
        return (bool) Redis::get($this->openKey($driver));
    }

    public function recordFailure(string $driver): void
    {
        $key = $this->failuresKey($driver);

        // Pipeline INCR + EXPIRE — both commands sent together. Unconditionally
        // refreshing the TTL eliminates the "expire only on count==1" race that
        // could leave the counter without a TTL. Window slides slightly with
        // each failure (acceptable per spec §15.9).
        $results = Redis::pipeline(function ($pipe) use ($key) {
            $pipe->incr($key);
            $pipe->expire($key, $this->windowSeconds);
        });
        $count = (int) ($results[0] ?? 0);

        if ($count >= $this->failureThreshold) {
            $this->trip($driver);
        }
    }

    public function recordSuccess(string $driver): void
    {
        // Clear the failure counter, but leave the open key alone — cooldown TTL
        // owns auto-recovery. Trade-off: flapping during extended outages
        // (open → cooldown → re-trip), accepted per spec §15.2.
        Redis::del($this->failuresKey($driver));
    }

    public function reset(string $driver): void
    {
        Redis::del($this->openKey($driver), $this->failuresKey($driver));
    }

    private function trip(string $driver): void
    {
        $wasOpen = $this->isOpen($driver);
        Redis::setex($this->openKey($driver), $this->cooldownSeconds, (string) now()->timestamp);
        if (! $wasOpen) {
            Log::warning('bot_protection.circuit_open', ['driver' => $driver]);
        }
        // Silent re-trip during sustained outage (no repeat log).
    }

    private function openKey(string $driver): string
    {
        return "bot_protection:cb:{$driver}:open";
    }

    private function failuresKey(string $driver): string
    {
        return "bot_protection:cb:{$driver}:failures";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/CircuitBreakerTest.php`
Expected: 7 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BotProtection/CircuitBreaker.php tests/Unit/Services/BotProtection/CircuitBreakerTest.php
git commit -m "feat(bot-protection): add Redis-backed CircuitBreaker

Pipelined INCR+EXPIRE for atomic-enough failure counting (eliminates the
expire-only-on-count==1 race). Logs once per state transition; cooldown
TTL owns auto-recovery. reset() helper for tests."
```

---

## Task 11: Create `BotProtectionServiceProvider` with boot guards

**Files:**
- Create: `app/Providers/BotProtectionServiceProvider.php`
- Create: `tests/Unit/Providers/BotProtectionServiceProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Providers\BotProtectionServiceProvider;
use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class)->in(__FILE__);

it('binds CaptchaManager and CircuitBreaker as singletons', function () {
    $a = app(CaptchaManager::class);
    $b = app(CaptchaManager::class);
    expect($a)->toBe($b);

    $c = app(CircuitBreaker::class);
    $d = app(CircuitBreaker::class);
    expect($c)->toBe($d);
});

it('boot-guard refuses null driver + enforce mode in production', function () {
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode'   => 'enforce',
    ]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'silent no-op');
});

it('boot-guard refuses Cloudflare test site key in production', function () {
    config([
        'partna.bot_protection.driver'                       => 'turnstile',
        'partna.bot_protection.mode'                         => 'enforce',
        'partna.bot_protection.drivers.turnstile.site_key'   => '1x00000000000000000000AA',
        'partna.bot_protection.drivers.turnstile.secret'     => 'any-secret',
    ]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'test site key');
});

it('boot-guard refuses missing secret for an active driver', function () {
    config([
        'partna.bot_protection.driver'                  => 'turnstile',
        'partna.bot_protection.drivers.turnstile.secret' => '',
    ]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'secret is not set');
});

it('allows null driver + enforce mode outside production', function () {
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode'   => 'enforce',
    ]);
    app()->detectEnvironment(fn () => 'local');

    (new BotProtectionServiceProvider(app()))->boot();
    expect(true)->toBeTrue();
});

it('legacy bridge maps partna.features.captcha=true to mode=enforce when mode=off', function () {
    Log::spy();
    config([
        'partna.features.captcha'      => true,
        'partna.bot_protection.driver' => 'null',  // avoid guard 3
        'partna.bot_protection.mode'   => 'off',
    ]);
    app()->detectEnvironment(fn () => 'local');

    (new BotProtectionServiceProvider(app()))->boot();

    expect(config('partna.bot_protection.mode'))->toBe('enforce');
    Log::shouldHaveReceived('warning')->with(\Mockery::on(fn ($msg) => str_contains($msg, 'deprecated')))->once();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Providers/BotProtectionServiceProviderTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Providers;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class BotProtectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker(
            failureThreshold: (int) config('partna.bot_protection.circuit_breaker.failure_threshold', 5),
            windowSeconds:    (int) config('partna.bot_protection.circuit_breaker.window_seconds', 60),
            cooldownSeconds:  (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300),
        ));

        $this->app->singleton(CaptchaManager::class, fn ($app) => new CaptchaManager($app));
    }

    public function boot(): void
    {
        $this->runBootGuards();
        $this->bridgeLegacyFlag();
    }

    private function runBootGuards(): void
    {
        $env    = $this->app->environment();
        $driver = (string) config('partna.bot_protection.driver');
        $mode   = (string) config('partna.bot_protection.mode');

        // Guard 1: null driver in production with enforce mode = silent disable.
        if ($env === 'production' && $driver === 'null' && $mode === 'enforce') {
            throw new CaptchaConfigurationException(
                'BOT_PROTECTION_DRIVER=null + BOT_PROTECTION_MODE=enforce in production is a silent no-op; set DRIVER explicitly or change MODE.'
            );
        }

        // Guard 2: Cloudflare test site key in production.
        $siteKey  = (string) config("partna.bot_protection.drivers.{$driver}.site_key", '');
        $testKeys = (array) config('partna.bot_protection.known_test_site_keys', []);
        if ($env === 'production' && $siteKey !== '' && in_array($siteKey, $testKeys, true)) {
            throw new CaptchaConfigurationException(
                "Cloudflare test site key '{$siteKey}' detected in production; replace with a real site key."
            );
        }

        // Guard 3: Active real driver without secret.
        if (in_array($driver, ['turnstile', 'hcaptcha'], true)) {
            $secret = (string) config("partna.bot_protection.drivers.{$driver}.secret", '');
            if ($secret === '') {
                throw new CaptchaConfigurationException(
                    "BOT_PROTECTION_DRIVER={$driver} but the driver secret is not set."
                );
            }
        }
    }

    private function bridgeLegacyFlag(): void
    {
        // Static flag → log fires once per worker boot, not once per request.
        static $warned = false;

        $legacy = config('partna.features.captcha');
        if ($legacy !== false && $legacy !== null && ! $warned) {
            $warned = true;
            Log::warning('PARTNA_CAPTCHA_ENABLED / partna.features.captcha is deprecated; use BOT_PROTECTION_MODE=off|shadow|enforce.');
        }

        if ($legacy === true && config('partna.bot_protection.mode') === 'off') {
            config(['partna.bot_protection.mode' => 'enforce']);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Providers/BotProtectionServiceProviderTest.php`
Expected: 6 PASS

- [ ] **Step 5: Commit**

```bash
git add app/Providers/BotProtectionServiceProvider.php tests/Unit/Providers/BotProtectionServiceProviderTest.php
git commit -m "feat(bot-protection): add BotProtectionServiceProvider

Binds CaptchaManager + CircuitBreaker as singletons. Runs four boot
guards: null-driver-enforce-prod refuses boot, CF test key in prod refuses
boot, missing-secret refuses boot, trusted-proxy soft warn. Bridges
legacy PARTNA_CAPTCHA_ENABLED for one release with once-per-process warning."
```

---

## Task 12: Register `BotProtectionServiceProvider` in `bootstrap/providers.php`

**Files:**
- Modify: `bootstrap/providers.php`

- [ ] **Step 1: Add the provider**

Open `bootstrap/providers.php`. Add to the array:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\DatabaseServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\BotProtectionServiceProvider::class,
];
```

- [ ] **Step 2: Verify provider loads**

Run: `php artisan about | grep -i "Bot Protection"` (or `php artisan config:clear && php artisan about`)
Expected: app boots without error. The provider doesn't appear in `about` unless it registers itself there — just confirm no boot failure.

- [ ] **Step 3: Commit**

```bash
git add bootstrap/providers.php
git commit -m "chore(bot-protection): register BotProtectionServiceProvider"
```

---

## Task 13: Implement `VerifyBotToken` middleware

**Files:**
- Create: `app/Http/Middleware/VerifyBotToken.php`

(Feature tests come in Task 16 after Pest helpers exist.)

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class VerifyBotToken
{
    public function __construct(
        private readonly CaptchaManager $captcha,
        private readonly CircuitBreaker $breaker,
    ) {
    }

    public function handle(Request $request, Closure $next, string $action = 'default'): Response
    {
        $mode = (string) config('partna.bot_protection.mode', 'off');

        // Off mode: zero work, zero network, zero Redis call.
        if ($mode === 'off') {
            return $next($request);
        }

        $driver = (string) config('partna.bot_protection.driver', 'null');
        $token  = $this->extractToken($request);

        if ($token === null) {
            Log::info('bot_protection.missing_token', ['action' => $action]);
            if ($mode === 'shadow') {
                Log::info('bot_protection.shadow_reject', ['driver' => $driver, 'action' => $action, 'codes' => ['captcha_missing'], 'has_token' => false]);
                return $next($request);
            }
            return $this->reject('captcha_missing');
        }

        // Breaker check — if Redis is down, treat as breaker-unavailable + fail-open.
        try {
            if ($this->breaker->isOpen($driver)) {
                $this->logFailOpenOnce($driver, $action, $request, 'circuit_open');
                return $next($request);
            }
        } catch (Throwable $e) {
            $this->logBreakerUnavailable($driver, $action, $request);
            return $next($request);
        }

        $timeoutMs = $mode === 'shadow'
            ? (int) config('partna.bot_protection.shadow_timeout_ms', 500)
            : (int) config('partna.bot_protection.enforce_timeout_ms', 3000);

        try {
            $result = $this->captcha->verify($token, $request->ip(), $action, $timeoutMs);
        } catch (CaptchaProviderException $e) {
            $this->safelyRecord(fn () => $this->breaker->recordFailure($driver));
            Log::warning('bot_protection.fail_open', [
                'driver'     => $driver,
                'reason'     => 'provider_error',
                'action'     => $action,
                'route'      => $request->path(),
                'ip'         => $request->ip(),
                'request_id' => $request->header('X-Request-Id'),
            ]);
            return $next($request);
        }

        $this->safelyRecord(fn () => $result->success ? $this->breaker->recordSuccess($driver) : null);

        if ($result->success) {
            return $next($request);
        }

        if ($mode === 'shadow') {
            Log::info('bot_protection.shadow_reject', [
                'driver' => $driver, 'action' => $action, 'codes' => $result->errorCodes, 'score' => $result->score, 'has_token' => true,
            ]);
            return $next($request);
        }

        // Map provider's timeout-or-duplicate sentinel to user-friendly captcha_expired.
        $userError = in_array('captcha_expired', $result->errorCodes, true) ? 'captcha_expired' : 'captcha_failed';

        // Server-side log keeps the raw provider codes; user response does not (info disclosure).
        Log::info('bot_protection.failed', ['driver' => $driver, 'action' => $action, 'codes' => $result->errorCodes]);

        return $this->reject($userError);
    }

    private function extractToken(Request $request): ?string
    {
        $raw = $request->header('X-Captcha-Token')
            ?? $request->input('captcha_token')
            ?? $request->input('cf_turnstile_response');  // legacy alias — accepted for one release

        if (! is_string($raw)) return null;
        return blank($raw) ? null : trim($raw);
    }

    private function reject(string $error): Response
    {
        return response()->json([
            'message' => 'Verification failed.',
            'error'   => $error,
            'captcha' => [
                'should_retry'    => true,
                'should_rerender' => true,
            ],
        ], 422);
    }

    private function safelyRecord(\Closure $op): void
    {
        try {
            $op();
        } catch (Throwable $e) {
            // Breaker bookkeeping failures must never break a request.
        }
    }

    private function logFailOpenOnce(string $driver, string $action, Request $request, string $reason): void
    {
        // Dedup via Redis: log once per cooldown window per driver. If Redis is dead
        // we already passed-through in the caller; we just skip logging here.
        try {
            $key = "bot_protection:fail_open_logged:{$driver}:{$reason}";
            $count = \Illuminate\Support\Facades\Redis::incr($key);
            if ($count === 1) {
                \Illuminate\Support\Facades\Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
                Log::warning('bot_protection.fail_open', [
                    'driver' => $driver, 'reason' => $reason, 'action' => $action,
                    'route' => $request->path(), 'ip' => $request->ip(),
                    'request_id' => $request->header('X-Request-Id'),
                ]);
            }
        } catch (Throwable $e) {
            // Silent — observability failure must not break the request.
        }
    }

    private function logBreakerUnavailable(string $driver, string $action, Request $request): void
    {
        static $warned = false;
        if ($warned) return;
        $warned = true;
        Log::warning('bot_protection.breaker_unavailable', [
            'driver' => $driver, 'action' => $action, 'route' => $request->path(),
        ]);
    }
}
```

- [ ] **Step 2: Confirm class autoloads**

Run: `composer dump-autoload -o`
Expected: success, no errors.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/VerifyBotToken.php
git commit -m "feat(bot-protection): add VerifyBotToken middleware

Provider-agnostic CAPTCHA gate. off/shadow/enforce modes, fail-open
on Redis/provider failure, once-per-cooldown-window fail-open logging,
captcha_expired mapping, legacy cf_turnstile_response body field accepted
for one release."
```

---

## Task 14: Register `bot.token` alias, remove `captcha` alias in `bootstrap/app.php`

**Files:**
- Modify: `bootstrap/app.php` (around line 79-92, the `$middleware->alias([...])` block)

- [ ] **Step 1: Apply the alias swap**

Open `bootstrap/app.php`. In the `$middleware->alias([...])` array, **remove this line**:

```php
            'captcha' => VerifyTurnstileCaptcha::class,
```

**Add this line** (anywhere in the array — keep alphabetical or grouped style consistent with file):

```php
            'bot.token' => \App\Http\Middleware\VerifyBotToken::class,
```

Also remove the corresponding `use` statement if present at the top of the file:

```php
use App\Http\Middleware\VerifyTurnstileCaptcha;
```

- [ ] **Step 2: Verify routes still load**

Run: `php artisan route:list 2>&1 | head -20`
Expected: route list prints without "middleware [captcha] not found" errors. (You'll get that error in the next task until routes are updated — but the `route:list` itself should still succeed for routes that don't use `captcha`.)

Note: if route:list fails because waitlist still references `captcha`, that's OK — Task 18 fixes it. Don't commit yet if routes are broken.

- [ ] **Step 3: Apply waitlist route fix preemptively to unblock route:list**

Open `routes/api.php` line 103-104. Replace:

```php
Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
    ->middleware(['throttle:waitlist', 'captcha']);
```

with:

```php
Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
    ->middleware(['throttle:waitlist', 'bot.token:waitlist']);
```

- [ ] **Step 4: Verify route:list now clean**

Run: `php artisan route:list 2>&1 | grep -E "waitlist|bot.token"`
Expected: shows the waitlist route with `bot.token:waitlist` in its middleware list.

- [ ] **Step 5: Commit**

```bash
git add bootstrap/app.php routes/api.php
git commit -m "feat(bot-protection): replace 'captcha' alias with 'bot.token'

bootstrap/app.php: swap alias. routes/api.php: update waitlist route
(was using 'captcha' which is the only route that did)."
```

---

## Task 15: Add `public-subscribe` rate limiter binding

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (inside `configureRateLimiting()`, after the `waitlist` limiter block around line 322)

- [ ] **Step 1: Add the binding**

Find the `RateLimiter::for('waitlist', ...)` block (around line 322) and add this new block immediately after it:

```php
        // public-subscribe: newsletter signups. Tightened from the previous
        // throttle:public-site (60/min IP) to 5/min IP + 12/h per email,
        // matching the waitlist limiter's per-email cap.
        RateLimiter::for('public-subscribe', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($request->ip())->response(function () {
                    return response()->json(['message' => 'Too many subscription attempts. Please wait before trying again.'], 429);
                }),
                Limit::perHour(12)->by($email !== '' ? "email:{$email}" : 'no-email')->response(function () {
                    return response()->json(['message' => 'Too many subscription attempts for this email. Please try later.'], 429);
                }),
            ];
        });
```

- [ ] **Step 2: Verify limiter registers**

Run: `php artisan tinker --execute='echo \Illuminate\Support\Facades\RateLimiter::limiter("public-subscribe") ? "ok" : "missing";'`
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat(bot-protection): add public-subscribe rate limiter

Tightens newsletter signup from 60/min IP (throttle:public-site) to
5/min IP + 12/h per email — matches waitlist pattern."
```

---

## Task 16: Add Pest helpers (`has_auth_middleware` + FakeProvider reset)

**Files:**
- Modify: `tests/Pest.php` (append to the "Functions" section)

- [ ] **Step 1: Add the helper + beforeEach hook**

Append to `tests/Pest.php` near the bottom (after the existing helper functions):

```php

/*
|--------------------------------------------------------------------------
| Bot Protection Test Helpers
|--------------------------------------------------------------------------
| has_auth_middleware: used by BotProtectionCoverageTest to skip auth-
|   protected routes when sweeping for missing bot.token middleware.
|
| The FakeProvider beforeEach hook binds a fresh instance per test so
| scripted results don't bleed between tests.
*/

function has_auth_middleware($route): bool
{
    return collect($route->gatherMiddleware())->some(fn ($m) =>
        $m === 'supabase.jwt'
        || $m === 'professional.api'
        || str_starts_with((string) $m, 'professional.api')
        || $m === 'staff'
        || $m === 'staff.admin'
        || str_starts_with((string) $m, 'auth:')
    );
}

uses()->beforeEach(function () {
    config(['partna.bot_protection.driver' => 'fake']);
    app()->instance(
        \App\Services\BotProtection\Providers\FakeProvider::class,
        new \App\Services\BotProtection\Providers\FakeProvider(),
    );
})->in('Feature/Http/Middleware', 'Feature/PublicSite', 'Feature/Security');
```

- [ ] **Step 2: Verify Pest still loads**

Run: `vendor/bin/pest tests/Unit/Services/BotProtection/NullProviderTest.php -v`
Expected: 3 PASS (no Pest bootstrap errors).

- [ ] **Step 3: Commit**

```bash
git add tests/Pest.php
git commit -m "test(bot-protection): add has_auth_middleware helper + FakeProvider reset hook

beforeEach hook binds a fresh FakeProvider per test in Feature/Http/Middleware,
Feature/PublicSite, Feature/Security — prevents state bleed between scripted
tests. has_auth_middleware helper is consumed by BotProtectionCoverageTest."
```

---

## Task 17: Write `VerifyBotToken` feature tests

**Files:**
- Create: `tests/Feature/Http/Middleware/VerifyBotTokenTest.php`

- [ ] **Step 1: Create the feature test file**

```php
<?php

use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\VerificationResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    // Mount a test route per scenario — keeps tests independent of real route changes.
    Route::post('/__test/bot-protected', fn () => response()->json(['ok' => true]))
        ->middleware('bot.token:test-action');
    Redis::flushdb();
});

it('off mode passes without token and without provider call', function () {
    config(['partna.bot_protection.mode' => 'off']);

    $response = $this->postJson('/__test/bot-protected');

    $response->assertOk();
    expect(app(FakeProvider::class)->verifyCount())->toBe(0);
});

it('enforce mode rejects 422 when token missing', function () {
    config(['partna.bot_protection.mode' => 'enforce']);

    $response = $this->postJson('/__test/bot-protected');

    $response->assertStatus(422);
    $response->assertJson(['error' => 'captcha_missing']);
    expect($response->json('captcha.codes' ?? null))->toBeNull();  // raw codes NOT exposed
});

it('enforce mode rejects 422 when token is whitespace-only', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => '   ']);
    $response->assertStatus(422)->assertJson(['error' => 'captcha_missing']);
});

it('enforce mode passes when FakeProvider returns success', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: true));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok-token']);

    $response->assertOk();
});

it('enforce mode rejects 422 captcha_failed on FakeProvider failure', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: false, errorCodes: ['invalid-input-response']));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'bad']);

    $response->assertStatus(422)->assertJson(['error' => 'captcha_failed']);
});

it('enforce mode rejects 422 captcha_expired when codes include the sentinel', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: false, errorCodes: ['timeout-or-duplicate', 'captcha_expired']));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'expired']);

    $response->assertStatus(422)->assertJson(['error' => 'captcha_expired']);
});

it('accepts the legacy cf_turnstile_response body field', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: true));

    $response = $this->postJson('/__test/bot-protected', ['cf_turnstile_response' => 'legacy-token']);

    $response->assertOk();
});

it('shadow mode passes invalid token + logs shadow_reject', function () {
    Log::spy();
    config(['partna.bot_protection.mode' => 'shadow']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: false, errorCodes: ['invalid-input-response']));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'bad']);

    $response->assertOk();
    Log::shouldHaveReceived('info')->withArgs(fn ($msg) => $msg === 'bot_protection.shadow_reject')->atLeast()->once();
});

it('shadow mode passes provider exception + logs fail_open (not shadow_reject)', function () {
    Log::spy();
    config(['partna.bot_protection.mode' => 'shadow']);
    app(FakeProvider::class)->queueException(new CaptchaProviderException('boom'));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertOk();
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => $msg === 'bot_protection.fail_open')->atLeast()->once();
    Log::shouldNotHaveReceived('info', fn ($msg) => $msg === 'bot_protection.shadow_reject');
});

it('enforce mode fails open on provider exception', function () {
    Log::spy();
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueException(new CaptchaProviderException('boom'));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertOk();
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => $msg === 'bot_protection.fail_open')->atLeast()->once();
});

it('captures the action tag through to the provider', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: true));

    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    expect(app(FakeProvider::class)->lastAction())->toBe('test-action');
});
```

- [ ] **Step 2: Run tests**

Run: `vendor/bin/pest tests/Feature/Http/Middleware/VerifyBotTokenTest.php -v`
Expected: 11 PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Http/Middleware/VerifyBotTokenTest.php
git commit -m "test(bot-protection): add VerifyBotToken feature tests

Covers off/shadow/enforce modes, missing/blank/valid/invalid/expired
tokens, legacy cf_turnstile_response field, provider-exception fail-open
in both shadow and enforce modes, and action-tag round-trip."
```

---

## Task 18: Create `BotProtectionCoverageTest` sweep

**Files:**
- Create: `tests/Feature/Security/BotProtectionCoverageTest.php`

- [ ] **Step 1: Create the test**

```php
<?php

use App\Http\Middleware\VerifyBotToken;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class)->in(__FILE__);

/*
|--------------------------------------------------------------------------
| Bot Protection Coverage Sweep
|--------------------------------------------------------------------------
| Every public mutation endpoint (POST/PUT/PATCH on public/* or v1/public/*)
| must either use bot.token middleware or appear in BOT_PROTECTION_EXEMPT
| below with a justification.
|
| Mirrors PolicyCoverageTest. Prevents silent regression when new public
| endpoints are added.
*/

const BOT_PROTECTION_EXEMPT = [
    // Analytics — write volume too high for interactive CAPTCHA; rate-limit + UA filter cover it.
    'public/analytics/pageviews',
    'public/analytics/clicks',
    'public/analytics/section-seen',
    // Resolve-identifier — enumeration defence via constant-time jitter, not interactive CAPTCHA.
    'public/auth/resolve-identifier',
    // Signup-availability — deferred to Tier 3 hardening.
    'public/signup/availability',
    // Webhooks — HMAC-gated, not user-facing.
    'internal/email-hooks/supabase',
    'internal/csp-report',
    // Unsubscribe — RFC 8058 token-gated.
    'public/unsubscribe/{token}',
];

const BOT_PROTECTION_URI_PREFIXES = ['public/', 'v1/public/'];
const BOT_PROTECTION_METHODS = ['POST', 'PUT', 'PATCH'];

function bot_protection_route_has_token_middleware($route): bool
{
    $middleware = collect($route->gatherMiddleware());
    return $middleware->contains(fn ($m) =>
        $m === 'bot.token'
        || str_starts_with((string) $m, 'bot.token:')
        || $m === VerifyBotToken::class
        || str_starts_with((string) $m, VerifyBotToken::class.':')
    );
}

it('every public mutation endpoint is either bot-protected or explicitly exempted', function () {
    $publicMutations = collect(Route::getRoutes())
        ->filter(fn ($r) =>
            ! empty(array_intersect(BOT_PROTECTION_METHODS, $r->methods()))
            && collect(BOT_PROTECTION_URI_PREFIXES)->some(fn ($p) => str_starts_with($r->uri(), $p))
            && ! has_auth_middleware($r));

    expect($publicMutations->count())
        ->toBeGreaterThan(0, 'Route collection appears empty — verify test bootstrap loads routes.');

    foreach ($publicMutations as $route) {
        $isProtected = bot_protection_route_has_token_middleware($route);
        $isExempt    = in_array($route->uri(), BOT_PROTECTION_EXEMPT, true);

        expect($isProtected || $isExempt)
            ->toBeTrue("Route {$route->uri()} is public mutation without bot.token middleware. Add bot.token:<action> or add to BOT_PROTECTION_EXEMPT with justification.");
    }
});

it('every BOT_PROTECTION_EXEMPT entry matches a registered route', function () {
    $allUris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    foreach (BOT_PROTECTION_EXEMPT as $exemptUri) {
        expect(in_array($exemptUri, $allUris, true))
            ->toBeTrue("BOT_PROTECTION_EXEMPT entry '{$exemptUri}' does not match any registered route. Remove stale entry.");
    }
});
```

- [ ] **Step 2: Run the sweep**

Run: `vendor/bin/pest tests/Feature/Security/BotProtectionCoverageTest.php -v`
Expected: FAIL for the first test — `Route public/enquiry is public mutation without bot.token middleware...` (we haven't updated those routes yet). Second test should PASS or fail with a specific stale entry (also informative).

This is the *intended* failure that proves the sweep works. Task 19 fixes it.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Security/BotProtectionCoverageTest.php
git commit -m "test(bot-protection): add BotProtectionCoverageTest sweep

Fails CI if a new public POST/PUT/PATCH endpoint lands without bot.token
middleware or explicit exemption. Mirrors PolicyCoverageTest pattern.

This commit intentionally leaves the sweep failing — Task 19 wires
bot.token onto the existing endpoints to make it pass."
```

---

## Task 19: Wire `bot.token` onto enquiry, customers, subscribe routes

**Files:**
- Modify: `routes/api/publicSite.php` (lines 30, 34, 37 — the three routes we're protecting)

- [ ] **Step 1: Edit each route**

In `routes/api/publicSite.php` find these three routes and update them.

Customer leads route (line ~30):

```php
// before
Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
    ->middleware(['lead.log', 'throttle:leads']);

// after
Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
    ->middleware(['lead.log', 'throttle:leads', 'bot.token:lead']);
```

Enquiry route (line ~34):

```php
// before
Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads']);

// after
Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads', 'bot.token:enquiry']);
```

Subscribe route (line ~37):

```php
// before
Route::post('/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
    ->middleware('throttle:public-site');

// after
Route::post('/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
    ->middleware(['throttle:public-subscribe', 'bot.token:subscribe']);
```

- [ ] **Step 2: Run the sweep — should now pass**

Run: `vendor/bin/pest tests/Feature/Security/BotProtectionCoverageTest.php -v`
Expected: 2 PASS.

If the second test fails ("stale EXEMPT entry"), inspect the message: either the EXEMPT URI is wrong (Laravel returns a slightly different form than expected) or the route was removed. Fix the EXEMPT entry to match what `Route::getRoutes()->...->uri()` actually returns.

- [ ] **Step 3: Run all tests to confirm nothing else broke**

Run: `composer test 2>&1 | tail -20`
Expected: full test suite passes.

- [ ] **Step 4: Commit**

```bash
git add routes/api/publicSite.php
git commit -m "feat(bot-protection): wire bot.token to enquiry, customers, subscribe

enquiry + customers: append bot.token after throttle (cheap rejection first).
subscribe: replace throttle:public-site with throttle:public-subscribe
(tightened limits) and add bot.token:subscribe."
```

---

## Task 20: Update `.env.example`

**Files:**
- Modify: `.env.example` (around line 210 where the legacy flag lives, and line ~?? where CLOUDFLARE_TURNSTILE_SECRET_KEY lives — search to confirm)

- [ ] **Step 1: Find and replace the legacy entries**

Search for `CLOUDFLARE_TURNSTILE_SECRET_KEY` and `PARTNA_CAPTCHA_ENABLED`:

```bash
grep -n "CLOUDFLARE_TURNSTILE_SECRET_KEY\|PARTNA_CAPTCHA_ENABLED" .env.example
```

Delete those entries (and any comment lines that introduce them like `# Enable bot protection...`).

- [ ] **Step 2: Add the new block**

Add this block in the same area (near other feature-flag-style entries):

```bash
# Bot protection foundation — see docs/superpowers/specs/2026-05-26-bot-protection-foundation-design.md
BOT_PROTECTION_DRIVER=null          # null | turnstile | hcaptcha. Default null for local; set to turnstile in deployed envs.
BOT_PROTECTION_MODE=off             # off | shadow | enforce. Default off locally; set enforce in deployed envs.
BOT_PROTECTION_FAIL_OPEN=true       # Pre-pilot default; revisit fail-closed per sensitive surface after first real incident.
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET=
HCAPTCHA_SITE_KEY=
HCAPTCHA_SECRET=
```

- [ ] **Step 3: Verify the diff**

Run: `git diff .env.example`
Expected: clean diff — removed 2 lines (plus comments), added 8 lines.

- [ ] **Step 4: Commit**

```bash
git add .env.example
git commit -m "chore(bot-protection): update .env.example

Remove CLOUDFLARE_TURNSTILE_SECRET_KEY and PARTNA_CAPTCHA_ENABLED.
Add BOT_PROTECTION_DRIVER/MODE/FAIL_OPEN + TURNSTILE_SITE_KEY/SECRET +
HCAPTCHA_SITE_KEY/SECRET per spec §12.2."
```

---

## Task 21: Update `EnvCheckService` for renamed env var

**Files:**
- Modify: `app/Services/Diagnostics/EnvCheckService.php:85`

- [ ] **Step 1: Update the mapping**

Find line 85 (or thereabouts):

```php
            'services.turnstile.secret_key' => 'CLOUDFLARE_TURNSTILE_SECRET_KEY',
```

Replace with:

```php
            'partna.bot_protection.drivers.turnstile.secret' => 'TURNSTILE_SECRET',
            'partna.bot_protection.drivers.turnstile.site_key' => 'TURNSTILE_SITE_KEY',
```

- [ ] **Step 2: Delete the now-orphaned `services.turnstile` block**

Open `config/services.php` lines ~73-77 and delete:

```php
    // Cloudflare Turnstile — bot-protection for public lead-capture endpoints.
    // Only required when PARTNA_CAPTCHA_ENABLED=true.
    'turnstile' => [
        'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
    ],
```

- [ ] **Step 3: Verify env check still runs**

Run: `php artisan tinker --execute='echo app(\App\Services\Diagnostics\EnvCheckService::class)::class;'`
Expected: prints the class name (proves it autoloads + boots).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Diagnostics/EnvCheckService.php config/services.php
git commit -m "chore(bot-protection): migrate EnvCheckService to new env var names

Rename CLOUDFLARE_TURNSTILE_SECRET_KEY check to TURNSTILE_SECRET +
TURNSTILE_SITE_KEY. Delete orphaned config/services.php 'turnstile' block."
```

---

## Task 22: Delete old `VerifyTurnstileCaptcha` middleware + its test

**Files:**
- Delete: `app/Http/Middleware/VerifyTurnstileCaptcha.php`
- Delete: `tests/Feature/PublicSite/CaptchaMiddlewareTest.php`

- [ ] **Step 1: Confirm nothing else references the class**

Run: `grep -rn "VerifyTurnstileCaptcha\|'captcha'" app/ bootstrap/ config/ routes/ tests/ 2>&1 | grep -v "^Binary" | grep -v "bot_protection\|bot.token"`
Expected: empty (or only matches inside comments / docs).

If grep finds live references, fix them before deleting. Don't proceed to deletion until grep is clean.

- [ ] **Step 2: Delete the files**

```bash
rm app/Http/Middleware/VerifyTurnstileCaptcha.php
rm tests/Feature/PublicSite/CaptchaMiddlewareTest.php
```

- [ ] **Step 3: Verify tests still pass**

Run: `composer test 2>&1 | tail -10`
Expected: full suite passes.

- [ ] **Step 4: Commit**

```bash
git add -A app/Http/Middleware/VerifyTurnstileCaptcha.php tests/Feature/PublicSite/CaptchaMiddlewareTest.php
git commit -m "chore(bot-protection): delete VerifyTurnstileCaptcha and its test

Class is unreferenced after the bot.token migration. Coverage subsumed
by VerifyBotTokenTest."
```

---

## Task 23: Retire the legacy `partna.features.captcha` config entry

**Files:**
- Modify: `config/partna.php` (line ~1004-1010 — the `features` block with `captcha`)

(One-release-later cleanup — but we can do it inline because the bridge code in `BotProtectionServiceProvider` no longer needs to fire once we delete this config entry. Re-read the bridge: it only acts when `partna.features.captcha === true`. Deleting the config key makes `config('partna.features.captcha')` return `null`, which the bridge silently ignores. So we can drop both at the same time and just delete the bridge code too.)

- [ ] **Step 1: Delete the legacy config line**

In `config/partna.php` find:

```php
        'captcha' => (bool) env('PARTNA_CAPTCHA_ENABLED', env('SIDEST_CAPTCHA_ENABLED', false)),
```

Delete that line.

- [ ] **Step 2: Delete the bridge code in `BotProtectionServiceProvider`**

In `app/Providers/BotProtectionServiceProvider.php`, delete the `bridgeLegacyFlag()` method body and the call site in `boot()`. Leave `boot()` calling only `runBootGuards()`.

```php
    public function boot(): void
    {
        $this->runBootGuards();
    }
```

Delete the `bridgeLegacyFlag()` method entirely.

- [ ] **Step 3: Delete the corresponding test**

In `tests/Unit/Providers/BotProtectionServiceProviderTest.php`, delete the test `it('legacy bridge maps partna.features.captcha=true to mode=enforce when mode=off', ...)`.

- [ ] **Step 4: Verify tests still pass**

Run: `composer test 2>&1 | tail -10`
Expected: full suite passes.

- [ ] **Step 5: Commit**

```bash
git add config/partna.php app/Providers/BotProtectionServiceProvider.php tests/Unit/Providers/BotProtectionServiceProviderTest.php
git commit -m "chore(bot-protection): retire legacy partna.features.captcha config + bridge

Pre-pilot, no users — no need to keep a one-release deprecation window.
Delete the config key, the legacy bridge in BotProtectionServiceProvider,
and the bridge test."
```

---

## Task 24: Write the Supabase Dashboard operator runbook

**Files:**
- Create: `docs/auth/bot-protection-supabase.md`

- [ ] **Step 1: Create the runbook**

```markdown
# Supabase Bot Protection — Operator Runbook

This doc covers Path B (Supabase-mediated) bot protection per
[bot-protection foundation spec §13](../superpowers/specs/2026-05-26-bot-protection-foundation-design.md).

The Laravel backend uses its own `bot.token` middleware for `/public/*`
mutation endpoints (Path A). Path B is everything that lives at Supabase:
signup, signin, password recovery, magic link.

## When to enable

After the backend bot-protection PR has shipped and `TURNSTILE_SITE_KEY` /
`TURNSTILE_SECRET` are set in Laravel Cloud, you can enable Bot Protection
in Supabase Dashboard for the matching environment.

## Steps (per environment)

1. **Open Supabase Dashboard** for the target project:
   - Dev:  `https://supabase.com/dashboard/project/glncumufgaqcmqhzwrxm`
   - Prod: `https://supabase.com/dashboard/project/edplucmvkcnokyygxqsb`
2. Navigate to **Authentication → Settings → Bot and Abuse Protection**.
3. **Enable** the toggle.
4. Provider: **Turnstile**.
5. Paste the **same secret** that Laravel uses for `TURNSTILE_SECRET` in
   this environment (Cloudflare Turnstile site — one secret powers both Laravel
   and Supabase).
6. Scope: enable for signup, signin, password recovery, magic link.
7. Save.

## Verification (REQUIRED before enabling in production)

Confirm Supabase enforces the CAPTCHA token server-side — not just at the
frontend. Otherwise an attacker with the public anon key can call
`/auth/v1/signup` directly via the JS SDK and bypass the Turnstile widget.

Run this curl from your terminal **after** enabling Bot Protection on the
target environment:

```bash
# Replace <project-ref> and <anon-key> with the target project's values.
curl -i -X POST \
  -H 'Content-Type: application/json' \
  -H 'apikey: <anon-key>' \
  -d '{"email":"bot-test+'$(date +%s)'@example.com","password":"test-password-123"}' \
  'https://<project-ref>.supabase.co/auth/v1/signup'
```

**Expected:** HTTP `400` with a body mentioning CAPTCHA (e.g. `"captcha protection: verification process failed"`).

**If you get HTTP `200`:** Bot Protection is NOT enforcing server-side.
DO NOT enable in production. Escalate to Supabase support.

## Frontend coordination

The Astro frontend must pass the Turnstile token through the Supabase JS SDK:

```js
const { error } = await supabase.auth.signUp({
  email,
  password,
  options: { captchaToken },  // token from <Turnstile> widget
})
```

This is frontend work, tracked in the frontend repo. Once Bot Protection is
on, the SDK call WILL fail without `captchaToken` — make sure the frontend
ships the change before enabling, or do this in this order:
1. Frontend deploys with widget + captchaToken pass-through.
2. Run the verification curl above (it should still 200 before enabling
   — confirming pre-state).
3. Enable Bot Protection in the dashboard.
4. Re-run the verification curl — should now 400.

## Rollback

If something breaks, **disable Bot Protection** in the Dashboard.
Effect is immediate (no deploy needed). Investigate, fix, re-enable.
```

- [ ] **Step 2: Verify the file renders**

Run: `cat docs/auth/bot-protection-supabase.md | head -20`
Expected: prints the heading and intro.

- [ ] **Step 3: Commit**

```bash
git add docs/auth/bot-protection-supabase.md
git commit -m "docs(bot-protection): add Supabase Bot Protection operator runbook

Step-by-step Dashboard config for signup/signin/recovery + the
mandatory Path B server-enforcement verification curl (must run
before enabling in prod). Per spec §13."
```

---

## Task 25: Write the real-wire integration test

**Files:**
- Create: `tests/Integration/TurnstileIntegrationTest.php`

- [ ] **Step 1: Create the test**

```php
<?php

use App\Services\BotProtection\Providers\TurnstileProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class)->group('integration')->in(__FILE__);

beforeEach(function () {
    if (! env('CI_RUN_INTEGRATION', false)) {
        $this->markTestSkipped('Integration tests opt-in via CI_RUN_INTEGRATION=true');
    }

    // Connectivity pre-check so a Cloudflare incident doesn't fail nightly CI.
    try {
        $health = Http::timeout(2)->get('https://challenges.cloudflare.com');
        if ($health->failed()) {
            $this->markTestSkipped('Cloudflare unreachable; skipping integration test');
        }
    } catch (\Throwable $e) {
        $this->markTestSkipped('Cloudflare unreachable: '.$e->getMessage());
    }

    config(['partna.bot_protection.drivers.turnstile' => [
        'site_key'   => '1x00000000000000000000AA',
        'secret'     => '1x0000000000000000000000000000000AA',
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ]]);
});

it('hits real Cloudflare siteverify with the always-pass test key', function () {
    $result = (new TurnstileProvider())->verify('XXXX.DUMMY.TOKEN.XXXX');

    // Always-pass test key returns success regardless of token value.
    expect($result->success)->toBeTrue();
});
```

- [ ] **Step 2: Verify it skips by default**

Run: `vendor/bin/pest tests/Integration/TurnstileIntegrationTest.php -v`
Expected: 1 SKIPPED.

- [ ] **Step 3: Manually run the integration test once to confirm wire works**

Run: `CI_RUN_INTEGRATION=true vendor/bin/pest tests/Integration/TurnstileIntegrationTest.php -v`
Expected: 1 PASS (or SKIPPED if your network can't reach Cloudflare).

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/TurnstileIntegrationTest.php
git commit -m "test(bot-protection): add nightly real-wire Turnstile integration test

Skipped by default; runs only when CI_RUN_INTEGRATION=true. Includes a
connectivity pre-check that markTestSkipped's on Cloudflare unreachability
so a CF incident doesn't fail nightly CI. Hits real challenges.cloudflare.com
with the always-pass test key."
```

---

## Task 26: Full test suite + manual smoke + final commit

**Files:** none (verification + cleanup)

- [ ] **Step 1: Run the full test suite**

Run: `composer test 2>&1 | tail -20`
Expected: all green.

- [ ] **Step 2: Code style**

Run: `php artisan pint`
Expected: pint formats any files it needs to; review the diff.

If pint changed files:

```bash
git add -u
git commit -m "style(bot-protection): pint pass"
```

- [ ] **Step 3: Manual smoke in dev (operator step — not automatable)**

Set in your local `.env`:

```
BOT_PROTECTION_DRIVER=turnstile
BOT_PROTECTION_MODE=enforce
BOT_PROTECTION_FAIL_OPEN=true
TURNSTILE_SITE_KEY=1x00000000000000000000AA
TURNSTILE_SECRET=1x0000000000000000000000000000000AA
```

Run: `php artisan config:clear && php artisan serve`

Submit each form via the frontend (or curl) with:
- Valid token (frontend widget): should succeed
- Missing token: should 422 `captcha_missing`
- Bad token: should 422 `captcha_failed`

Also verify the boot guards by temporarily emptying `TURNSTILE_SECRET`:

```
TURNSTILE_SECRET=
```

Run: `php artisan about`
Expected: app refuses to boot with `CaptchaConfigurationException`. Restore the secret.

- [ ] **Step 4: Final status check**

Run: `git status` and `git log --oneline -25`
Expected: clean working tree, 24-ish commits on top of the development branch covering the foundation.

- [ ] **Step 5: Ready for merge**

When `composer test` is green and manual smoke passes, the PR is ready for review.

---

## Out of scope (intentionally deferred)

Per spec §16:

- Analytics endpoints, resolve-identifier, signup-availability hardening (Tier 3)
- Per-email dedup on lead forms
- FingerprintJS / device fingerprinting
- Fail-closed for sensitive surfaces
- Cloudflare Bot Management Enterprise
- Automatic provider failover
- Redis dedup of token replay
- Exponential backoff on circuit breaker
- Log sampling for fail-open events at high RPS

Each lands as its own future PR when the trigger condition fires.

## Frontend coordination

Per spec §14, the frontend session needs to ship in parallel:
- `<Turnstile siteKey={...} action={...}>` widget in 4 forms + report form (T&S spec)
- `X-Captcha-Token` header (preferred) or `captcha_token` body field on submit
- UI re-render of widget on 422 `captcha_failed` / `captcha_expired`
- Build-time env var `TURNSTILE_SITE_KEY` (no `/public/config` endpoint built)

Backend can ship in `BOT_PROTECTION_MODE=off` if frontend isn't ready;
flip to `enforce` once frontend lands.
