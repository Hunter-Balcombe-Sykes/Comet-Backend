<?php

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Throttle\FailOpenThrottleRequests;
use App\Providers\AppServiceProvider;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Str;

/**
 * D1 — the app must survive its cache store dying.
 *
 * The 2026-07-31 Redis-down drill found HTTP 500 on every public sitepage read
 * and every analytics beacon during a Valkey outage: ThrottleRequests threw a
 * raw RedisException before the request ever reached a DB-backed read that
 * would have served fine, and CacheLockService threw five lines later even if
 * the limiter had let it through.
 *
 * Nothing in the suite exercised a dead store behind middleware — which is
 * exactly why it shipped unnoticed. The suite runs SQLite + an ARRAY cache, so
 * a store failure has to be simulated; that simulation is this file's whole
 * reason to exist, and D1.7's real-Redis drill re-run is what keeps it honest.
 */

/**
 * Every operation throws, the way phpredis does when the socket is gone.
 * Implements LockProvider so Cache::lock() reaches a throwing lock() rather
 * than short-circuiting on Repository's "store does not support locks" guard —
 * a different failure shape than the one production sees.
 */
final class ThrowingStore implements LockProvider, Store
{
    public function get($key)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function many(array $keys)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function put($key, $value, $seconds)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function putMany(array $values, $seconds)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function increment($key, $value = 1)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function decrement($key, $value = 1)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function forever($key, $value)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function forget($key)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function flush()
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function getPrefix()
    {
        return '';
    }

    public function add($key, $value, $seconds)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function restoreLock($name, $owner)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }
}

/**
 * Point the whole application at a store that throws.
 *
 * The forgetInstance() is load-bearing, not tidiness. Illuminate\Cache\RateLimiter
 * is a container SINGLETON resolved at boot against the array store
 * (CacheServiceProvider); without forgetting it, the limiter would keep using
 * the perfectly healthy array cache and every assertion here would be a FALSE
 * PASS. Re-running configureRateLimiting() is required for the same reason —
 * the named limiters were registered on the instance we just discarded, and
 * they captured $throttleEnabled from the boot-time config.
 */
function killTheCacheStore(bool $throttleEnabled = true): void
{
    config(['partna.throttle.enabled' => $throttleEnabled]);

    // Both halves are required: CacheManager::resolve() reads
    // cache.stores.{name} and throws "not defined" BEFORE it ever consults the
    // custom creators, so extend() alone is not enough.
    Cache::extend('throwing', fn () => Cache::repository(new ThrowingStore));
    config([
        'cache.stores.throwing' => ['driver' => 'throwing'],
        'cache.default' => 'throwing',
    ]);

    app()->forgetInstance(CacheRateLimiter::class);
    // forgetInstance() alone is not enough: the RateLimiter FACADE memoises its
    // resolved object in Facade::$resolvedInstance, so configureRateLimiting()
    // below would register every named limiter back onto the discarded instance
    // while the middleware resolved the fresh (empty) one — "Rate limiter
    // [public-site] is not defined" on every request.
    RateLimiterFacade::clearResolvedInstance(CacheRateLimiter::class);

    (new ReflectionMethod(AppServiceProvider::class, 'configureRateLimiting'))
        ->invoke(new AppServiceProvider(app()));
}

/** Tables the public profile payload builder reaches into. */
function setupDeadStoreProfileSchema(): void
{
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    setupContentSelectionTable();

    // Shared helper, not a local CREATE TABLE: NoLocalCanonicalTableDdlTest and
    // DuplicateStandInDdlGuardTest both reject a bespoke copy, because
    // tests/Pest.php's version runs first in setup order and IF NOT EXISTS makes
    // the local one a silent no-op — the test would believe it ran under a
    // schema it never got.
    setupDesignKitsTable();

    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN architecture_id TEXT NOT NULL DEFAULT 'staple'");
    } catch (Throwable) {
        // Column already added by an earlier test in this process.
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fail-open set — public reads stay up
// ─────────────────────────────────────────────────────────────────────────────

it('serves the public profile with the cache store dead', function () {
    setupDeadStoreProfileSchema();
    createTenant('deadstore-profile');

    killTheCacheStore();

    // Covers BOTH halves of D1: the throttle:public-profile limiter has to fail
    // open, and CacheLockService::rememberLocked has to degrade to an uncached
    // compute. Either one still throwing means a 500 here.
    $this->getJson('/api/public/profiles/deadstore-profile')->assertOk();
});

it('accepts an analytics beacon with the cache store dead', function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();
    app()->bind(AnalyticsIngestor::class, SyncIngestor::class);

    $tenant = createTenant('deadstore-beacon');

    killTheCacheStore();

    $this->withHeaders([
        'X-Site-Subdomain' => 'deadstore-beacon',
        'Origin' => 'https://deadstore-beacon.'.config('partna.public_domain'),
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ])->assertSuccessful();
});

