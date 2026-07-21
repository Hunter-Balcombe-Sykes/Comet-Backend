<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ConnectResolver;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W1 — ConnectResolver is the seam GenericPlatformController::connect()
// delegates to; its only job (for now) is wrapping the strategy call in
// FetchBudget::open() (see app/Services/Http/FetchBudget.php — the budget was
// moved off SafeUrlFetcher by the 2026-07-20 independent review, since a
// budget attached to SafeUrlFetcher alone is invisible to collaborators like
// YoutubeThumbnailResolver that deliberately bypass it). See ConnectResolver's
// docblock for the Phase 2 coupling note (outcome type widening).
//
// These tests still probe through SafeUrlFetcher (rather than FetchBudget
// directly) because that's the realistic path: a connect strategy fetches via
// SafeUrlFetcher, and it's SafeUrlFetcher's container-shared FetchBudget
// dependency — not ConnectResolver's own reference — that must observe the
// deadline. See tests/Unit/Platforms/ConnectResolverYoutubeTest.php for the
// end-to-end regression covering the specific collaborator (thumbnail probes)
// that a SafeUrlFetcher-only probe like this one cannot reach.

it('opens a wall-clock budget around the strategy call', function () {
    // remaining() needs a FetchBudget instance to call on, so this observes
    // the wrapper indirectly: set the budget to 0 (exhausted the instant
    // open() opens it) and have the fake strategy make its own SafeUrlFetcher
    // call from inside resolve(). If ConnectResolver correctly wraps the
    // strategy call in a budget, that nested fetch sees an already-exhausted
    // deadline and never sends a request. Against unfixed code (no
    // ConnectResolver, or one that calls resolve() unwrapped), no deadline is
    // ever set, the fetch proceeds normally, and a request IS sent — the
    // assertion below fails.
    //
    // This also exercises the scoped() container binding FetchBudget needs:
    // the strategy resolves SafeUrlFetcher (which itself resolves FetchBudget)
    // fresh from the container, and that FetchBudget must be the SAME
    // instance ConnectResolver opened the budget on, or the deadline set on
    // one would have zero effect on the other.
    config()->set('partna.http_fetch.connect_budget_seconds', 0);
    Http::fake(['https://1.1.1.1/probe' => Http::response('ok', 200)]);

    $probeStrategy = new class implements ConnectStrategy
    {
        public function resolve(string $input): ConnectResult
        {
            $sent = app(SafeUrlFetcher::class)->tryFetch('https://1.1.1.1/probe') !== null;

            return ConnectResult::ok(['sent' => $sent]);
        }
    };

    $descriptor = PlatformDescriptor::make('probe')->label('Probe')
        ->connect($probeStrategy, 'Enter a valid link.');

    // Unit 11 W6 — resolve() now returns a ConnectOutcome wrapping the
    // ConnectResult (see ConnectResolver's docblock); these probe descriptors
    // never call ->deferredConnect(), so ->deferred is always false here and
    // ->result behaves exactly as the old bare ConnectResult return did.
    $outcome = app(ConnectResolver::class)->resolve($descriptor, 'anything');
    expect($outcome->deferred)->toBeFalse();
    $result = $outcome->result;

    expect($result->failed())->toBeFalse()
        ->and($result->selection['sent'])->toBeFalse();
    Http::assertNothingSent();
});

it('lets the strategy call through normally when the budget is not exhausted', function () {
    // Sanity counterpart to the test above, proving the exhausted-budget
    // result isn't just "ConnectResolver always blocks everything": a
    // generous budget lets the same probe strategy's fetch succeed.
    config()->set('partna.http_fetch.connect_budget_seconds', 20);
    Http::fake(['https://1.1.1.1/probe' => Http::response('ok', 200)]);

    $probeStrategy = new class implements ConnectStrategy
    {
        public function resolve(string $input): ConnectResult
        {
            $sent = app(SafeUrlFetcher::class)->tryFetch('https://1.1.1.1/probe') !== null;

            return ConnectResult::ok(['sent' => $sent]);
        }
    };

    $descriptor = PlatformDescriptor::make('probe')->label('Probe')
        ->connect($probeStrategy, 'Enter a valid link.');

    // Unit 11 W6 — resolve() now returns a ConnectOutcome wrapping the
    // ConnectResult (see ConnectResolver's docblock); these probe descriptors
    // never call ->deferredConnect(), so ->deferred is always false here and
    // ->result behaves exactly as the old bare ConnectResult return did.
    $outcome = app(ConnectResolver::class)->resolve($descriptor, 'anything');
    expect($outcome->deferred)->toBeFalse();
    $result = $outcome->result;

    expect($result->selection['sent'])->toBeTrue();
    Http::assertSentCount(1);
});

it('returns the strategy failure verbatim when it fails inside the budget', function () {
    config()->set('partna.http_fetch.connect_budget_seconds', 20);

    $failingStrategy = new class implements ConnectStrategy
    {
        public function resolve(string $input): ConnectResult
        {
            return ConnectResult::fail('Enter a valid link.', 422);
        }
    };

    $descriptor = PlatformDescriptor::make('probe')->label('Probe')
        ->connect($failingStrategy, 'Enter a valid link.');

    $outcome = app(ConnectResolver::class)->resolve($descriptor, 'garbage');
    expect($outcome->deferred)->toBeFalse();
    $result = $outcome->result;

    expect($result->failed())->toBeTrue()
        ->and($result->error)->toBe('Enter a valid link.')
        ->and($result->status)->toBe(422);
});
