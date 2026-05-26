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
