<?php

use App\Providers\BotProtectionServiceProvider;
use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Snapshot and restore TrustProxies::$alwaysTrustProxies so guard-4 tests can't
// leak state into other tests (the static persists across tests within a process).
// Property is protected — use reflection. Same approach the production code uses.
function bp_test_proxies_get()
{
    $r = new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
    $r->setAccessible(true);

    return $r->getValue();
}

function bp_test_proxies_set($value): void
{
    $r = new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
    $r->setAccessible(true);
    $r->setValue(null, $value);
}

beforeEach(function () {
    $this->originalProxies = bp_test_proxies_get();
});

afterEach(function () {
    bp_test_proxies_set($this->originalProxies);
});

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
        'partna.bot_protection.mode' => 'enforce',
    ]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'silent no-op');
});

it('boot-guard refuses Cloudflare test site key in production', function () {
    config([
        'partna.bot_protection.driver' => 'turnstile',
        'partna.bot_protection.mode' => 'enforce',
        'partna.bot_protection.drivers.turnstile.site_key' => '1x00000000000000000000AA',
        'partna.bot_protection.drivers.turnstile.secret' => 'any-secret',
    ]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'test site key');
});

it('boot-guard refuses missing secret for an active driver', function () {
    config([
        'partna.bot_protection.driver' => 'turnstile',
        'partna.bot_protection.drivers.turnstile.secret' => '',
    ]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'secret is not set');
});

it('allows null driver + enforce mode outside production', function () {
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode' => 'enforce',
    ]);
    app()->detectEnvironment(fn () => 'local');

    (new BotProtectionServiceProvider(app()))->boot();
    expect(true)->toBeTrue();
});

it('boot-guard throws CaptchaConfigurationException when mode is off in production', function () {
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode' => 'off',
    ]);
    app()->detectEnvironment(fn () => 'production');
    bp_test_proxies_set('*'); // configure proxies so guard-5 doesn't also fire

    expect(fn () => (new BotProtectionServiceProvider(app()))->boot())
        ->toThrow(CaptchaConfigurationException::class, 'MODE=off in production');
});

it('boot-guard does not throw for mode off outside production', function () {
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode' => 'off',
    ]);
    app()->detectEnvironment(fn () => 'local');
    bp_test_proxies_set('*');

    // Must not throw
    (new BotProtectionServiceProvider(app()))->boot();
    expect(true)->toBeTrue();
});

it('boot-guard does not throw for mode off in testing env', function () {
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode' => 'off',
    ]);
    app()->detectEnvironment(fn () => 'testing');
    bp_test_proxies_set('*');

    // Must not throw
    (new BotProtectionServiceProvider(app()))->boot();
    expect(true)->toBeTrue();
});

it('guard-5 logs warning when trusted proxies are unconfigured in a deployed env', function () {
    Log::spy();
    config([
        'partna.bot_protection.driver' => 'null',
        // Use shadow (not off) so guard-4's new throw doesn't preempt guard-5.
        'partna.bot_protection.mode' => 'shadow',
    ]);
    app()->detectEnvironment(fn () => 'production');
    bp_test_proxies_set(null);

    (new BotProtectionServiceProvider(app()))->boot();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'bot_protection.trusted_proxies_unconfigured')
        ->once();
});

it('guard-5 stays quiet when trusted proxies are configured', function () {
    Log::spy();
    config([
        'partna.bot_protection.driver' => 'null',
        // Use shadow (not off) so guard-4's new throw doesn't preempt guard-5.
        'partna.bot_protection.mode' => 'shadow',
    ]);
    app()->detectEnvironment(fn () => 'production');
    bp_test_proxies_set('*');

    (new BotProtectionServiceProvider(app()))->boot();

    Log::shouldNotHaveReceived('warning', fn ($msg) => $msg === 'bot_protection.trusted_proxies_unconfigured');
});

it('guard-5 stays quiet in local/testing even when proxies are unconfigured', function () {
    Log::spy();
    config([
        'partna.bot_protection.driver' => 'null',
        'partna.bot_protection.mode' => 'off',
    ]);
    app()->detectEnvironment(fn () => 'local');
    bp_test_proxies_set(null);

    (new BotProtectionServiceProvider(app()))->boot();

    Log::shouldNotHaveReceived('warning', fn ($msg) => $msg === 'bot_protection.trusted_proxies_unconfigured');
});
