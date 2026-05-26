<?php

use App\Providers\BotProtectionServiceProvider;
use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;

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
