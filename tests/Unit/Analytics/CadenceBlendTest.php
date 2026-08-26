<?php

use App\Services\Analytics\ActionScorer;

// The cadence-aware previous-score weight (smart-scoring plan, 2026-08-27):
// prev_weight = 0.3^(Δt/1day), clamped [0.3, 0.99] — compounds to the daily
// 0.7/0.3 semantics at any cadence.

it('compounds to the daily semantics: 15-minute weight ^96 = one daily step', function () {
    $now = now();
    $w15 = ActionScorer::cadenceBlendPrev($now->copy()->subMinutes(15)->toISOString(), $now);

    expect($w15)->toEqualWithDelta(0.3 ** (15 / 1440), 0.0001)
        ->and($w15 ** 96)->toEqualWithDelta(0.3, 0.001);
});

it('a daily gap gives exactly the classic 0.3, longer gaps clamp there', function () {
    $now = now();

    expect(ActionScorer::cadenceBlendPrev($now->copy()->subDay()->toISOString(), $now))
        ->toEqualWithDelta(0.3, 0.0001)
        ->and(ActionScorer::cadenceBlendPrev($now->copy()->subDays(5)->toISOString(), $now))
        ->toBe(0.3);
});

it('back-to-back runs clamp at 0.99 and a first write takes the daily weight', function () {
    $now = now();

    expect(ActionScorer::cadenceBlendPrev($now->toISOString(), $now))->toBe(0.99)
        ->and(ActionScorer::cadenceBlendPrev(null, $now))->toBe(0.3)
        ->and(ActionScorer::cadenceBlendPrev('', $now))->toBe(0.3);
});
