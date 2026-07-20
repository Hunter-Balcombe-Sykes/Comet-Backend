<?php

use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\VerificationResult;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

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

it('enforce mode rejects 400 when token missing', function () {
    config(['partna.bot_protection.mode' => 'enforce']);

    $response = $this->postJson('/__test/bot-protected');

    $response->assertStatus(400);
    $response->assertJson(['error' => 'captcha_missing']);
    expect($response->json('captcha.codes' ?? null))->toBeNull();  // raw codes NOT exposed
});

it('enforce mode rejects 400 when token is whitespace-only', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => '   ']);
    $response->assertStatus(400)->assertJson(['error' => 'captcha_missing']);
});

it('enforce mode passes when FakeProvider returns success', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: true));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok-token']);

    $response->assertOk();
});

it('enforce mode rejects 400 captcha_failed on FakeProvider failure', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: false, errorCodes: ['invalid-input-response']));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'bad']);

    $response->assertStatus(400)->assertJson(['error' => 'captcha_failed']);
});

it('enforce mode rejects 400 captcha_expired when codes include the sentinel', function () {
    config(['partna.bot_protection.mode' => 'enforce']);
    app(FakeProvider::class)->queueResult(new VerificationResult(success: false, errorCodes: ['timeout-or-duplicate', 'captcha_expired']));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'expired']);

    $response->assertStatus(400)->assertJson(['error' => 'captcha_expired']);
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

it('enforce + fail_open=false rejects 503 captcha_unavailable on provider exception', function () {
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => false,
    ]);
    app(FakeProvider::class)->queueException(new CaptchaProviderException('boom'));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertStatus(503)->assertJson(['error' => 'captcha_unavailable']);
});

it('enforce + fail_open=false rejects 503 when circuit breaker is open', function () {
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => false,
        'partna.bot_protection.circuit_breaker.failure_threshold' => 1,
    ]);
    // Trip the breaker via a single provider failure.
    app(CircuitBreaker::class)->recordFailure('fake');

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertStatus(503)->assertJson(['error' => 'captcha_unavailable']);
});

it('enforce + fail_open=false rejects 503 when Redis breaker check throws', function () {
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => false,
    ]);
    // Force the breaker's Redis call to throw — simulates Redis-down.
    // CircuitBreaker is final so we mock the facade, not the breaker class.
    Redis::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    Redis::shouldReceive('incr')->andThrow(new RuntimeException('redis down'));
    Redis::shouldReceive('expire')->andReturn(true);

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertStatus(503)->assertJson(['error' => 'captcha_unavailable']);
});

// WHK-3 regression proof — the breaker's own Redis::get throw lands us on the
// breaker_unavailable path, which then asks firstHitInWindow() (also Redis)
// "have we already alerted this window?". Before the fix, a throw there
// returned `false` — indistinguishable from "already alerted" — so the one
// alert that says "Redis is down" was silently muted every single time. This
// MUST fail against the pre-fix code (firstHitInWindow(): bool, throttledFailReport
// returning early on any falsy/null): verified by reverting the src change
// locally and re-running — Log::warning + Exceptions::assertReportedCount both
// see zero calls, so both assertions below fail.
it('logs and reports breaker_unavailable when the dedup store is ALSO unreachable', function () {
    Log::spy();
    Exceptions::fake();
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => false,
    ]);
    Redis::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertStatus(503)->assertJson(['error' => 'captcha_unavailable']);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'bot_protection.breaker_unavailable')
        ->once();
    Exceptions::assertReportedCount(1);
});

// WHK-3 sibling — circuit genuinely open (isOpen()'s Redis::get succeeds) but
// the SEPARATE dedup-store round-trip inside firstHitInWindow (Redis::eval,
// left unstubbed here so it throws) is unreachable. Proves the null-fallthrough
// isn't accidentally scoped to only the breaker_unavailable caller.
it('logs and reports circuit_open fail-open when the dedup store is unreachable', function () {
    Log::spy();
    Exceptions::fake();
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => false,
    ]);
    Redis::shouldReceive('get')->andReturn('1');

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    $response->assertStatus(503)->assertJson(['error' => 'captcha_unavailable']);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg, $ctx = []) => $msg === 'bot_protection.fail_open' && ($ctx['reason'] ?? null) === 'circuit_open')
        ->once();
    Exceptions::assertReportedCount(1);
});

it('shadow + fail_open=false still passes through on provider exception', function () {
    Log::spy();
    config([
        'partna.bot_protection.mode' => 'shadow',
        'partna.bot_protection.fail_open' => false,
    ]);
    app(FakeProvider::class)->queueException(new CaptchaProviderException('boom'));

    $response = $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok']);

    // Shadow mode must NEVER reject a real user, even with fail_open=false.
    $response->assertOk();
});

// TEST-3 — circuit_open path: throttledFailReport() dedups BOTH log AND report
// to once per cooldown window, then fires again once the window resets. Drives
// the real circuit-open branch (isOpen() === true) rather than mocking Redis::eval
// — this repo's bot-protection suite uses live Redis (see beforeEach flushdb above).
it('dedups circuit-open fail-open log+report once per cooldown window, then again after the window resets', function () {
    Log::spy();
    Exceptions::fake();
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => true,
        'partna.bot_protection.circuit_breaker.failure_threshold' => 1,
    ]);
    // Trip the breaker so every request below hits the circuit-open branch.
    app(CircuitBreaker::class)->recordFailure('fake');

    // Three requests inside the same cooldown window — only the first should log/report.
    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok'])->assertOk();
    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok'])->assertOk();
    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok'])->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'bot_protection.fail_open')
        ->once();
    Exceptions::assertReportedCount(1);

    // The dedup key carries a TTL — proves the EXPIRE landed in the SAME eval as the
    // INCR (a TTL is present, not orphaned). This is a same-round-trip check, not a
    // full crash-atomicity proof (that would need mid-eval fault injection).
    expect(Redis::ttl('bot_protection:fail_open_logged:fake:circuit_open'))->toBeGreaterThan(0);

    // Simulate cooldown-window expiry (no real sleep) by clearing the dedup key directly.
    Redis::del('bot_protection:fail_open_logged:fake:circuit_open');

    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok'])->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'bot_protection.fail_open')
        ->twice();
    Exceptions::assertReportedCount(2);
});

// TEST-3 (OBS-4 regression tripwire) — provider_error path logs EVERY failure
// (unconditional, Redis-independent) but throttles only the Nightwatch report()
// to once per cooldown window. Breaker is NOT open here; the provider throws.
// This MUST fail if provider_error's log ever gets throttled again.
it('logs every provider-error fail-open but throttles the report to once per window', function () {
    Log::spy();
    Exceptions::fake();
    config([
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.fail_open' => true,
    ]);
    // Queue two provider exceptions — one per request, both in the same window.
    app(FakeProvider::class)->queueException(new CaptchaProviderException('boom'));
    app(FakeProvider::class)->queueException(new CaptchaProviderException('boom'));

    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok'])->assertOk();
    $this->postJson('/__test/bot-protected', [], ['X-Captcha-Token' => 'ok'])->assertOk();

    // Log fires on BOTH requests (unconditional) — this is the regression guard.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg, $ctx = []) => $msg === 'bot_protection.fail_open' && ($ctx['reason'] ?? null) === 'provider_error')
        ->twice();
    // report() throttled to once per cooldown window.
    Exceptions::assertReportedCount(1);
});
