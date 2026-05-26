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
