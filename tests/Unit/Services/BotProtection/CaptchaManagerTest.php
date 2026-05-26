<?php

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\Providers\HCaptchaProvider;
use App\Services\BotProtection\Providers\NullProvider;
use App\Services\BotProtection\Providers\TurnstileProvider;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

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
    $fake = new FakeProvider;
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
    $fake = new FakeProvider;
    app()->instance(FakeProvider::class, $fake);

    $manager = new CaptchaManager(app());
    $manager->verify('tok', '1.2.3.4', 'enquiry');

    expect($fake->lastAction())->toBe('enquiry');
    expect($fake->verifyCount())->toBe(1);
});

it('memoises the resolved driver across verify() calls', function () {
    config(['partna.bot_protection.driver' => 'null']);
    $manager = new CaptchaManager(app());
    $first = $manager->driver();
    $second = $manager->driver();
    expect($first)->toBe($second);
});

it('flush() drops the memo so a rebound container instance is picked up', function () {
    config(['partna.bot_protection.driver' => 'fake']);
    $original = new FakeProvider;
    app()->instance(FakeProvider::class, $original);

    $manager = new CaptchaManager(app());
    expect($manager->driver())->toBe($original);

    // Rebind to a different instance and assert that without flush() we still
    // get the memoised original, and that flush() picks up the new binding.
    $replacement = new FakeProvider;
    app()->instance(FakeProvider::class, $replacement);
    expect($manager->driver())->toBe($original);

    $manager->flush();
    expect($manager->driver())->toBe($replacement);
});