it('does not soften SEC-1 origin binding when the cache store is dead', function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();
    app()->bind(AnalyticsIngestor::class, SyncIngestor::class);

    $victim = createTenant('deadstore-victim');
    createTenant('deadstore-attacker');

    killTheCacheStore();

    // Fail-OPEN must not become fail-THROUGH. The Origin cross-check is a
    // tenancy control, not a rate limit; an outage is not permission to write
    // events into someone else's site.
    $this->withHeader('Origin', 'https://deadstore-attacker.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/pageviews', [
            'site_id' => $victim->site->id,
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
        ])->assertNotFound();

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fail-closed set — 503, never 500, never unmetered
// ─────────────────────────────────────────────────────────────────────────────

it('fails a public write form closed as 503 with the cache store dead', function () {
    tenantHelpersEnsureTables();
    createTenant('deadstore-leads');

    killTheCacheStore();

    // `leads` is deliberately absent from FAIL_OPEN_LIMITERS: unmetered during
    // an outage means spam straight into site.customers / enquiry mail. What
    // this asserts is the SHAPE of the refusal — a 503 the client can retry,
    // not the leaked RedisException 500 the drill measured.
    $response = $this->withHeader('Origin', 'https://deadstore-leads.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'site_id' => (string) Str::uuid(),
            'name' => 'Spam Bot',
            'email' => 'bot@example.test',
            'message' => 'hello',
        ]);

    $response->assertStatus(503);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('resolves multi-limiter routes strictest-wins', function () {
    killTheCacheStore();

    // /public/documents/{id}/download carries ['throttle:public-site',
    // 'throttle:document-download']. public-site opens, document-download
    // closes — net 503. That is intended; pinned here so nobody "fixes" it.
    $this->getJson('/api/public/documents/'.Str::uuid().'/download')->assertStatus(503);
});

// ─────────────────────────────────────────────────────────────────────────────
// Not silent, and not re-wired
// ─────────────────────────────────────────────────────────────────────────────

it('emits a breadcrumb rather than degrading silently', function () {
    setupDeadStoreProfileSchema();
    createTenant('deadstore-breadcrumb');

    killTheCacheStore();

    Log::spy();

    $this->getJson('/api/public/profiles/deadstore-breadcrumb')->assertOk();

    // The drill measured ZERO breadcrumbs during a full outage. A fail-open
    // change that kept that property would make the next outage silent, which
    // is strictly worse than loud 500s.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'cache.store_unavailable')
        ->atLeast()->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'throttle.store_unavailable')
        ->atLeast()->once();
});

it('keeps VerifySupabaseJwt pinned ahead of ThrottleRequests', function () {
    // The binding must really be in place first — otherwise everything below
    // is asserting on stock middleware and proves nothing about this change.
    expect(app(ThrottleRequests::class))->toBeInstanceOf(FailOpenThrottleRequests::class);

    // Middleware aliases only reach the Router once the HTTP kernel has been
    // resolved, which happens on the first request. Gathering before that
    // returns the raw alias strings and every assertion below silently passes
    // on an empty match.
    $this->getJson('/api/health');

    /** @var Router $router */
    $router = app(Router::class);

    // The pins in bootstrap/app.php match the LITERAL string
    // ThrottleRequests::class. Registering the subclass as a new alias instead
    // of binding over that name would leave them matching a class no longer in
    // the stack — silently un-pinning VerifySupabaseJwt and reintroducing the
    // `supabase_uid`-not-yet-set RuntimeException in the per-uid limiters.
    $route = collect($router->getRoutes()->getRoutes())
        ->first(fn ($candidate) => $candidate->uri() === 'api/bootstrap');

    expect($route)->not->toBeNull();

    $gathered = array_values($router->gatherRouteMiddleware($route));
    $indexOf = fn (string $class) => collect($gathered)
        ->search(fn ($m) => is_string($m) && str_starts_with($m, $class));

    $jwtAt = $indexOf(VerifySupabaseJwt::class);
    $throttleAt = $indexOf(ThrottleRequests::class);
    $bindingsAt = $indexOf(SubstituteBindings::class);

    expect($jwtAt)->not->toBeFalse()
        ->and($throttleAt)->not->toBeFalse()
        ->and($jwtAt)->toBeLessThan($throttleAt);

    // Proves the list really was priority-SORTED rather than merely left in
    // declaration order: `api` (which contributes SubstituteBindings) is listed
    // first on the route, so an unsorted gather would put bindings at index 0.
    expect($throttleAt)->toBeLessThan($bindingsAt);
});

it('changes nothing when throttling is disabled', function () {
    // Needed so the request can reach route-model binding and 404 honestly —
    // without it a missing table masks the result as a 500 either way.
    tenantHelpersEnsureTables();
    setupMediaTables();

    killTheCacheStore(throttleEnabled: false);

    // Limit::none() short-circuits to Unlimited before any store call, so the
    // fail-closed document-download limiter never sees the dead store and the
    // request falls through to a plain not-found instead of a 503.
    $this->getJson('/api/public/documents/'.Str::uuid().'/download')->assertNotFound();
});
