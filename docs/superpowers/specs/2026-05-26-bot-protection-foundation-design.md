# Bot Protection Foundation — Backend Design

**Date:** 2026-05-26
**Author:** Josh + Claude (brainstorming session, 7-lens review revision)
**Status:** Design — awaiting user review before implementation plan
**Related:** [2026-05-26-trust-and-safety-foundation-design.md](2026-05-26-trust-and-safety-foundation-design.md) — consumes the foundation this spec defines.

---

## 1. TL;DR

Refactor the existing provider-coupled `VerifyTurnstileCaptcha` middleware into a provider-agnostic `bot.token` middleware backed by a `CaptchaManager` driver registry. Add `off | shadow | enforce` runtime modes, a fail-open Redis-backed circuit breaker, and a coverage sweep test. Extend coverage to `/public/subscribe`, plus Supabase Auth signup/login/password-reset via Supabase's own dashboard config.

The goal is set-up-once durability: swap providers in one env var, never refactor middleware again, never have a public POST endpoint silently land without bot protection.

**Pre-pilot rollout: build + manual test + ship.** No staged shadow/enforce gates — Partna has zero users today, so the enterprise-grade gradual rollout would be theatre. Ship `enforce` mode straight to prod once it works in dev. `shadow` mode and the rollback knobs exist in the code for free; we use them later when there's traffic worth protecting.

This spec has been through a 7-lens review (security, failure modes, middleware mechanics, config/operations, testing, spec rigor, scalability). The revisions in this version address all P0/P1/P2 findings from those lenses. See §17 for decisions made during review.

## 2. Goals

1. Provider abstraction — `turnstile | hcaptcha | null` resolves from `BOT_PROTECTION_DRIVER` env var; backend code changes outside `app/Services/BotProtection/` are not required to swap providers. (Frontend widget and Supabase Dashboard coordination are out-of-band; see §14.)
2. Runtime modes — `off | shadow | enforce` per environment; switching modes is an env-var change plus a worker restart (typically a fast-deploy / `php artisan queue:restart`). Pre-pilot uses `enforce` straight away; `shadow` exists as a knob for future use when there's real traffic.
3. Coverage durability — `BotProtectionCoverageTest` fails CI when a new public mutation endpoint (POST, PUT, PATCH) lands without `bot.token` middleware or explicit exemption.
4. Fail-open availability — provider outages do not block legitimate users; circuit breaker prevents pathological retry storms; Redis outages also fail-open (do not 500 the request).
5. Observability — every fail-open, every shadow-mode would-reject, and every circuit-state-transition is structured-logged for review. Per-request log events while the breaker is open are emitted once per state-transition, not per request, to avoid log storms.
6. Backwards-compatible migration — the legacy env var `PARTNA_CAPTCHA_ENABLED` continues to work for one release with a deprecation warning, then is deleted. The legacy body-field `cf_turnstile_response` is accepted alongside `captcha_token` for one release, then deleted.

## 3. Non-goals

- Analytics endpoints (`/public/analytics/*`) — token-volume too high for interactive CAPTCHA; deferred to a separate behavioural/fingerprint strategy.
- Resolve-identifier and signup-availability hardening — current constant-time jitter is adequate; revisit if enumeration becomes a measured problem.
- Device fingerprinting (FingerprintJS or equivalent) — defer until first measured ATO incident.
- Cloudflare Bot Management Enterprise tier — only justified when traffic exceeds free tier limits.
- A unified `TrustScore` aggregator across CAPTCHA + honeypot + timing + rate-limit signals — YAGNI for current needs; existing single-purpose middlewares stay.
- Frontend widget implementation — out of scope for backend repo; documented as contract for frontend session.
- Automatic provider failover — manual env-var failover only; auto-failover is a known limitation documented in §15.

## 4. Locked decisions

| Decision | Choice | Rationale |
|---|---|---|
| Provider | **Cloudflare Turnstile** (Managed mode) | Already on Cloudflare; 1M/mo free; first-class Supabase integration; GDPR-clean; smallest JS bundle; PAT-ready for iOS. |
| Fallback provider | hCaptcha (full implementation alongside Turnstile) | Native Supabase support; Stripe-validated; 100K/mo free. Proves the abstraction works. |
| Provider explicitly rejected | reCAPTCHA (v2, v3, Enterprise) | CNIL fines for EU operators; Austrian court ruling Nov 2024; Google cut free tier from 1M to 10K/mo in 2024; no native Supabase support. |
| Failure mode (CF provider) | Fail-open with circuit breaker | At pre-beta scale, availability of signup/contact forms beats brief windows where bots could slip through during a CF outage. Flip to fail-closed on sensitive surfaces only after a real ATO incident. |
| Failure mode (Redis) | Fail-open with separate log event | The circuit breaker depends on Redis; if Redis is down, the middleware must not 500. Wrap all breaker calls in `try/catch` and log `bot_protection.breaker_unavailable`. |
| Abstraction scope | Narrow — CAPTCHA only | Honeypot, form-timing, rate-limit stay as separate single-purpose middlewares. Matches Laravel's Manager-with-drivers pattern (Cache, Mail, Queue). |
| Coverage gate | Sweep test enforces `bot.token` or explicit EXEMPT for POST/PUT/PATCH on `api/public/` and `api/v1/public/` URI prefixes (Laravel registers these routes under the `api/` prefix via `withRouting()`) | Same pattern as `PolicyCoverageTest` for authorization. Prevents silent regression. |
| Rollout cadence | Pre-pilot: build → manual test in dev → ship `enforce` straight to prod. No staged shadow/enforce gates. | Partna has zero users today. Staged rollout would be theatre. The mode/driver knobs are runtime config (not deployed code branches), so when there's traffic worth protecting later, the same code already supports shadow/enforce promotion. |
| Vocabulary | `CaptchaProvider` / `CaptchaManager` interface naming kept, even though outer namespace is `BotProtection` | The interface is the *CAPTCHA-specific* contract. The outer namespace anticipates future sibling concerns (honeypot service, fingerprint service) that would live as peers, not implementations of the same interface. See §17. |
| Shadow-mode timeout | 500ms (separate from enforce-mode 3s timeout) | Shadow discards the result; a 3s timeout would block PHP workers and starve worker capacity if shadow is ever used at scale. |
| Circuit-breaker atomicity | Redis pipeline with unconditional EXPIRE refresh on every failure increment | Avoids the conditional-EXPIRE race that can leave the counter without a TTL. Trade-off: failure window slides slightly with each failure — acceptable. |

## 5. Current state — what exists, what's broken

### 5.1 What exists

