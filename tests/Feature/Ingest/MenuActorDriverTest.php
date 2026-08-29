<?php

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\MenuActorDriver;
use App\Services\Cache\ApifyBudget;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.menu.platforms', [
        'square' => ['actor' => 'menus-r-us~restaurant-menu-scraper'],
        'uber-eats' => ['actor' => 'memo23~uber-eats-scraper'],
        'doordash' => ['actor' => 'dz_omar~doordash-scraper'],
    ]);
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.menu', 100);
    config()->set('partna.menu.actor_attempts', 4);
    config()->set('partna.menu.actor_attempt_timeout_seconds', 60);
});

function menuCtx(array $input = []): BilledEffectContext
{
    return new BilledEffectContext(
        'actor',
        'menu',
        $input + [
            'actor' => 'memo23~uber-eats-scraper',
            'input' => ['startUrls' => [['url' => 'https://www.ubereats.com/au/store/x']]],
        ],
        'run-1',
        'source-1',
        'user-1',
    );
}

/**
 * One dataset row shaped like the real actor result, enough for the driver to
 * pass it through. The price is deliberately not a whole number: the driver
 * returns $response->json(), so a whole-dollar float round-trips to an INT —
 * which is why every connector guards prices with is_numeric(), not is_float().
 */
function menuDataset(): array
{
    return [['title' => 'Universal Restaurant', 'menuItems' => [['name' => 'Pide', 'section' => 'Mains', 'price' => 18.5]]]];
}

it('claims only its own (kind, name), leaving the other actors unclaimed', function () {
    $driver = app(MenuActorDriver::class);

    expect($driver->supports('actor', 'menu'))->toBeTrue()
        ->and($driver->supports('actor', 'music'))->toBeFalse()
        ->and($driver->supports('actor', 'instagram'))->toBeFalse()
        ->and($driver->supports('api', 'menu'))->toBeFalse();
});

// ── Refusals, all BEFORE the first budget claim ──────────────────────────────

