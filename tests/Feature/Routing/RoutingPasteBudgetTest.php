<?php

use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Queue;

// SCALE-10 (2026-08-25): the paste path (RoutingController::store) reused
// connect_budget_seconds — a 45s CONNECT budget — on an interactive paste.
// Fixed with its own partna.http_fetch.paste_budget_seconds key (10s
// default), mirroring preview_budget_seconds' FI-3 precedent. These tests
// prove (a) both call sites in store() are wired to the NEW key, and (b)
// budget exhaustion on either call site degrades to a clean response, not a
// 500 or a hang.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

afterEach(fn () => Mockery::close());

/**
 * A SafeUrlFetcher double standing in for "the budget just ran out" — same
 * swallow/throw shape SafeUrlFetcher's own budget check produces (fetch()
 * throws SafeUrlException once FetchBudget::remaining() <= 0; tryFetch()
 * catches that to null). Copied from ConnectFetchBudgetTest.php's fixture.
 */
function pasteExhaustedFetcher(): SafeUrlFetcher
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->andThrow(new SafeUrlException('Fetch budget exhausted for test'));
    $fetcher->shouldReceive('tryFetch')->andReturnNull();
    $fetcher->shouldReceive('fetchMany')->andReturnUsing(fn (array $urls) => array_fill_keys($urls, null));

    return $fetcher;
}

/**
 * Records every $seconds value RoutingController hands to FetchBudget::open()
 * (store() opens it once for the item arm and once more for the route()
 * fallthrough), then delegates to the real implementation so the wrapped work
 * behaves exactly as it would in production.
 */
function pasteBudgetSpy(): FetchBudget
{
    return new class extends FetchBudget
    {
        /** @var list<float> */
        public array $seconds = [];

        public function open(float $seconds, callable $work): mixed
        {
            $this->seconds[] = $seconds;

            return parent::open($seconds, $work);
        }
    };
}

// ── wiring: both call sites read the new key ────────────────────────────────

it('opens the paste budget from paste_budget_seconds, not connect_budget_seconds, on both store() call sites', function () {
    Queue::fake();
    config(['partna.http_fetch.paste_budget_seconds' => 3.0]);
    config(['partna.http_fetch.connect_budget_seconds' => 45.0]);

    $spy = pasteBudgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    // Deterministic fallthrough: the item read is exhausted, so $written stays
    // null and store() is guaranteed to reach BOTH budget->open() call sites.
    $this->app->instance(SafeUrlFetcher::class, pasteExhaustedFetcher());

    $pro = createTenant('routing-paste-budget-wiring');

    actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ])->assertStatus(202);

    expect($spy->seconds)->toHaveCount(2);
    expect($spy->seconds[0])->toBe(3.0);
    expect($spy->seconds[1])->toBe(3.0);
});

// ── degrade: item arm ────────────────────────────────────────────────────────

it('falls through to a clean card write, not a 500, when the budget is exhausted on the item read', function () {
    Queue::fake();

    $spy = pasteBudgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, pasteExhaustedFetcher());

    $pro = createTenant('routing-paste-budget-item');

    // youtube.com/watch is classified content-item by pure regex (no fetch),
    // so this reaches MediaSeeder::seedItem — whose page read is exhausted —
    // BEFORE the exhausted fetcher can matter to anything else.
    $response = actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);

    $response->assertStatus(202);
    expect($response->json('outcome'))->not->toBe('item');
    expect($spy->seconds)->toHaveCount(2);
});

// ── degrade: route() fallthrough ─────────────────────────────────────────────

it('falls through to a clean reject, not a 500, when the budget is exhausted expanding a short link', function () {
    Queue::fake();

    $spy = pasteBudgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, pasteExhaustedFetcher());

    $pro = createTenant('routing-paste-budget-route');

    // bit.ly isn't a content-item/event host, so this skips the item arm
    // entirely and reaches route()'s budget directly — its only fetch is the
    // short-link expander, which never throws (ShortLinkExpander's own
    // docblock): an exhausted budget just yields the unexpanded URL, which
    // the canonicaliser rejects as 'shortener' — a real, non-500 answer.
    $response = actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://bit.ly/some-code',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('verdict', 'reject')
        ->assertJsonPath('blockReason', 'shortener');

    expect($spy->seconds)->toHaveCount(1);
});
