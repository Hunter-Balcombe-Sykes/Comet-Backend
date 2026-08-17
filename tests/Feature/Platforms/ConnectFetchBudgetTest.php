<?php

use App\Models\Core\User\User;
use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\ShopifyScraper;
use Illuminate\Support\Str;

// W1: every fetch-bearing region in the bespoke connect controllers (Apple,
// Shop, Fresha, Eventbrite/Humanitix via EventsPlatformController, Events) is
// capped by a FetchBudget::open(connect_budget_seconds, ...) wrap, mirroring
// ConnectResolver::resolve() — with ZERO happy-path change. (Skool was one of
// the seven until Phase 1.2 demoted it to link-only and deleted its
// controller; the bandcamp case below covers the ConnectResolver lane it used
// to stand in for.)
//
// Budget exhaustion is simulated with a SafeUrlFetcher double that reproduces
// exactly what SafeUrlFetcher::fetchFollowingRedirects() itself does once
// FetchBudget::remaining() <= 0: fetch() throws SafeUrlException, tryFetch()
// swallows that to null, fetchMany() drops every URL to null. Every scraper
// EXCEPT FreshaScraper reads via the non-throwing tryFetch/fetchMany, so
// exhaustion surfaces as the SAME graceful failure shape these controllers
// already produce for an ordinary transport failure — never a 500.
// FreshaScraper::fetchLocation() uses the throwing fetch(), so FreshaController
// explicitly translates SafeUrlException/ConnectionException to a 502 (the
// approved exception — see the W1 brief's Fresha section).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 8: addBrand()/setProducts() now write content.* (ShopContentWriter)
    // — the stand-in schema must exist for the shop tests below.
    setupContentTables();
});

afterEach(fn () => Mockery::close());

function fbUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/**
 * A SafeUrlFetcher double standing in for "the budget just ran out" — the
 * exact swallow/throw shape SafeUrlFetcher's own budget check produces
 * (fetchFollowingRedirects() throws SafeUrlException once remaining() <= 0;
 * tryFetch() catches that to null; fetchMany() drops every URL to null).
 */
function exhaustedFetcher(): SafeUrlFetcher
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->andThrow(new SafeUrlException('Fetch budget exhausted for test'));
    $fetcher->shouldReceive('tryFetch')->andReturnNull();
    $fetcher->shouldReceive('fetchMany')->andReturnUsing(fn (array $urls) => array_fill_keys($urls, null));

    return $fetcher;
}

/**
 * Proves a FetchBudget::open() call site is genuinely reached (guards against
 * the wrap being silently dropped in a refactor) — records that open() ran,
 * then delegates to the real implementation so the wrapped work behaves
 * exactly as it would in production.
 */
function budgetSpy(): FetchBudget
{
    return new class extends FetchBudget
    {
        public bool $opened = false;

        public function open(float $seconds, callable $work): mixed
        {
            $this->opened = true;

            return parent::open($seconds, $work);
        }
    };
}

// ── Apple ──────────────────────────────────────────────────────────────────

it('apple music connect surfaces the existing 404, not a 500, when the fetch budget is exhausted', function () {
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser(fbUser('fbapl1'))
        ->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Could not find that Apple Music artist or an album.');

    expect($spy->opened)->toBeTrue();
});

// ── Bandcamp (ConnectResolver, the registry-driven connect lane) ────────────
// Skool held this slot until Phase 1.2 demoted it to link-only — its connect no
// longer fetches anything, so there is no budget left to exhaust. Bandcamp is
// the surviving equivalent and a strictly better fixture: it exercises
// ConnectResolver::resolve()'s FetchBudget::open() wrap (the one the header
// comment above calls the model for the bespoke controllers), which nothing
// else in this file covered. BandcampScraper reads via tryFetch/fetchMany, so
// exhaustion lands on the same graceful 404 an ordinary transport failure gives.

it('bandcamp connect surfaces the existing 404, not a 500, when the fetch budget is exhausted', function () {
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser(fbUser('fbbc1'))
        ->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demo.bandcamp.com'])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Could not find releases on that Bandcamp page.');

    expect($spy->opened)->toBeTrue();
});

// ── Shop ───────────────────────────────────────────────────────────────────