it('never spends when the token is missing', function () {
    config()->set('services.apify.token', null);
    Http::fake();

    expect(fn () => app(MenuActorDriver::class)->run(menuCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('never spends on an actor that is not a registered menu platform', function () {
    // The connector resolves its own actor from config and passes '' when the
    // entry is missing. Without this check that empty id would be POSTed to
    // Apify, and a 404 would settle the store as "no menu" for the week.
    Http::fake();

    expect(fn () => app(MenuActorDriver::class)->run(menuCtx(['actor' => 'someone~unapproved-scraper'])))
        ->toThrow(EffectNotAttempted::class);
    expect(fn () => app(MenuActorDriver::class)->run(menuCtx(['actor' => ''])))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('never spends when the effect carries no actor input', function () {
    Http::fake();

    expect(fn () => app(MenuActorDriver::class)->run(menuCtx(['input' => []])))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('refuses without spending when the daily cap is reached', function () {
    // The cap defaults to 0 for an unregistered tag, so this also pins that the
    // key claimed is EXACTLY 'menu' — the one that already exists in
    // partna.limits.apify.actors. Claim under any other spelling and every run
    // is denied silently, which reads as a dead actor, not a config error.
    config()->set('partna.limits.apify.actors.menu', 0);
    Http::fake();

    expect(fn () => app(MenuActorDriver::class)->run(menuCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

// ── Budget is per REAL actor run, not per driver call ────────────────────────

it('spends exactly one budget slot for a run that answers first time', function () {
    Http::fake(['api.apify.com/*' => Http::response(menuDataset(), 201)]);

    $before = app(ApifyBudget::class)->remaining('menu');
    app(MenuActorDriver::class)->run(menuCtx());

    expect(app(ApifyBudget::class)->remaining('menu'))->toBe($before - 1);
});

it('spends one budget slot per retry, because each attempt is a separately billed run', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $before = app(ApifyBudget::class)->remaining('menu');
    app(MenuActorDriver::class)->run(menuCtx());

    expect(app(ApifyBudget::class)->remaining('menu'))->toBe($before - 4);
    Http::assertSentCount(4);
});

it('stops retrying and reports no answer when the cap runs out mid-retry', function () {
    // Two slots for four attempts.
    config()->set('partna.limits.apify.actors.menu', 2);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = app(MenuActorDriver::class)->run(menuCtx());

    // NOT EffectNotAttempted: two billed runs already left the process, and
    // rule 1 of BilledEffectDriver forbids releasing the claim after that —
    // doing so would let those runs be billed a second time.
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertSentCount(2);
});

// ── Retries ──────────────────────────────────────────────────────────────────

it('retries an empty dataset, because these actors return empty for a valid open store', function () {
    $responses = Http::sequence()
        ->push([], 201)
        ->push([], 201)
        ->push(menuDataset(), 201);
    Http::fake(['api.apify.com/*' => $responses]);

    $result = app(MenuActorDriver::class)->run(menuCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(1);
    Http::assertSentCount(3);
});

it('reports no answer — never an empty answer — when every attempt comes back empty', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = app(MenuActorDriver::class)->run(menuCtx());

    // A WAF-blocked scrape is indistinguishable from a genuinely empty store.
    // Answered([]) would serve "this restaurant has no menu" for the whole
    // freshness window off a bot block.
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

// ── A row that arrived but carries no menu is the same miss as no row ────────

/**
 * The REAL Uber Eats bot-wall row, from the first live Phase 5 run
 * (2026-08-16). Every expected key is present and menuItems is empty, because
 * loadedUrl was def.uber.com's challenge page rather than the store.
 */
function uberBotWallRow(): array
{
    return [[
        'menuItems' => [],
        'hasMenu' => false,
        'statusCode' => 200,
        'title' => null,
        'storeUuid' => null,
        'bodyLength' => 40226,
        'message' => 'warning: menu not found.',
        'loadedUrl' => 'https://def.uber.com/en/challenge?from_service=d2ViLWVhdHMtdjI%3D',
    ]];
}

it('retries a fully-keyed row whose declared payload is empty, not just an empty dataset', function () {
    $responses = Http::sequence()
        ->push(uberBotWallRow(), 201)
        ->push(menuDataset(), 201);
    Http::fake(['api.apify.com/*' => $responses]);

    $result = app(MenuActorDriver::class)->run(menuCtx(['expect' => ['menuItems']]));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(2);
});

it('reports no answer when every attempt hits the bot wall, so it is never cached as "no menu"', function () {
    Http::fake(['api.apify.com/*' => Http::response(uberBotWallRow(), 201)]);

    $result = app(MenuActorDriver::class)->run(menuCtx(['expect' => ['menuItems']]));

    // Answered([]) here would settle "this restaurant has no menu" for the whole
    // freshness window off a bot challenge — the exact failure BilledEffectOutcome
    // exists to prevent. This is what the first live Phase 5 run did before the
    // predicate moved inside the row.
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertSentCount(4);
});

it('accepts any one of several declared paths, for a vendor that moves its menu', function () {
    // Square's categories() reads menu.categories OR a top-level categories.
    Http::fake(['api.apify.com/*' => Http::response([[
        'restaurantName' => 'Anseo',
        'categories' => [['name' => 'Coffee', 'items' => [['name' => 'Flat White', 'price' => '4.50']]]],
    ]], 201)]);

    $result = app(MenuActorDriver::class)->run(menuCtx([
        'actor' => 'menus-r-us~restaurant-menu-scraper',
        'input' => ['mode' => 'url', 'url' => 'https://anseo.square.site'],
        'expect' => ['menu.categories', 'categories'],
    ]));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(1);
});

it('treats an effect that declares no expectation exactly as before', function () {
    // Backwards compatible: without `expect`, any non-empty dataset answers.
    Http::fake(['api.apify.com/*' => Http::response(uberBotWallRow(), 201)]);

    $result = app(MenuActorDriver::class)->run(menuCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(1);
});

it('retries a 5xx and answers when Apify recovers', function () {
    $responses = Http::sequence()
        ->push('upstream exploded', 502)
        ->push(menuDataset(), 201);
    Http::fake(['api.apify.com/*' => $responses]);

    expect(app(MenuActorDriver::class)->run(menuCtx())->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(2);
});

it('accepts 201, which is what run-sync-get-dataset-items actually returns', function () {
    // ->ok() accepts 200 alone and would reject every successful run.
    Http::fake(['api.apify.com/*' => Http::response(menuDataset(), 201)]);

    expect(app(MenuActorDriver::class)->run(menuCtx())->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertSentCount(1);
});

// ── 4xx is hard, but not all 4xx mean the same thing ─────────────────────────

it('settles a store-level 4xx as an empty answer, so a dead store is not re-billed every run', function () {
    Http::fake(['api.apify.com/*' => Http::response(['error' => 'bad url'], 400)]);

    $result = app(MenuActorDriver::class)->run(menuCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toBe([]);
    Http::assertSentCount(1);
});

it('reports no answer on an account-level 4xx, so a config break is never cached as truth', function (int $status) {
    // 401/402/403/404 say our token, rental or actor id is wrong — nothing at
    // all about the store. Settling them Answered([]) would cache a
    // configuration break as "no menu" for every store at once.
    Exceptions::fake();
    Http::fake(['api.apify.com/*' => Http::response(['error' => 'nope'], $status)]);

    $result = app(MenuActorDriver::class)->run(menuCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    // Hard: not retried.
    Http::assertSentCount(1);
    // B3 (#W2-OBS-5): throttled report, representative status pinned below.
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'apify' && $e->status === $status);
})->with([401, 402, 403, 404]);

// ── Transport ────────────────────────────────────────────────────────────────

it('sends the connector-built input to the connector-named actor, with the token as a header', function () {
    Http::fake(['api.apify.com/*' => Http::response(menuDataset(), 201)]);

    app(MenuActorDriver::class)->run(menuCtx([
        'actor' => 'dz_omar~doordash-scraper',
        'input' => ['startUrls' => [['url' => 'https://www.doordash.com/store/x']], 'address' => 'Melbourne VIC, Australia'],
    ]));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'dz_omar~doordash-scraper/run-sync-get-dataset-items')
            && $request['address'] === 'Melbourne VIC, Australia'
            // The token must NOT be in the body: Apify would treat it as actor
            // input and the run would be unauthenticated, which a pay-per-event
            // actor rejects as an x402 PAYMENT error rather than a 401 (F30).
            && ! isset($request['token'])
            && $request->hasHeader('Authorization');
    });
});

it('passes the dataset through untouched, because the connectors own the mapping', function () {
    // Deliberately NOT normalised here. Each menu connector already maps the
    // vendor's shape (ported from the legacy *MenuDriver classes), which is why
    // this driver has no adapters where MusicActorDriver has three.
    Http::fake(['api.apify.com/*' => Http::response(menuDataset(), 201)]);

    expect(app(MenuActorDriver::class)->run(menuCtx())->data)->toBe(menuDataset());
});
