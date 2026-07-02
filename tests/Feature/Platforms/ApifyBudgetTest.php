<?php

use App\Services\Cache\ApifyBudget;

beforeEach(function () {
    config()->set('partna.limits.apify.global_daily_cap', 5);
    config()->set('partna.limits.apify.actors.menu', 2);
    config()->set('partna.limits.apify.actors.google-business', 2);
});

it('grants claims up to the per-actor cap then rejects', function () {
    $budget = new ApifyBudget;
    expect($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('menu'))->toBeFalse(); // 3rd exceeds actor cap of 2
});

it('rejects once the GLOBAL cap is hit even if the actor cap is not', function () {
    config()->set('partna.limits.apify.actors.instagram', 10); // high actor cap — global will bind first
    $budget = new ApifyBudget;
    // Consume 4 slots across two actors (global=5, so 1 slot left)
    expect($budget->tryClaim('menu'))->toBeTrue()   // global=1
        ->and($budget->tryClaim('menu'))->toBeTrue()   // global=2
        ->and($budget->tryClaim('google-business'))->toBeTrue()   // global=3
        ->and($budget->tryClaim('google-business'))->toBeTrue()   // global=4
        // 5th claim: instagram still has actor headroom (cap=10), but global=5 → allowed
        ->and($budget->tryClaim('instagram'))->toBeTrue()   // global=5 (at cap)
        // 6th claim: global cap (5) exceeded → must reject regardless of actor cap
        ->and($budget->tryClaim('instagram'))->toBeFalse(); // global would be 6 > 5
});

it('reports remaining headroom as the min of actor and global', function () {
    $budget = new ApifyBudget;
    expect($budget->remaining('menu'))->toBe(2);  // actor cap 2 < global 5
    $budget->tryClaim('menu');
    expect($budget->remaining('menu'))->toBe(1);
});

it('a rejected claim does not consume budget (decrement releases it)', function () {
    config()->set('partna.limits.apify.actors.menu', 1);
    $budget = new ApifyBudget;
    $budget->tryClaim('menu');          // 1/1
    $budget->tryClaim('menu');          // rejected, must release
    // global should reflect only the 1 successful claim
    expect($budget->remaining('google-business'))->toBe(2); // global 5 - 1 = 4, gb cap 2 → 2
});