| Surface | CAPTCHA wired? | Enabled? | Other protection |
|---|---|---|---|
| `/public/enquiry` | **No.** `'captcha'` middleware alias exists but is not applied to the route. | — | `lead.log` + `throttle:leads` (3/min IP + 100/min subdomain + 10/h per professional) + honeypot + form timing |
| `/public/customers` | **No.** Same — alias exists, not applied. | — | `lead.log` + `throttle:leads` + honeypot + form timing |
| `/public/waitlist` | **No.** Route is not in `routes/api/publicSite.php`; defined elsewhere or absent. | — | `throttle:waitlist` (5/min IP + 12/h per email) |
| `/public/subscribe` | **No.** Not wired. | — | `throttle:public-site` (60/min IP) |
| `/public/analytics/*` | **No.** | — | `throttle:analytics` (120/min IP), UA filter |
| `/public/auth/resolve-identifier` | **No.** | — | Constant-time jitter (40-120ms) |
| `/public/signup/availability` | **No.** | — | None |
| Supabase signup | Not enabled in Supabase | — | Supabase defaults |
| Supabase login | Not enabled in Supabase | — | Supabase defaults |
| Supabase password reset | Not enabled in Supabase | — | Supabase defaults |

**Important correction from the prior version of this spec:** the `VerifyTurnstileCaptcha` middleware *class* exists at `app/Http/Middleware/VerifyTurnstileCaptcha.php` and is registered as alias `'captcha'` in `bootstrap/app.php` (line 89), but **it is not currently applied to any route**. The `'PARTNA_CAPTCHA_ENABLED'` env-var flag controls the middleware's no-op short-circuit, but since the middleware is not on any route, the flag does nothing today either. This spec adds the new middleware to routes and retires the dead class + alias + flag.

### 5.2 What's broken about the current setup

1. Middleware class named `VerifyTurnstileCaptcha` — provider locked into class name; swapping providers requires deleting and rewriting.
2. Middleware not applied to any route — wiring is incomplete, not just disabled.
3. Existing middleware is **fail-closed** (returns 503 on Cloudflare outage) — this spec changes behaviour to **fail-open with circuit breaker**. This is an intentional behavioural reversal motivated by the pre-beta availability calculus in §4; flagged here so PR reviewers understand it is not a regression.
4. Body field name `cf_turnstile_response` is provider-specific. This spec moves to `X-Captcha-Token` header (preferred) and `captcha_token` body field (fallback). The legacy `cf_turnstile_response` body field is accepted for one release for frontend compatibility, then deleted (§14).
5. No `shadow` mode — flipping the flag would go straight from off to enforcing on real users with zero observation window.
6. Supabase Auth surfaces unprotected — Turnstile wiring at Laravel does nothing for signup/login/reset which happen at Supabase.
7. No middleware tests — flipping the flag could regress and not be caught.
8. No coverage sweep — new public endpoints can be added without CAPTCHA and no one notices until production abuse.
9. Existing test file `tests/Feature/PublicSite/CaptchaMiddlewareTest.php` tests the soon-to-be-deleted middleware — must be deleted as part of this work.

### 5.3 Migration map — every touchpoint being retired or renamed

| Today | Replacement | Notes |
|---|---|---|
| Class `App\Http\Middleware\VerifyTurnstileCaptcha` | Class `App\Http\Middleware\VerifyBotToken` | Old class deleted. |
| Alias `'captcha'` (in `bootstrap/app.php` line 89) | Alias `'bot.token'` (same file) | Old alias removed. |
| Env var `PARTNA_CAPTCHA_ENABLED` | Env var `BOT_PROTECTION_MODE` | Legacy bridged for one release with deprecation log. |
| Env var `CLOUDFLARE_TURNSTILE_SECRET_KEY` | Env var `TURNSTILE_SECRET` | Rename. `.env.example` updated; legacy entry removed. |
| Config `partna.features.captcha` | Config `partna.bot_protection.mode` | Old config key removed. |
| Config `services.turnstile.secret_key` | Config `partna.bot_protection.drivers.turnstile.secret` | Old key removed. `EnvCheckService` (`app/Services/Health/EnvCheckService.php` line 85, which maps the old env name) updated. |
| Body field `cf_turnstile_response` | Header `X-Captcha-Token` (preferred) / body field `captcha_token` | Legacy body field accepted for one release. |
| Test `tests/Feature/PublicSite/CaptchaMiddlewareTest.php` | Test `tests/Feature/Http/Middleware/VerifyBotTokenTest.php` | Old test deleted; coverage subsumed by new test. |

## 6. Architecture

### 6.1 File layout

```
app/Services/BotProtection/
  Contracts/CaptchaProvider.php          # interface (CAPTCHA-specific contract — see §17)
  Providers/TurnstileProvider.php        # production driver
  Providers/HCaptchaProvider.php         # fallback driver, full implementation
  Providers/NullProvider.php             # tests + local dev, always succeeds, no network
  Providers/FakeProvider.php             # scripted-response test double (instance-scoped queue)
  CaptchaManager.php                     # resolves driver from config; boot-time validation
  VerificationResult.php                 # immutable value object
  CircuitBreaker.php                     # Redis-backed, per-driver, pipelined INCR+EXPIRE
  Exceptions/CaptchaProviderException.php       # network / 5xx failures
  Exceptions/CaptchaConfigurationException.php  # boot-time misconfig

app/Http/Middleware/
  VerifyBotToken.php                     # NEW — replaces VerifyTurnstileCaptcha

app/Providers/
  BotProtectionServiceProvider.php       # NEW — binds CaptchaManager, CircuitBreaker; boot guards

config/partna.php                        # new 'bot_protection' block

docs/auth/bot-protection-supabase.md     # NEW — operator runbook for Supabase Dashboard config

.env.example                             # +7 new vars, -1 legacy var (see §12.2)

tests/Pest.php                           # +has_auth_middleware() helper, +FakeProvider reset hook

tests/Feature/Security/BotProtectionCoverageTest.php  # NEW — coverage sweep
tests/Feature/Http/Middleware/VerifyBotTokenTest.php  # NEW — middleware feature tests
tests/Unit/Services/BotProtection/**                  # NEW — unit tests per class
tests/Integration/TurnstileIntegrationTest.php        # NEW — nightly real-wire test
```

**On the Form Request rule (`app/Rules/BotToken.php`):** removed from the deliverable. The middleware-as-route-attribute pattern covers every surface in this spec; a Form Request rule would be dead code. If a future surface needs conditional CAPTCHA (e.g., login that only requires CAPTCHA after N failures), add the rule then with a concrete consumer.

### 6.2 The contract

```php
namespace App\Services\BotProtection\Contracts;

interface CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,        // override per-call (used by shadow mode)
    ): VerificationResult;

    public function driverName(): string;
}
```

`action` is optional because Turnstile treats it as an analytics tag, reCAPTCHA v3 requires it, hCaptcha ignores it. `timeoutMs` is per-call to support shadow-mode's shorter timeout.

### 6.3 The result

```php
namespace App\Services\BotProtection;

final class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?float $score = null,        // null for Turnstile/hCaptcha, 0-1 for reCAPTCHA v3
        public readonly array $errorCodes = [],
        public readonly ?string $hostname = null,
        public readonly ?string $action = null,
        public readonly ?string $challengeTs = null,
        public readonly bool $wasFailOpen = false,   // observability flag
    ) {}
}
```

### 6.4 Config schema

