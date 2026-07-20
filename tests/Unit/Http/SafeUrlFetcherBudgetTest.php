<?php

use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W1 — SafeUrlFetcher consulting the shared FetchBudget + its
// ->connectTimeout(). Every custom-link/platform-scraper fetch routes through
// here (see docs/superpowers/plans/2026-07-20-platform-connect-async.md
// §1a): the per-hop timeout alone allows a redirect chain x the 403
// honest-UA retry to hold the request thread for minutes. Literal public IPs
// (1.1.1.1, 8.8.8.8, 9.9.9.9) keep these hermetic — no DNS mocking needed,
// matching SafeUrlFetcherTest.php.
//
// The budget itself lives in FetchBudget (app/Services/Http/FetchBudget.php),
// NOT on SafeUrlFetcher — see that class's docblock for why (independent
// review 2026-07-20 found a budget attached to SafeUrlFetcher alone is
// invisible to YoutubeThumbnailResolver, which deliberately bypasses
// SafeUrlFetcher). These tests open the budget via app(FetchBudget::class),
// then exercise SafeUrlFetcher through it — proving the two collaborate via
// the container's scoped binding, not a private field on SafeUrlFetcher.

it('cuts a redirect chain short once the wall-clock budget is exhausted', function () {
    // Unfixed code has no budget at all: fetchFollowingRedirects() walks
    // every 3xx hop up to max_redirects (5 here → hop 0..5) before throwing
    // "Too many redirects" — six requests sent. The fix must throw
    // SafeUrlException as soon as a hop's delay burns the budget, well
    // before hop 6, so the request count stays low.
    config()->set('partna.http_fetch.max_redirects', 5);

    Http::fake(function () {
        usleep(100_000); // 100ms — deliberately slower than the 50ms budget below

        return Http::response('', 302, ['Location' => 'https://1.1.1.1/next']);
    });

    $budget = app(FetchBudget::class);
    $fetcher = app(SafeUrlFetcher::class);

    expect(fn () => $budget->open(0.05, fn () => $fetcher->fetch('https://1.1.1.1/start')))
        ->toThrow(SafeUrlException::class);

    // Proves we stopped after the first hop's sleep burned the budget,
    // rather than walking the full chain.
    Http::assertSentCount(1);
});

it('leaves fetch() outside an open budget completely unchanged (no deadline set)', function () {
    // Deliberately vacuous against unfixed code — FetchBudget doesn't exist
    // yet, so this passes either way. Its job is to prove the budget is
    // strictly opt-in: the ~dozen other SafeUrlFetcher call sites in this app
    // (menu/shop/link-card scrapers) never open a budget, so they must see
    // zero behaviour change.
    Http::fake(['https://1.1.1.1/plain' => Http::response('ok', 200)]);

    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/plain');

    expect($out['status'])->toBe(200)->and($out['body'])->toBe('ok');
    Http::assertSentCount(1);
});

it('reads connect_timeout_seconds from config (connectTimeout cannot be observed via Http::fake)', function () {
    // Illuminate\Http\Client\Request — what Http::fake() records — wraps only
    // the PSR-7 request (method/uri/headers/body). Guzzle-level client
    // options such as connect_timeout are passed separately to the handler
    // and are never attached to the recorded request, so there is no honest
    // way to assert `->connectTimeout(N)` reached the wire through the fake.
    // This pins the one thing that IS observable: the config value lands in
    // the constructor-set property that feeds ->connectTimeout() at both
    // call sites (fetchFollowingRedirects, pooledGet).
    config()->set('partna.http_fetch.connect_timeout_seconds', 4);

    $fetcher = app(SafeUrlFetcher::class);

    $property = new ReflectionProperty($fetcher, 'connectTimeoutSeconds');
    $property->setAccessible(true);

    expect($property->getValue($fetcher))->toBe(4);
});

