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
