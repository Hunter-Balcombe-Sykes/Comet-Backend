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
    $budget = new ApifyBudget;
    // global cap 5: 2 menu + 2 gb = 4, one more of either = 5 (ok), next = reject
    expect($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('menu'))->toBeTrue()
        ->and($budget->tryClaim('google-business'))->toBeTrue()
        ->and($budget->tryClaim('google-business'))->toBeTrue()   // global now 4
        ->and($budget->remaining('google-business'))->toBe(0);    // gb actor cap (2) reached
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
