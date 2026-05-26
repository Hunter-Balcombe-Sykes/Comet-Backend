<?php

use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\VerificationResult;
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