it('shop addBrand surfaces the existing 422 store_unreachable code, not a 500, when the fetch budget is exhausted', function () {
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser(fbUser('fbsh1'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://teststore.example.com'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_unreachable');

    expect($spy->opened)->toBeTrue();
});

// The live-catalog read (setProducts / brandProducts / updateBrand's syncLatest)
// reaches ShopifyScraper::fetchProducts, whose transport-failure branch had to be
// made null-safe for this to be a 502 rather than a 500 — see the fetchProducts
// case in ScraperTransportFailureTest. Shopify is the DEFAULT provider, so this
// is the shop path an exhausted budget actually lands on most often.
it('shop setProducts surfaces a 502, not a 500, when the fetch budget is exhausted', function () {
    shimPgAdvisoryLockForSqlite();

    $user = fbUser('fbsh2');

    // Connect a brand first with a working scraper. The shopify branch of
    // brandProfileFor() returns no products, so no picker catalog is warmed and
    // the selection write below genuinely falls through to a live store read.
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: see ShopRelationalStorageTest's 'rel-brand' block — id mirrors fetchBrand()'s.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'fb-brand', 'name' => 'FB Store']);
        // W9 Unit 4: ShopBrandIdentity::for() now calls brandIdFrom($meta, $origin).
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'fb-brand', 'name' => 'FB Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://fbstore.example.com'])
        ->assertOk();

    // Drop the mock so the REAL ShopifyScraper runs over an exhausted fetcher.
    // Fresh spy bound here so it attests to setProducts' OWN budget, not the
    // one addBrand already opened above.
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->forgetInstance(ShopifyScraper::class);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser($user)
        ->putJson('/api/platforms/shop/brands/fb-brand/selection', ['productIds' => ['p1']])
        ->assertStatus(502);

    expect($spy->opened)->toBeTrue();
});

// ── Fresha ─────────────────────────────────────────────────────────────────
// FreshaScraper::fetchLocation() throws (SafeUrlFetcher::fetch(), not
// tryFetch()) — connect() explicitly catches SafeUrlException|ConnectionException
// and aborts 502, matching the scraper's own bad-status 502.

it('fresha connect surfaces a 502, not a 500, when the fetch budget is exhausted', function () {
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();

    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser(fbUser('fbfr1'))
        ->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(502)
        // 502-not-500 is what this test pins. abort()'s sentence is swallowed by
        // #P2-30's generic 5xx body in the deployed env — the friendly copy that
        // does reach users is the poll payload's connectFetchError().
        ->assertJsonPath('message', 'An error occurred');

    expect($spy->opened)->toBeTrue();
});

// ── Eventbrite (EventsPlatformController::addAccount) ─────────────────────

it('eventbrite connect surfaces the existing 422, not a 500, when the fetch budget is exhausted', function () {
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser(fbUser('fbeb1'))
        ->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Could not load that Eventbrite page.');

    expect($spy->opened)->toBeTrue();
});

// Humanitix, unlike Eventbrite, has a FETCHING normaliser: resolveHostUrl()
// follows a bare event-page URL to find its host link, which is precisely why
// the brief required normalizeAccountUrl() to sit inside the budget. That path
// read $res['status'] with no null guard, so an exhausted budget (tryFetch →
// null) produced a 500 rather than this 422 — the Eventbrite case above could
// never have caught it, since its normaliser is pure regex.
it('humanitix connect surfaces a 422, not a 500, when the budget is exhausted inside the fetching normaliser', function () {
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    actingAsUser(fbUser('fbhx1'))
        ->postJson('/api/platforms/humanitix/connect', ['url' => 'https://events.humanitix.com/some-event'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Humanitix host URL (events.humanitix.com/host/...).');

    expect($spy->opened)->toBeTrue();
});

// ── Events (smart-detect facade over Eventbrite/Humanitix) ────────────────

it('events add surfaces the existing 422, not a 500, when the fetch budget is exhausted', function () {
    $this->app->instance(SafeUrlFetcher::class, exhaustedFetcher());

    // An organiser URL the detector routes to Eventbrite (not an event URL,
    // not a recognisable custom link) — reaches EventbriteScraper::fetchEvents.
    $spy = budgetSpy();
    $this->app->instance(FetchBudget::class, $spy);

    actingAsUser(fbUser('fbev1'))
        ->postJson('/api/platforms/events/add', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Couldn't load that Eventbrite page.");

    expect($spy->opened)->toBeTrue();
});

// ── Happy path (byte-identical) ─────────────────────────────────────────────
// The budget now wraps every happy path too, so proving the response is
// unchanged is the other half of the contract. Apple connect, Eventbrite
// connect, and Shopify addBrand are already exact-pinned in
// PlatformResourceContractTest.php; Humanitix connect in
// IntegrationsV2ConnectionTest.php; events/add in EventsCatalogTest.php.
// Fresha connect (team mode — the branch a 'partna' account takes, since
// can_book_storewide is business-only) has no existing happy-path pin, so it
// is added here per the brief's escape hatch.

it('fresha connect returns the unchanged team-mode menu shape on the happy path', function () {
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->andReturn([
            'storeName' => 'Ollies',
            'team' => [
                ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
            ],
            'services' => [
                ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false],
            ],
        ]);
    });

    actingAsUser(fbUser('fbfr2'))
        ->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'team',
            'storeName' => 'Ollies',
            'team' => [
                ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
            ],
            'services' => [
                ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false],
            ],
        ]);
});
