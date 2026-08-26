<?php

use App\Services\Analytics\EventTimeRelevance;
use Illuminate\Support\Carbon;

// The event relevance curve (smart-scoring plan step 5 + critic find
// 2026-08-27): peaks at the occurrence date, holds FULL weight through a
// 6-hour in-progress grace window (f_occurrence stores no duration), then
// quarter-weights and decays. w=3.0, half-life 7d — the shipped config
// defaults — used literally so the numbers below are the production curve.

const ETR_W = 3.0;
const ETR_HL = 7.0;

function etrAt(string $startsAt, string $now): ?float
{
    return EventTimeRelevance::relevance(ETR_W, ETR_HL, Carbon::parse($startsAt), Carbon::parse($now));
}

it('peaks at the event date', function () {
    expect(etrAt('2026-09-01T19:00:00Z', '2026-09-01T19:00:00Z'))->toEqualWithDelta(3.0, 0.001);
});

it('an event one half-life out carries half the peak', function () {
    expect(etrAt('2026-09-08T19:00:00Z', '2026-09-01T19:00:00Z'))->toEqualWithDelta(1.5, 0.001);
});

it('does NOT cliff at the doors: 1 minute and 5 hours in, the event holds full weight', function () {
    expect(etrAt('2026-09-01T19:00:00Z', '2026-09-01T19:01:00Z'))->toEqualWithDelta(3.0, 0.001)
        ->and(etrAt('2026-09-01T19:00:00Z', '2026-09-02T00:00:00Z'))->toEqualWithDelta(3.0, 0.001);
});

it('quarter-weights once the grace window closes, aged from its end', function () {
    // 6h1m past start = just past grace → ~w·0.25 (decay over 1 minute ≈ none).
    expect(etrAt('2026-09-01T19:00:00Z', '2026-09-02T01:01:00Z'))->toEqualWithDelta(0.75, 0.01);
});

it('a week after the grace window the past weight has halved again', function () {
    expect(etrAt('2026-09-01T19:00:00Z', '2026-09-09T01:00:00Z'))->toEqualWithDelta(0.375, 0.01);
});

it('fades to null (not a micro-boost) far in the past and far in the future', function () {
    expect(etrAt('2026-09-01T19:00:00Z', '2026-11-01T19:00:00Z'))->toBeNull()
        ->and(etrAt('2026-11-01T19:00:00Z', '2026-09-01T19:00:00Z'))->toBeNull();
});