it('clears the deadline after FetchBudget::open() returns, including when $work() throws', function () {
    // Against a finally-less implementation, the expired deadline from the
    // first (budget-exhausting) call would still be set when the second,
    // unrelated fetch() runs — so that second fetch would throw "Fetch
    // budget exhausted" immediately instead of completing normally. Both
    // fetches share one FetchBudget instance (the whole point of the scoped
    // container binding), so a leaked deadline is directly observable this
    // way without touching any private method.
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/a') || str_contains($request->url(), '/next')) {
            usleep(50_000); // burns the 10ms budget below

            return Http::response('', 302, ['Location' => 'https://1.1.1.1/next']);
        }

        return Http::response('ok', 200);
    });

    $budget = app(FetchBudget::class);
    $fetcher = app(SafeUrlFetcher::class);

    expect(fn () => $budget->open(0.01, fn () => $fetcher->fetch('https://1.1.1.1/a')))
        ->toThrow(SafeUrlException::class);

    // Unrelated call, outside any budget — must succeed, not inherit the
    // expired deadline from the call above.
    $out = $fetcher->fetch('https://1.1.1.1/b');

    expect($out['status'])->toBe(200)->and($out['body'])->toBe('ok');
});

// ── pooledGet()/fetchMany() — OPPOSITE contract to fetch(): drop, don't throw ──
//
// fetchMany()'s docblock is explicit: "URLs that fail validation or exceed
// MAX_REDIRECTS are silently dropped (null) — unlike fetch(), which throws."
// Budget exhaustion must respect that split, not blanket-apply fetch()'s
// throwing behaviour. The concrete failure mode this guards: BandcampScraper::
// enrichPrices() (called from BandcampConnect::resolve(), inside
// ConnectResolver's budget) calls fetchMany() with no try/catch around it —
// so a throwing pooledGet() would turn "ran out of time pricing the 4th of 6
// release tiles" into an uncaught 500, instead of Bandcamp's connect
// succeeding with an unpriced latest tile (the graceful-degradation behaviour
// fetchMany()'s contract promises every other caller).

it('drops the un-fetched URLs to null instead of throwing when the budget runs out mid-fetchMany()', function () {
    // Against the throwing implementation, pooledGet() raised SafeUrlException
    // on the second URL's round — fetchManyFollowingRedirects() has no
    // try/catch around its `$this->pooledGet(...)` call, so that exception
    // propagated straight out of fetchMany() uncaught, and the
    // ->not->toThrow() assertion below failed (it DID throw).
    Log::spy();

    // Force one URL per pool round (default concurrency is 6, which would
    // fire all 3 test URLs in a single round and give the budget nothing to
    // expire "mid-batch" against).
    config()->set('partna.refresh.host_limits.fetch_many.pool_concurrency', 1);

    Http::fake(function ($request) {
        if (str_contains($request->url(), '1.1.1.1')) {
            usleep(60_000); // burns the 50ms budget below, before round 2 fires

            return Http::response('bodyA', 200);
        }

        // B and C must never actually be requested — proven below via
        // assertSentCount(1), not just by their result being null.
        return Http::response('should not be reached', 200);
    });

    $budget = app(FetchBudget::class);
    $fetcher = app(SafeUrlFetcher::class);
    $urls = ['https://1.1.1.1/a', 'https://8.8.8.8/b', 'https://9.9.9.9/c'];

    $out = null;
    expect(function () use ($budget, $fetcher, $urls, &$out) {
        $out = $budget->open(0.05, fn () => $fetcher->fetchMany($urls));
    })->not->toThrow(SafeUrlException::class);

    // Fetched-before-exhaustion URL retains its real data...
    expect($out['https://1.1.1.1/a']['body'])->toBe('bodyA')
        // ...un-fetched URLs come back null, exactly like any other dropped
        // fetchMany() URL (a 404, a byte-cap trip, a failed SSRF re-check).
        ->and($out['https://8.8.8.8/b'])->toBeNull()
        ->and($out['https://9.9.9.9/c'])->toBeNull();

    Http::assertSentCount(1);

    // One log line for the whole exhausted pooledGet() call, not one per
    // dropped URL — and it must say how many were dropped and how the budget
    // read at the time, so "dropped for budget" is distinguishable from
    // "dropped for a 404" after the fact.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'http_fetch.pooled_get.budget_exhausted'
            && $ctx['dropped'] === 2
            && $ctx['remaining_seconds'] <= 0);
});
