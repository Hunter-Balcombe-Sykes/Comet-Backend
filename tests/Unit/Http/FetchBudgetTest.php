<?php

use App\Services\Http\FetchBudget;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W1 (post-review) — FetchBudget is the request/job-scoped collaborator
// extracted out of SafeUrlFetcher so a wall-clock budget can bound EVERY fetch
// during a platform connect, not just the ones routed through SafeUrlFetcher.
// See FetchBudget's own docblock for the concrete bug this fixes
// (YoutubeThumbnailResolver::pooledHead() uses raw Http::pool(), invisible to
// a budget attached to SafeUrlFetcher).

it('reports no budget by default', function () {
    $budget = app(FetchBudget::class);

    expect($budget->remaining())->toBeNull()
        ->and($budget->exhausted())->toBeFalse();
});

it('reports remaining time while a budget is open, and null again once it returns', function () {
    $budget = app(FetchBudget::class);

    $observed = $budget->open(1.0, fn () => $budget->remaining());

    expect($observed)->not->toBeNull()
        ->and($observed)->toBeGreaterThan(0.0)
        ->and($observed)->toBeLessThanOrEqual(1.0);

    // Cleared after open() returns — no lingering deadline for the next call.
    expect($budget->remaining())->toBeNull();
});

it('clears the deadline even when $work() throws', function () {
    $budget = app(FetchBudget::class);

    expect(fn () => $budget->open(1.0, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    // A finally-less implementation would leave the deadline set here.
    expect($budget->remaining())->toBeNull()
        ->and($budget->exhausted())->toBeFalse();
});

it('reports exhausted() once the deadline has passed', function () {
    $budget = app(FetchBudget::class);

    $result = $budget->open(0.0, function () use ($budget) {
        // 0-second budget: by the time this closure runs, hrtime() has moved
        // forward at least a little, so the deadline has already passed.
        return $budget->exhausted();
    });

    expect($result)->toBeTrue();
});

it('shares one instance across the container, so every consumer observes the same deadline', function () {
    // This is the property the scoped() binding in AppServiceProvider exists
    // for. SafeUrlFetcher and YoutubeThumbnailResolver each independently
    // constructor-inject FetchBudget — resolved from the container, never
    // passed by hand — so a budget opened by one collaborator (ConnectResolver)
    // is only visible to the others if they all resolve to the SAME instance.
    // Without the scoped binding, Laravel hands out a fresh instance per
    // resolution for an unbound concrete class: this is precisely the trap
    // that let a YouTube connect's thumbnail-probe round run unbounded even
    // after SafeUrlFetcher itself was correctly wired (2026-07-20 W1
    // independent review).
    $opener = app(FetchBudget::class);

    [$remainingA, $remainingB] = $opener->open(1.0, function () {
        // Two INDEPENDENT container resolutions, mirroring how SafeUrlFetcher
        // and YoutubeThumbnailResolver each pull their own copy rather than
        // sharing a reference directly.
        $consumerA = app(FetchBudget::class);
        $consumerB = app(FetchBudget::class);

        return [$consumerA->remaining(), $consumerB->remaining()];
    });

    // Against an unscoped binding, both consumers would resolve fresh
    // instances with no deadline set at all — remaining() would be null for
    // both, failing here.
    expect($remainingA)->not->toBeNull()
        ->and($remainingB)->not->toBeNull()
        // Both consumers observe (approximately) the same remaining time —
        // proof they share the SAME underlying deadline, not two independent
        // clocks that happen to both be open.
        ->and(abs($remainingA - $remainingB))->toBeLessThan(0.05);
});

it('ensureOpen() behaves like open() when no budget is active', function () {
    $budget = app(FetchBudget::class);

    $observed = $budget->ensureOpen(5.0, fn () => $budget->remaining());

    expect($observed)->not->toBeNull()
        ->and($observed)->toBeGreaterThan(0.0)
        ->and($observed)->toBeLessThanOrEqual(5.0);

    // Cleared after ensureOpen() returns — same as open().
    expect($budget->remaining())->toBeNull();
});

it('ensureOpen() runs inside an already-open budget without touching its deadline', function () {
    $budget = app(FetchBudget::class);
    $innerRemaining = null;

    $budget->open(2.0, function () use ($budget, &$innerRemaining) {
        // Nested ensureOpen with a LONGER budget: it must run inside the outer 2s,
        // not start a fresh 60s deadline.
        $budget->ensureOpen(60.0, function () use ($budget, &$innerRemaining) {
            $innerRemaining = $budget->remaining();
        });

        // After the inner call returns, the OUTER deadline must still be live.
        // A naive open()-delegating ensureOpen would have cleared it in its finally.
        expect($budget->remaining())->not->toBeNull()
            ->and($budget->remaining())->toBeGreaterThan(0.0);
    });

    // Inside the nested call, remaining reflected the OUTER ~2s, never 60s.
    expect($innerRemaining)->not->toBeNull()
        ->and($innerRemaining)->toBeLessThanOrEqual(2.0);
});

it('ensureOpen() ignores its own $seconds when nested inside a shorter outer budget', function () {
    $budget = app(FetchBudget::class);
    $innerRemaining = null;

    $budget->open(1.0, function () use ($budget, &$innerRemaining) {
        $budget->ensureOpen(60.0, function () use ($budget, &$innerRemaining) {
            $innerRemaining = $budget->remaining();
        });
    });

    expect($innerRemaining)->not->toBeNull()
        ->and($innerRemaining)->toBeLessThanOrEqual(1.0);
});

it('ensureOpen() propagates a nested exception and leaves the outer deadline intact', function () {
    $budget = app(FetchBudget::class);

    $budget->open(2.0, function () use ($budget) {
        expect(fn () => $budget->ensureOpen(60.0, function () {
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class);

        // The inner ensureOpen() set nothing to clear — outer deadline survives.
        expect($budget->remaining())->not->toBeNull()
            ->and($budget->remaining())->toBeGreaterThan(0.0);
    });
});
