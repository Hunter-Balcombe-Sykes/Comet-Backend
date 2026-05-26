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