```php
// config/partna.php
'bot_protection' => [
    'driver'         => env('BOT_PROTECTION_DRIVER', 'null'),    // default null until operator opts in
    'mode'           => env('BOT_PROTECTION_MODE', 'off'),       // off | shadow | enforce
    'fail_open'      => (bool) env('BOT_PROTECTION_FAIL_OPEN', true),
    'enforce_timeout_ms' => 3000,
    'shadow_timeout_ms'  => 500,    // shadow discards result; short timeout prevents worker starvation

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
    ],

    // Cloudflare-published test keys per
    // https://developers.cloudflare.com/turnstile/troubleshooting/testing/
    // Recognised by the boot guard (§6.7) to prevent test keys leaking into production.
    'known_test_site_keys' => [
        '1x00000000000000000000AA',   // always passes
        '2x00000000000000000000AB',   // always fails
        '3x00000000000000000000FF',   // forces challenge
    ],
],
```

### 6.5 Middleware registration & usage

**Middleware alias goes in `bootstrap/app.php`** (Laravel 12 — no `app/Http/Kernel.php` in this project). The existing `'captcha'` alias is replaced with `'bot.token'`:

```php
// bootstrap/app.php — inside ->withMiddleware(function (Middleware $middleware) {...})
$middleware->alias([
    // ...existing aliases unchanged...
    'bot.token' => \App\Http\Middleware\VerifyBotToken::class,
    // (delete the old 'captcha' => VerifyTurnstileCaptcha::class line)
]);
```

**Middleware signature explicitly:**

```php
namespace App\Http\Middleware;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBotToken
{
    public function __construct(
        private CaptchaManager $captcha,
        private CircuitBreaker $breaker,
    ) {}

    public function handle(Request $request, Closure $next, string $action = 'default'): Response
    {
        // ...see §9 for full lifecycle
    }
}
```

**Route usage** — note that **throttle runs BEFORE `bot.token`** (Laravel preserves middleware-array order; throttle is cheap so it should reject rate-limited bots before we pay for a Turnstile API call):

```php
// routes/api/publicSite.php — using EXISTING rate-limiter names (verified on disk)
Route::post('/public/enquiry', PublicEnquiryController::class)
    ->middleware(['throttle:leads', 'bot.token:enquiry']);

Route::post('/public/customers', PublicCustomerLeadController::class)
    ->middleware(['throttle:leads', 'bot.token:lead']);

Route::post('/public/waitlist', PublicWaitlistController::class)
    ->middleware(['throttle:waitlist', 'bot.token:waitlist']);

Route::post('/public/subscribe', PublicEmailSubscriptionController::class)
    ->middleware(['throttle:public-subscribe', 'bot.token:subscribe']);
```

Existing limiters used: `leads` (covers both enquiry and customers per `AppServiceProvider::configureRateLimiting` line 293), `waitlist` (line 322).

**One new rate limiter binding required.** `public-subscribe` does not exist today (subscribe currently uses the looser `public-site` limiter, 60/min IP). Add to `AppServiceProvider::configureRateLimiting()`:

```php
RateLimiter::for('public-subscribe', function (Request $request) use ($throttleEnabled) {
    if (! $throttleEnabled) return Limit::none();
    return [
        Limit::perMinute(5)->by($request->ip()),                                    // tightened from 60/min
        Limit::perHour(12)->by(strtolower((string) $request->input('email', ''))),  // per-email per spec §7
    ];
});
```

### 6.6 Container bindings — `BotProtectionServiceProvider`

```php
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
            failureThreshold: config('partna.bot_protection.circuit_breaker.failure_threshold'),
            windowSeconds:    config('partna.bot_protection.circuit_breaker.window_seconds'),
            cooldownSeconds:  config('partna.bot_protection.circuit_breaker.cooldown_seconds'),
        ));

        $this->app->singleton(CaptchaManager::class, fn ($app) => new CaptchaManager($app));
    }

    public function boot(): void
    {
        $this->runBootGuards();
        $this->bridgeLegacyFlag();
    }

    private function runBootGuards(): void { /* see §6.7 */ }
    private function bridgeLegacyFlag(): void { /* see §12.3 */ }
}
```

Register in `bootstrap/providers.php`. Bindings as singletons (one instance per request lifecycle; CaptchaManager resolves drivers lazily so swapping drivers via env var at next worker restart picks up cleanly).

### 6.7 Boot-time guards

`BotProtectionServiceProvider::runBootGuards()` runs in `boot()` and fails loudly for misconfigurations that would otherwise cause silent security failures:

```php
private function runBootGuards(): void
{
    $env    = app()->environment();
    $driver = config('partna.bot_protection.driver');
    $mode   = config('partna.bot_protection.mode');

    // Guard 1: NullProvider in production with enforce mode = silent disable. Refuse to boot.
    if ($env === 'production' && $driver === 'null' && $mode === 'enforce') {
        throw new CaptchaConfigurationException(
            'BOT_PROTECTION_DRIVER=null + BOT_PROTECTION_MODE=enforce in production is a silent no-op; set DRIVER explicitly or change MODE.'
        );
    }

    // Guard 2: Test site key in production. Refuse to boot.
    $siteKey  = (string) config("partna.bot_protection.drivers.{$driver}.site_key");
    $testKeys = (array) config('partna.bot_protection.known_test_site_keys', []);
    if ($env === 'production' && in_array($siteKey, $testKeys, true)) {
        throw new CaptchaConfigurationException(
            "Cloudflare test site key '{$siteKey}' detected in production; replace with a real site key."
        );
    }

    // Guard 3: Active driver without secret. Refuse to boot (provider would 4xx every request).
    if (in_array($driver, ['turnstile', 'hcaptcha'], true)) {
        $secret = (string) config("partna.bot_protection.drivers.{$driver}.secret");
        if ($secret === '') {
            throw new CaptchaConfigurationException(
                "BOT_PROTECTION_DRIVER={$driver} but the driver secret is not set."
            );
        }
    }

    // Guard 4: Trusted proxy warning. Soft warn — log only, do not refuse boot.
    if (! $this->trustedProxiesConfigured()) {
        Log::warning('bot_protection.trusted_proxies_unconfigured', [
            'note' => '$request->ip() may return the Cloudflare edge IP instead of the real client IP; Turnstile siteverify scoring is degraded.',
        ]);
    }
}
```

Boot failures surface in Laravel Cloud deploy logs and `php artisan about` output, catching misconfig before traffic hits.

## 7. Per-surface coverage (Tier 2 scope)

| Surface | Protection | Action tag | Rate limit (after) |
|---|---|---|---|
| `/public/enquiry` | `bot.token:enquiry` | `enquiry` | Keep `throttle:leads` (3/min IP + 100/min subdomain + 10/h per professional) |
| `/public/customers` | `bot.token:lead` | `lead` | Keep `throttle:leads` (3/min IP + 100/min subdomain) |
| `/public/waitlist` | `bot.token:waitlist` | `waitlist` | Keep `throttle:waitlist` (5/min IP + 12/h per email) |
| `/public/subscribe` | `bot.token:subscribe` **(NEW)** | `subscribe` | **NEW** `throttle:public-subscribe` (5/min IP + 12/h per email); replaces existing looser `throttle:public-site` |
| `/v1/public/report` | `bot.token:report` (built by [T&S spec](2026-05-26-trust-and-safety-foundation-design.md)) | `report` | 5,1 per IP/min + 3,60 per (IP, target)/hour (per T&S spec) |
| Supabase signup | Supabase Dashboard → Auth → Bot Protection | — | Supabase native |
| Supabase login | Supabase Dashboard → Auth → Bot Protection | — | Supabase native |
| Supabase password reset | Supabase Dashboard → Auth → Bot Protection | — | Supabase native |

