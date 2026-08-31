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

// #FU-4: ApifyBudget had no way to hand back a slot claimed by a SUCCESSFUL
// tryClaim() that then didn't spend (a lock timeout, a rolled-back write).
// These pin release()'s two-counter decrement.

it('release hands back BOTH counters, not just the actor half', function () {
    // Global cap set to 3 so it — not the actor cap — is the one release must
    // move; if release only decremented the actor counter this would still
    // read 2 after a claim+release, hiding the missing global decrement.
    config()->set('partna.limits.apify.global_daily_cap', 3);
    $budget = new ApifyBudget;

    $budget->tryClaim('google-business'); // global 1/3, actor 1/2
    $budget->release('google-business');  // must give back BOTH

    // If the global half leaked, one more claim would push global to 3/3 and
    // the NEXT would be denied at 4>3; instead we should be able to claim
    // three full times fresh (global back at 0).
    expect($budget->tryClaim('google-business'))->toBeTrue();
    expect($budget->tryClaim('menu'))->toBeTrue();
    expect($budget->tryClaim('menu'))->toBeTrue();
    // That's global 1(re-claim)+1+1 = 3, at cap — one more must be denied.
    expect($budget->tryClaim('menu'))->toBeFalse();
});

it('release is exactly one unit — a second outstanding claim still counts', function () {
    $budget = new ApifyBudget;

    $budget->tryClaim('menu'); // 1/2
    $budget->tryClaim('menu'); // 2/2 — actor cap now reached
    $budget->release('menu'); // hand back one — actor should read 1/2 again

    expect($budget->remaining('menu'))->toBe(1);
});