**Out of scope (explicit deferral, in `BotProtectionCoverageTest::EXEMPT`):**
- `public/analytics/pageviews`, `public/analytics/clicks`, `public/analytics/section-seen`
- `public/auth/resolve-identifier`
- `public/signup/availability`
- `internal/email-hooks/supabase`, `internal/csp-report`
- `public/unsubscribe/{token}`

Each entry justified inline in the EXEMPT list comments.

## 8. Two CAPTCHA universes — by design

```
PATH A — Backend-mediated (this spec builds)
  Astro widget → token in X-Captcha-Token header → bot.token middleware →
  CaptchaManager → TurnstileProvider → Cloudflare siteverify → pass / 422
  Surfaces: /public/{enquiry,customers,waitlist,subscribe}, /v1/public/report

PATH B — Supabase-mediated (Supabase Dashboard config; zero backend code)
  Astro widget → token in signup body → Supabase Auth Bot Protection →
  Cloudflare siteverify → pass / 422
  Surfaces: Supabase signup, login, password reset
```

Path B uses the same Cloudflare site key / secret as Path A (one secret, two consumers).

### Path B verification step — must happen before enabling in production Supabase

The Supabase `anon` key is public (appears in the frontend bundle). An attacker with the anon key could call `/auth/v1/signup` directly via the Supabase JS SDK, bypassing the Astro frontend (and any Turnstile widget rendered there). This is only safe if **Supabase's Bot Protection enforces the CAPTCHA token server-side at the Supabase edge**.

**Verification step (manual, before turning on Bot Protection in prod Supabase Dashboard):** make a direct HTTP POST to `https://<project-ref>.supabase.co/auth/v1/signup` with a valid anon key, valid email/password, and **no `captchaToken`** in the body. Expected result with Bot Protection enabled: **400** with an error mentioning CAPTCHA. If the request succeeds (`200`), the spec's Path B trust assumption is wrong — escalate to Supabase support before enabling.

## 9. Request lifecycle (Path A)

1. Frontend renders `<Turnstile siteKey={...} action="enquiry" />`; user interacts; widget emits opaque 5-min single-use token.
2. Frontend submits form, includes token as `X-Captcha-Token` header (preferred) or `captcha_token` body field (fallback for plain HTML form posts). The middleware also accepts the legacy `cf_turnstile_response` body field for one release for frontend compatibility.
3. Throttle middleware runs first (`throttle:leads` etc) — cheapest rejection path.
4. `VerifyBotToken` middleware runs:
   1. Read `mode` from `config('partna.bot_protection.mode')`. If `off`, pass through (no provider call, no Redis call).
   2. Read token in priority order: `X-Captcha-Token` header → `captcha_token` body → `cf_turnstile_response` body (legacy). Normalise via `$token = blank($token) ? null : trim($token);` to reject empty strings and whitespace-only tokens.
   3. If token is null → emit `Log::info('bot_protection.missing_token')` and reject 422 `captcha_missing` (or shadow-log + pass if `mode === 'shadow'`).
   4. Wrap the breaker check in `try/catch`. If `CircuitBreaker::isOpen($driverName)` throws (Redis down), log `bot_protection.breaker_unavailable` and pass (fail-open).
   5. If breaker is open, emit `bot_protection.fail_open` with `reason=circuit_open` **only on the first request per cooldown window** (deduped via Redis-incremented counter with the same TTL as the open key). Pass.
   6. Determine timeout: `mode === 'shadow'` ? `shadow_timeout_ms` (500) : `enforce_timeout_ms` (3000). Call `CaptchaManager::verify($token, $request->ip(), $action, $timeoutMs)`.
   7. Network error / 5xx → `CaptchaProviderException`. Record breaker failure (in `try/catch` — Redis errors don't propagate). Emit `bot_protection.fail_open` with `reason=provider_error`. Pass.
   8. Provider returns `success=true` → record breaker success. Pass.
   9. Provider returns `success=false` → if `mode === 'shadow'`, emit `bot_protection.shadow_reject` and pass; otherwise reject 422 with structured response (§10.5).
5. Honeypot middleware runs (existing, unchanged).
6. Form-timing middleware runs (existing, unchanged).
7. Controller executes.

### 9.1 Trusted-proxy dependency

`$request->ip()` returns the Cloudflare edge IP rather than the real client IP unless `TrustProxies` middleware is configured with Cloudflare's IP ranges (or Laravel Cloud's `APP_TRUSTED_PROXIES=*` + Cloudflare's `CF-Connecting-IP` forwarding). Without this, Turnstile's siteverify fraud-scoring is degraded — the per-IP signal is constant. The §6.7 boot guard warns if trusted proxies aren't configured; verify before deploying to production.

### 9.2 `captcha_expired` mapping

`TurnstileProvider::verify()` maps Turnstile error code `'timeout-or-duplicate'` to internal sentinel `captcha_expired` on the `VerificationResult.errorCodes` array. The middleware reads this and emits `error: "captcha_expired"` in the 422 response so the frontend can show "Verification expired, please re-submit" UX. Other Turnstile codes map to `captcha_failed` generically.

## 10. Failure modes & error handling

### 10.1 Failure-mode table

| Failure | Cause | Handling | User sees | We see |
|---|---|---|---|---|
| Token missing / blank / whitespace | Frontend bug, bot bypass, JS disabled | 422 `captcha_missing` | "Please verify you're human" + widget | `Log::info('bot_protection.missing_token')` |
| Token invalid | Tampered, wrong site | 422 `captcha_failed` | Widget re-render + generic error | `Log::info('bot_protection.failed')` |
| Token expired (Turnstile `timeout-or-duplicate`) | Idle >5 min, double-submit, replay | 422 `captcha_expired` | "Verification expired" + widget re-render | `Log::info('bot_protection.failed')` |
| Provider 4xx (config error) | Wrong secret, malformed config | Caught at boot (§6.7) — wouldn't normally reach runtime | — | Refuses to boot |
| Provider timeout / 5xx | CF outage, network blip | Fail-open, request continues | Form succeeds normally | `Log::warning('bot_protection.fail_open', reason=provider_error)` |
| Circuit breaker open | Pattern of provider failures | Skip verify entirely, fail-open. **Rate-limit middleware (which runs before `bot.token`) remains fully active**, bounding the blast radius during the cooldown window — an attacker who deliberately trips the breaker by exhausting siteverify still hits the same per-IP, per-subdomain, and per-professional limits that apply when the breaker is closed. | Form succeeds normally | `Log::warning('bot_protection.circuit_open')` on state transition; `Log::warning('bot_protection.fail_open', reason=circuit_open)` once per cooldown window |
| Redis down (breaker unavailable) | Cache Redis outage | Fail-open, request continues | Form succeeds normally | `Log::warning('bot_protection.breaker_unavailable')` |

**`BOT_PROTECTION_FAIL_OPEN=false` override.** All three "fail-open" rows above (provider timeout/5xx, breaker open, breaker unavailable) flip behaviour when `fail_open=false`: instead of passing the request through, the middleware returns **503 `captcha_unavailable`** with `should_retry=true`, `should_rerender=false`. The default (and the pre-pilot setting) is `fail_open=true`; flip to `false` per sensitive surface only after a measured ATO incident motivates it (§16). **Shadow mode ignores `fail_open`** — shadow is observation-only and must never reject a real user, regardless of `fail_open` setting.

### 10.2 Circuit breaker (Redis-backed, pipelined-atomic)

```php
namespace App\Services\BotProtection;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class CircuitBreaker
{
    public function __construct(
        private int $failureThreshold = 5,
        private int $windowSeconds    = 60,
        private int $cooldownSeconds  = 300,
    ) {}

    public function isOpen(string $driver): bool
    {
        return (bool) Redis::get("bot_protection:cb:{$driver}:open");
    }

    public function recordFailure(string $driver): void
    {
        $key = "bot_protection:cb:{$driver}:failures";

        // Pipelined INCR + EXPIRE — both commands sent together. Unconditionally
        // refreshing the TTL on every failure eliminates the "expire only on count==1"
        // race that could leave the counter without a TTL. Trade-off: window slides
        // slightly with each failure (an attacker pacing failures at threshold-1
        // could keep the counter alive longer than 60s), accepted per §15.
        $results = Redis::pipeline(function ($pipe) use ($key) {
            $pipe->incr($key);
            $pipe->expire($key, $this->windowSeconds);
        });
        $count = $results[0];

        if ($count >= $this->failureThreshold) {
            $this->trip($driver);
        }
    }

    public function recordSuccess(string $driver): void
    {
        Redis::del("bot_protection:cb:{$driver}:failures");
        // The 'open' key is intentionally NOT deleted — cooldown TTL handles auto-recovery.
        // This creates flapping behaviour during extended outages (open → cooldown → re-trip);
        // accepted per §15 known limitations.
    }

    private function trip(string $driver): void
    {
        $wasAlreadyOpen = $this->isOpen($driver);
        Redis::setex(
            "bot_protection:cb:{$driver}:open",
            $this->cooldownSeconds,
            (string) now()->timestamp,
        );
        if (! $wasAlreadyOpen) {
            Log::warning('bot_protection.circuit_open', ['driver' => $driver]);
        }
        // Re-trip during sustained outage: do not log again. Accepted log silence during
        // a stuck-open outage. Trade-off documented in § 15.
    }

    /** For tests: simulate cooldown expiry without sleeping. */
    public function reset(string $driver): void
    {
        Redis::del("bot_protection:cb:{$driver}:open");
        Redis::del("bot_protection:cb:{$driver}:failures");
    }
}
```

**Redis-down handling in the middleware.** Wrap *every* breaker call in `try/catch (\Throwable $e)`. On any exception, log `bot_protection.breaker_unavailable` (deduped via a Redis-counter cooldown window — same pattern as `logFailOpenOnce` — to keep observability working across long-lived workers without spamming logs) and pass-through (fail-open). If Redis itself is the failure mode, the dedup INCR also throws and we silently skip the log; matches the "observability failure must not break the request" rule used everywhere else in this file.

### 10.3 Once-per-state-transition fail-open logging

During an extended outage at high RPS, naïve per-request logging emits `Log::warning('bot_protection.fail_open')` thousands of times per minute. Mitigation: maintain a Redis-incremented counter keyed on `bot_protection:fail_open_logged:{$driver}` with the same TTL as the cooldown key; log only when counter increments to 1. This bounds log volume to one event per cooldown window per driver per outage. Per-request `provider_error` events (pre-trip) are not deduplicated — volume is naturally bounded by the breaker threshold (max 5 events per outage onset).

### 10.4 Observability event shape

```php
Log::warning('bot_protection.fail_open', [
    'driver'     => $driver,
    'reason'     => 'provider_timeout' | 'provider_error' | 'circuit_open' | 'breaker_unavailable',
    'action'     => $action,
    'route'      => $request->route()?->getName() ?? $request->path(),
    'ip'         => $request->ip(),
    'request_id' => $request->header('X-Request-Id'),
]);
```

Per `reference_nightwatch_alerts.md` in user memory: `Log::warning` is breadcrumb-only; review trends weekly. Add paging alert only after baseline reveals a category that warrants proactive attention.

### 10.5 Rejection response shape (contract for frontend)

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/json

{
  "message": "Verification failed.",
  "error": "captcha_failed",
  "captcha": {
    "should_retry": true,
    "should_rerender": true
  }
}
```

Error values: `captcha_missing`, `captcha_failed`, `captcha_expired`.

**Provider-specific error codes are not exposed to the client** — logging happens server-side only via the `errorCodes` array on the `VerificationResult`. Exposing codes like `'invalid-input-response'` would help attackers tune bypass attempts.

## 11. Testing strategy

### 11.1 Test doubles

| Provider | Usage | Behaviour |
|---|---|---|
| `NullProvider` | `phpunit.xml`, `composer dev` local | Always `success=true`. Zero network (asserted via `Http::assertNothingSent()`). |
| `FakeProvider` | Per-test scripted scenarios | Instance-scoped result queue (not static) — `app()->instance(CaptchaProvider::class, new FakeProvider())` in `beforeEach`. Exposes `queueResult(VerificationResult)`, `lastAction(): ?string`, `verifyCount(): int`. |
| `TurnstileProvider` + Cloudflare test keys | Nightly integration test | Real-wire test against `challenges.cloudflare.com`. Skips on connectivity failure (does not fail CI). |

### 11.2 Test pyramid

**Unit tests** (`tests/Unit/Services/BotProtection/`):
- `CaptchaManager` resolves correct driver from config; throws `CaptchaConfigurationException` on unknown driver.
- `VerificationResult` construction and immutability.
- `CircuitBreaker` — open/close transitions, pipelined INCR+EXPIRE under concurrent simulation, cooldown behaviour via `reset()` method (not Redis TTL — `Redis::fake()` does not honour TTL).
- `TurnstileProvider::verify()` payload construction, success/failure parsing, exception on 5xx/timeout — `Http::fake()`.
- `TurnstileProvider` `timeout-or-duplicate` → `captcha_expired` mapping.
- Same suite for `HCaptchaProvider`.
- `NullProvider::verify()` always succeeds, asserts `Http::assertNothingSent()`.

**Feature tests** (`tests/Feature/Http/Middleware/VerifyBotTokenTest.php`):
- `BOT_PROTECTION_MODE=off` → no token required, request passes, **`Http::assertNothingSent()`** (no provider call even if Redis is down).
- `BOT_PROTECTION_MODE=enforce` + no token → 422 `captcha_missing`.
- `BOT_PROTECTION_MODE=enforce` + whitespace-only token (`"   "`) → 422 `captcha_missing` (whitespace normalisation).
- `BOT_PROTECTION_MODE=enforce` + empty-string header → falls through to body field; if both missing → 422 `captcha_missing`.
- `BOT_PROTECTION_MODE=enforce` + valid token (FakeProvider) → passes.
- `BOT_PROTECTION_MODE=enforce` + invalid token → 422 `captcha_failed` with documented response shape (assert response body has NO `codes` field).
- `BOT_PROTECTION_MODE=enforce` + Turnstile `timeout-or-duplicate` → 422 `captcha_expired`.
- `BOT_PROTECTION_MODE=enforce` + legacy `cf_turnstile_response` body field → passes (compatibility).
- `BOT_PROTECTION_MODE=shadow` + invalid token → 200 + `Log::assertLogged('bot_protection.shadow_reject')`.
- `BOT_PROTECTION_MODE=shadow` + provider throws → 200 + `Log::assertLogged('bot_protection.fail_open')` + `Log::assertNotLogged('bot_protection.shadow_reject')` (distinct events for distinct gate metrics).
- Provider throws → 200 + `Log::assertLogged('bot_protection.fail_open')` + breaker failure recorded.
- Circuit breaker open → no provider call made (`Http::assertNothingSent()`) + 200 + `wasFailOpen=true` event.
- Redis down (simulate via mock throwing) → 200 + `Log::assertLogged('bot_protection.breaker_unavailable')` + no 500.
- Action tag round-trip: FakeProvider captures action; after request to `/public/enquiry`, assert `FakeProvider::lastAction() === 'enquiry'`.

**Endpoint integration tests** — for the 4 protected endpoints (`tests/Feature/PublicSite/`):
- Happy path: valid token → controller runs → expected side-effect (email queued, DB row created).
- Sad path: missing token in enforce mode → 422, no side-effect.
- Shadow regression: missing token + shadow mode → request passes, side-effect runs.

Existing per-endpoint tests get scenarios added; the old `tests/Feature/PublicSite/CaptchaMiddlewareTest.php` is deleted.

**Provider 4xx config errors — caught at boot, not at runtime.** Per §6.7 Guard 3, the service provider refuses to boot if the active driver's secret is missing. There is therefore no runtime "provider returns 4xx because of bad config" test — that failure mode cannot reach the middleware. If a 4xx code is returned by a properly-configured provider (e.g., Cloudflare changing their API), it falls through the `CaptchaProviderException` path and fails-open. The boot guard is unit-tested directly in `tests/Unit/Providers/BotProtectionServiceProviderTest.php`.

**Real-wire integration test** (`tests/Integration/TurnstileIntegrationTest.php`, `@group integration`):
- Pre-check: `Http::timeout(2)->get('https://challenges.cloudflare.com')`; on failure, `$this->markTestSkipped(...)`. This prevents Cloudflare connectivity issues from failing nightly CI.
- Hits real `challenges.cloudflare.com` with `1x00000000000000000000AA` (always-pass) key.
- Skipped unless `CI_RUN_INTEGRATION=true`.

### 11.3 Coverage sweep test

`tests/Feature/Security/BotProtectionCoverageTest.php`:

```php
<?php

use App\Http\Middleware\VerifyBotToken;
use Illuminate\Support\Facades\Route;

// File-scope const (not class-scope) — Pest tests are not classes.
// Mirrors the pattern in tests/Feature/Security/PolicyCoverageTest.php.
// NOTE: URIs here include the `api/` prefix because that's what Laravel's
// withRouting(api: 'routes/api.php') registers them under — `$route->uri()`
// returns `api/public/enquiry`, not `public/enquiry`. The first version of
// this spec dropped the `api/` prefix; the shipping test corrects that.
const BOT_PROTECTION_EXEMPT = [
    // Analytics: token-volume too high for interactive CAPTCHA; rate-limit + UA filter cover it.
    'api/public/analytics/pageviews',
    'api/public/analytics/clicks',
    'api/public/analytics/section-seen',
    // Resolve-identifier: enumeration defence via constant-time jitter, not interactive CAPTCHA.
    'api/public/auth/resolve-identifier',
    // Signup-availability: see §16 future-work plan.
    'api/public/signup/availability',
    // Unsubscribe: token-gated per RFC 8058.
    'api/public/unsubscribe/{token}',
    // Webhooks (api/internal/...) are not matched by the URI prefix filter below,
    // so they don't need exempt entries.
];

const BOT_PROTECTION_URI_PREFIXES = ['api/public/', 'api/v1/public/'];

const BOT_PROTECTION_METHODS = ['POST', 'PUT', 'PATCH'];

function bot_protection_route_has_token_middleware($route): bool
{
    // Use gatherMiddleware() to include group-level middleware, not just per-route.
    $middleware = collect($route->gatherMiddleware());
    return $middleware->contains(fn ($m) =>
        $m === 'bot.token'
        || str_starts_with($m, 'bot.token:')
        || $m === VerifyBotToken::class
        || str_starts_with($m, VerifyBotToken::class.':')
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
            ->toBeTrue("Route {$route->uri()} is public mutation without bot.token middleware. Add bot.token or add to BOT_PROTECTION_EXEMPT with justification.");
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

**`has_auth_middleware()`** lives in `tests/Pest.php` alongside the project's other Pest helpers:

```php
function has_auth_middleware($route): bool
{
    return collect($route->gatherMiddleware())->some(fn ($m) =>
        $m === 'supabase.jwt'
        || str_starts_with($m, 'professional.api')
        || $m === 'staff'
        || $m === 'staff.admin'
    );
}
```

### 11.4 CI matrix

```
Default (every PR):
  BOT_PROTECTION_DRIVER=null  BOT_PROTECTION_MODE=off       # baseline, fast
  BOT_PROTECTION_DRIVER=null  BOT_PROTECTION_MODE=enforce   # confirms null driver passes (used via FakeProvider in tests)

Nightly only (allow_failure: true):
  CI_RUN_INTEGRATION=true                                    # one real-wire Turnstile test, skipped on connectivity failure
```

### 11.5 FakeProvider reset hook

In `tests/Pest.php`:

```php
uses()->beforeEach(function () {
    app()->instance(
        App\Services\BotProtection\Contracts\CaptchaProvider::class,
        new App\Services\BotProtection\Providers\FakeProvider(),
    );
})->in('Feature/Http/Middleware', 'Feature/PublicSite');
```

This binds a fresh FakeProvider per test, eliminating state bleed.

## 12. Rollout (pre-pilot)

Partna has zero users at the time this spec lands. Staged gates with 7-day soak windows would be theatre. The plan is:

1. **Build it** — merge the PR (abstraction + middleware + tests + service provider + `.env.example` + Pest helpers). CI green is the only gate.
2. **Test it in dev** — set the dev env vars in §12.2. Manually submit each of the 4 protected forms with valid + missing + bad tokens. Verify the boot guards fire on misconfig (e.g., temporarily unset `TURNSTILE_SECRET` and confirm the app refuses to boot). Check Cloudflare dashboard shows verifications under the right action tags.
3. **Ship to prod** — set the prod env vars. Same manual smoke test. Configure Supabase Dashboard for signup/login/reset and run the Path B verification curl (§8).
4. **Done.**

Total elapsed time: hours, not weeks. If something breaks, flip `BOT_PROTECTION_MODE=off` via env var + worker restart and dig in.

**The `shadow` mode is built but unused for now.** It costs nothing in the code; it's there for the day there's real traffic and you want to change something (new surface, threshold tweak, provider swap) without risk. When that day comes, the workflow is: flip to `shadow`, watch logs for a few days, flip to `enforce`. Document the choice in that PR.

### 12.1 Future-proofing

When the platform has real users and a new public mutation endpoint is being added, the contributor should:

1. Set `bot.token:<action>` middleware on the new route (otherwise `BotProtectionCoverageTest` fails CI).
2. If the surface is high-risk (account mutation, payment), consider a brief `shadow` period before flipping to `enforce`.
3. If the surface is low-risk (one more contact-form variant), just ship `enforce` and watch logs.

No process gate required. The runtime knobs make this self-service.

### 12.2 Env var changes

**`.env.example` updates (delivered in the PR):**

```diff
- CLOUDFLARE_TURNSTILE_SECRET_KEY=
- # Enable bot protection by also setting PARTNA_CAPTCHA_ENABLED=true
- PARTNA_CAPTCHA_ENABLED=false

+ # Bot protection foundation — see docs/superpowers/specs/2026-05-26-bot-protection-foundation-design.md
+ BOT_PROTECTION_DRIVER=null          # null | turnstile | hcaptcha. Default null for local; set to turnstile in deployed envs.
+ BOT_PROTECTION_MODE=off             # off | shadow | enforce. Default off locally; set enforce in deployed envs.
+ BOT_PROTECTION_FAIL_OPEN=true       # Pre-pilot default; revisit fail-closed per sensitive surface after first real incident.
+ TURNSTILE_SITE_KEY=
+ TURNSTILE_SECRET=
+ HCAPTCHA_SITE_KEY=
+ HCAPTCHA_SECRET=
```

**Laravel Cloud dev (set when shipping the PR):**
```
BOT_PROTECTION_DRIVER=turnstile
BOT_PROTECTION_MODE=enforce
BOT_PROTECTION_FAIL_OPEN=true
TURNSTILE_SITE_KEY=<dev-publishable-key>
TURNSTILE_SECRET=<dev-secret>
```

**Laravel Cloud production (set when shipping to prod):**
```
BOT_PROTECTION_DRIVER=turnstile
BOT_PROTECTION_MODE=enforce
BOT_PROTECTION_FAIL_OPEN=true
TURNSTILE_SITE_KEY=<prod-publishable-key>   # separate site key per CF best practice
TURNSTILE_SECRET=<prod-secret>
```

### 12.3 Legacy flag deprecation

The legacy `PARTNA_CAPTCHA_ENABLED` is bridged in `BotProtectionServiceProvider::bridgeLegacyFlag()`. The bridge reads from `config()` (not `env()`) so it works in config-cached production:

```php
private function bridgeLegacyFlag(): void
{
    static $warned = false;

    // The legacy flag was loaded into config under `partna.features.captcha` per the old config block.
    // We keep that config key for one release as the bridge; both keys live for one release.
    $legacy = config('partna.features.captcha');
    if ($legacy !== false && $legacy !== null && ! $warned) {
        $warned = true;
        Log::warning('PARTNA_CAPTCHA_ENABLED / partna.features.captcha is deprecated; use BOT_PROTECTION_MODE=off|shadow|enforce.');
    }

    // If the operator hasn't explicitly set the new mode but has the legacy flag enabled,
    // map to 'enforce' for safety. Strict === true comparison — the env-cast string "true"
    // is converted by env() to PHP true, but only set if explicitly enabled.
    if ($legacy === true && config('partna.bot_protection.mode') === 'off') {
        config(['partna.bot_protection.mode' => 'enforce']);
    }
}
```

**Why this works under `config:cache`.** Laravel reads env vars *into* config at cache-build time. Once cached, `env()` returns `null` at runtime, but `config()` returns the cached value. Reading `config('partna.features.captcha')` is correct in both cached and uncached modes.

**The `static $warned` flag** ensures the deprecation log fires once per worker process, not once per request — preventing log noise from the legacy flag remaining set.

Delete the env-var alias and the bridge code one release after this ships.

### 12.4 Rollback

If anything goes wrong after shipping:

- **Soft rollback** — `BOT_PROTECTION_MODE=shadow` + worker restart (`php artisan queue:restart` or Laravel Cloud rolling restart). Requests pass through; logs still record what *would* have been rejected. ~30s to take effect.
- **Hard rollback** — `BOT_PROTECTION_MODE=off` + worker restart. Middleware no-ops entirely. ~30s.

Rollback is **fast** (~30s), not **instant**. PHP workers are long-lived and read config at boot; env-var changes need a restart to propagate. Plan for that 30-second window during which the bad mode persists.

### 12.5 Monitoring

Pre-pilot: there are no users, so there's nothing to monitor proactively. Look at the `bot_protection.*` log events in Nightwatch if a form submission fails during testing or if Cloudflare reports something unusual.

Once there's traffic (post-pilot), the things worth watching:

- `bot_protection.fail_open` rate — sustained non-zero means Cloudflare is having trouble or the breaker is tripping.
- `bot_protection.shadow_reject` rate — only relevant if you start using shadow mode.
- Cloudflare dashboard → Turnstile → Analytics → Verifications — watch volume vs the 1M/month free-tier limit. Per §15.3, set a threshold alert at 800K once volume approaches that point.

No paging alerts at launch. Add them later if a specific category of failure starts warranting one.

## 13. Supabase Dashboard configuration (parallel track, manual)

Documented in `docs/auth/bot-protection-supabase.md` (new file, delivered in the PR):

1. Supabase Dashboard → Project (`glncumufgaqcmqhzwrxm` dev / `edplucmvkcnokyygxqsb` prod) → Authentication → Settings → Bot and Abuse Protection.
2. Provider: **Turnstile**. Secret: same secret Laravel uses (one secret, two consumers).
3. Coverage: enabled for signup, signin, password recovery, magic link.
4. Frontend (Astro) wraps Supabase Auth client calls with the same Turnstile token:
   ```js
   supabase.auth.signUp({ email, password, options: { captchaToken } })
   ```
5. **Path B verification (§8)** before promoting to enforce. Curl test result captured in the runbook.
6. No Laravel code changes for this path.

## 14. Frontend contract (handoff to frontend session)

| Backend gives them | Frontend gives back |
|---|---|
| `TURNSTILE_SITE_KEY` value (publishable) — provided at frontend build time as an env var; **no `/public/config` endpoint built** (committed to env-only contract) | Token in `X-Captcha-Token` header on every protected POST |
| List of actions: `enquiry`, `lead`, `waitlist`, `subscribe`, `report` | Per-form `action` attribute on the Turnstile widget |
| 422 response shape documented (§10.5) — error codes `captcha_missing`, `captcha_failed`, `captcha_expired` | UI re-renders widget on `captcha_failed` / `captcha_expired` |
| Cloudflare test site key (`1x00000000000000000000AA`) for local dev | `<Turnstile>` component wired into all 5 forms |
| Body-field fallback: backend accepts `captcha_token` body for non-JS forms AND `cf_turnstile_response` for legacy compat (one release) | Migrate to `captcha_token` field name within one release |

**Provider-swap coordination caveat.** The spec's "swap providers via one env var" claim applies to backend code only. A real provider swap also requires:
1. Frontend Astro widget component change (`<Turnstile>` → `<HCaptcha>` etc.).
2. Supabase Dashboard Bot Protection provider re-selection.
3. New site key supplied to the frontend build-time env var.

These are coordinated changes across repos and are not abstracted by this design. The backend abstraction makes the **backend** swap mechanical; the system-wide swap remains a multi-team task.

Backend and frontend must ship together for `enforce` mode to work — the frontend has to render the widget and send the token. If the frontend isn't ready when the backend is, set `BOT_PROTECTION_MODE=off` until it lands (or `shadow` if you want to start collecting baseline logs).

## 15. Known limitations (deliberate trade-offs)

These are documented choices, not bugs — flagged so they're visible to future reviewers and trigger reconsideration when conditions change.

1. **Concurrent token replay within validity window** — Turnstile tokens are single-use, but the spec relies on Turnstile's own idempotency. A simultaneous double-submit (two requests racing with the same token) is not server-side deduplicated. Acceptable at current scale; add a Redis dedup layer if replay abuse is measured.

2. **Circuit breaker flapping during extended outages** — During a 30-minute Cloudflare outage, the breaker opens (5 min cooldown), TTL expires, next failure batch reopens it, repeat. Each re-trip after the first within an outage is silent (not logged) to avoid spam. Acceptable; revisit with exponential backoff if outage patterns become problematic.

3. **Turnstile free-tier cliff at ~10K MAU** — Free tier is 1M siteverify/month. At 4 protected surfaces × 5 submissions/user/day × 10K MAU = ~50K submissions/day = ~1.5M/month. Monitoring threshold (§12.5) at 800K warns before the cliff. No paid intermediate Turnstile tier exists today; the alternative is hCaptcha (100K free) or Cloudflare Enterprise (~$2K/mo).

4. **Log volume during outages** — Once-per-state-transition logging caps `fail_open` events at one per cooldown window, but `provider_error` events (pre-trip) are per-request, naturally bounded by the threshold (5 events per outage onset). Acceptable. If Nightwatch costs spike, add sampling.

5. **Manual provider failover** — No automatic primary→fallback driver switch. Failover requires env-var change + worker restart. At pre-beta scale this is fine. Document as a limitation if a real outage motivates auto-failover.

6. **Cloudflare Turnstile analytics 7-day retention** — Per-surface action tag analytics live in CF dashboard with 7-day retention on free tier. Longer-term analysis requires either Enterprise tier or exporting our own structured `bot_protection.*` log events (which Nightwatch retains independently).

8. **Token field rename frontend coordination** — `cf_turnstile_response` → `captcha_token` requires frontend update within one release. Backend accepts both for the transition window then deletes the legacy alias.

8. **`$request->ip()` accuracy depends on trusted-proxy config** — §6.7 boot guard warns if unconfigured but does not refuse boot. Verify before production deploy.

9. **Failure-window sliding under attacker pacing** — Pipelined unconditional EXPIRE means an attacker who paces failures at threshold-1 (4 failures every 59 seconds) could keep the counter alive indefinitely without ever tripping the breaker. The trade-off vs. the immortal-counter race in the conditional-EXPIRE pattern is worth it; the attack is low-value (just causes more provider load, not bypass).

10. **Conditionally-registered routes escape the coverage sweep** — `Route::getRoutes()` returns only routes registered at test bootstrap. A route guarded by `if (config('feature.X')) { Route::post(...); }` with the flag off in the test environment is invisible to `BotProtectionCoverageTest`. Mitigations: prefer always-register-with-controller-level-gating over env-gated registration for any public mutation endpoint; if route-level gating is unavoidable, add the route URI to a separate `CONDITIONALLY_REGISTERED` list in the test file with a justification, and review the list on deploy. Today no such routes exist; documented here so it's a conscious choice the first time someone considers env-gating a public endpoint.

## 16. Future work (out of scope)

- **Tier 3 hardening** — extend coverage to `/public/analytics/*`, `/public/auth/resolve-identifier`, `/public/signup/availability`. Requires different strategy than interactive CAPTCHA (token-based or behavioural).
- **Per-email dedup on lead forms** — orthogonal to CAPTCHA; would catch list pollution that survives CAPTCHA-solving services.
- **FingerprintJS** — for ATO detection once MFA ships and a real ATO incident gives baseline data.
- **Fail-closed for sensitive surfaces** — flip password-reset / account-mutation to fail-closed if a CF outage causes measurable abuse.
- **Cloudflare Bot Management Enterprise tier** — when traffic justifies and Tier 3 hardening proves insufficient.
- **Automatic provider failover** — primary→fallback driver switch without operator intervention; design once a real outage justifies the complexity.
- **Redis dedup of token replay** — see §15.1.
- **Exponential backoff on circuit breaker** — see §15.2.
- **Log sampling for fail-open events at high RPS** — see §15.4.

## 17. Decisions made during 7-lens review

These are decisions explicitly raised by review and resolved here so they don't re-litigate in implementation.

1. **`CaptchaProvider` interface naming stays.** The outer namespace `App\Services\BotProtection\` is the conceptual category (bot protection signals broadly); the interface itself is CAPTCHA-specific. Future sibling concerns (honeypot service, fingerprint service) live as peer namespaces under `BotProtection/`, not as implementations of `CaptchaProvider`. This is a deliberate vocabulary choice: "bot protection" is a domain; "captcha" is one mechanism within that domain.

2. **`BotToken.php` Form Request rule deleted from deliverables.** Middleware-as-route-attribute covers every surface in this spec; the rule would be dead code. Add it when a concrete consumer (e.g., conditional CAPTCHA on N-failed login) lands.

3. **`/public/config` endpoint NOT built.** Frontend gets `TURNSTILE_SITE_KEY` via build-time env var (Astro-native pattern). Adding an API endpoint for this is extra surface area for a value that can be baked at build time.

4. **No automatic provider failover at launch.** Manual env-var failover is acceptable at pre-pilot; auto-failover adds complexity and an availability dependency on whichever provider is "primary." Document as known limitation (§15.5); revisit on real outage.

5. **Coverage sweep extended to POST + PUT + PATCH; DELETE excluded.** Anonymous DELETE on public endpoints is rare and high-friction by design (typically token-gated). Add DELETE if a public DELETE surface ever exists.

6. **`v1/public/` URI prefix added to coverage sweep filter.** The T&S spec's `/v1/public/report` would otherwise escape; this prevents that. Sweep filter is `['api/public/', 'api/v1/public/']` — `api/` is included because `$route->uri()` returns the path Laravel registered the route under, which includes the `withRouting(api: ...)` prefix.

7. **Fail-open extended to Redis failures.** If the cache Redis is down, the middleware passes-through with a `breaker_unavailable` log event. Same philosophy as provider fail-open: availability beats brief windows of bot exposure at this scale.

## 18. Open questions

None at design close. All architectural decisions are locked per §4 and §17.
