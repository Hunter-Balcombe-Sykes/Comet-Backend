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
    // SiteActionsService reads the `custom_links` pool for the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();

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

// ─────────────────────────────────────────────────────────────────────────────
// Fallback set — `leads` keeps limiting from Postgres, never opens
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Stand-in for the table the degraded counter reads.
 *
 * File-local, matching the six existing test files that each declare their own.
 * Do NOT promote this to tests/Pest.php: NoLocalCanonicalTableDdlTest would
 * then flag all six of those as local-canonical violations, because Pest.php's
 * copy runs first in setup order and IF NOT EXISTS makes theirs a silent no-op.
 */
function setupDeadStoreLeadSubmissions(): void
{
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        occurred_at TEXT NULL,
        subdomain TEXT NULL,
        site_id TEXT NULL,
        user_id TEXT NULL,
        customer_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        outcome TEXT NULL,
        form_started_at_ms INTEGER NULL
    )');
    DB::connection('pgsql')->table('analytics.lead_submissions')->delete();
}

it('admits an under-limit enquiry with the cache store dead', function () {
    tenantHelpersEnsureTables();
    setupDeadStoreLeadSubmissions();
    createTenant('deadstore-leads');

    killTheCacheStore();

    // `leads` is in FALLBACK_LIMITERS, not FAIL_OPEN_LIMITERS. The gate stays
    // shut against abuse but answers from Postgres instead of Redis, so a real
    // visitor's enquiry is no longer thrown away. This replaces the pre-2026-08-06
    // assertion that this endpoint 503s — see drill 03 finding 3.
    //
    // NOTE (Finding 2, 2026-08-06 final review): this payload has no `subject`
    // and a 4-char `message` (PublicEnquiryRequest requires min:10), so
    // FormRequest validation 422s BEFORE the request ever reaches
    // PublicEnquiryController::submit() — the 422 below is Laravel validation,
    // not the controller's "site not accepting enquiries" check, and no site is
    // even seeded. This test therefore proves ONLY that the throttle middleware
    // let the request through the store-dead limiter; it does NOT exercise the
    // controller body, the customer upsert, or the enquiry save. That gap is
    // exactly what let a fifth, unguarded Redis gate (CustomerObserver ->
    // UserCacheService::invalidateCustomerCount()) ship undetected for a whole
    // review cycle — see the controller-body reproduction in
    // "commits the enquiry when the customer-count cache invalidation dies"
    // below, which posts a valid body against a seeded contact site instead.
    $response = $this->withHeader('Origin', 'https://deadstore-leads.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'site_id' => (string) Str::uuid(),
            'name' => 'Real Visitor',
            'email' => 'visitor@example.test',
            'message' => 'hello',
        ]);

    // NOT 503 and NOT 500. The limiter let it through; the 422 that follows is
    // validation, not the limiter's business.
    expect($response->status())->not->toBe(503)
        ->and($response->status())->not->toBe(500);
});

it('rejects an over-limit enquiry with 429 rather than opening', function () {
    tenantHelpersEnsureTables();
    setupDeadStoreLeadSubmissions();
    createTenant('deadstore-leads-over');

    config(['partna.throttle.leads_degraded_per_minute_ip' => 2]);

    // Two prior submissions from this IP inside the window.
    foreach (range(1, 2) as $ignored) {
        DB::connection('pgsql')->table('analytics.lead_submissions')->insert([
            'id' => (string) Str::uuid(),
            'occurred_at' => now()->toDateTimeString(),
            'subdomain' => 'deadstore-leads-over',
            'ip_hash' => hash_hmac('sha256', '127.0.0.1', config('app.key')),
            'outcome' => 'created',
        ]);
    }

    killTheCacheStore();

    $this->withHeader('Origin', 'https://deadstore-leads-over.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'site_id' => (string) Str::uuid(),
            'name' => 'Spam Bot',
            'email' => 'bot@example.test',
            'message' => 'hello',
        ])
        ->assertStatus(429);
});

it('falls back to 503 when Postgres is dead too', function () {
    tenantHelpersEnsureTables();
    createTenant('deadstore-leads-nodb');

    killTheCacheStore();

    // No analytics.lead_submissions table => the counter throws. With both
    // stores gone there is no lead worth saving and no way to meter, so a
    // retryable 503 is the honest answer — never an open gate.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS analytics.lead_submissions');

    $response = $this->withHeader('Origin', 'https://deadstore-leads-nodb.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'site_id' => (string) Str::uuid(),
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'message' => 'hello',
        ]);

    $response->assertStatus(503);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('reports fallback mode in the throttle breadcrumb', function () {
    tenantHelpersEnsureTables();
    setupDeadStoreLeadSubmissions();
    createTenant('deadstore-leads-crumb');

    killTheCacheStore();

    Log::spy();

    $this->withHeader('Origin', 'https://deadstore-leads-crumb.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'site_id' => (string) Str::uuid(),
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'message' => 'hello',
        ]);

    // A degraded limiter that says nothing is worse than a loud 500 — the next
    // outage would be silent. `mode` must distinguish fallback from open.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'throttle.store_unavailable'
            && ($context['limiter'] ?? null) === 'leads'
            && ($context['mode'] ?? null) === 'fallback')
        ->atLeast()->once();
});

/**
 * Published site with an ACTIVE contact block. Mirrors seedDispatchContactSite()
 * in EnquiryDispatchResilienceTest.php under a DIFFERENT name — Pest file-local
 * functions are not visible across test files. Without an active contact block
 * PublicEnquiryController::submit() 422s before reaching the customer upsert,
 * and the Finding-1 reproduction below would pass vacuously.
 */
function seedDeadCacheContactSite(string $subdomain): void
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $subdomain,
        'handle_lc' => strtolower($subdomain),
        'display_name' => 'Dead Cache Pro',
        'first_name' => 'Dead Cache Pro',
        'primary_email' => 'deadcache@example.test',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => $subdomain,
        'is_published' => 1,
    ]);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode(['notification_email' => 'pro@example.test']),
    ]);
}

/**
 * Finding 1 (2026-08-06 final review) — a fifth, unguarded Redis gate.
 * PublicEnquiryController::submit() upserts the submitter as a Customer
 * BEFORE saving the enquiry. That write fires CustomerObserver::created()/
 * updated() -> UserCacheService::invalidateCustomerCount() ->
 * Cache::deleteMultiple(), which was completely unguarded: with the cache
 * store dead this threw, $enquiry->save() was never reached, and the visitor
 * got a raw 500 with no lead saved at all — worse than the pre-existing
 * throttle-only 503 this branch set out to fix.
 */
it('commits the enquiry when the customer-count cache invalidation dies', function () {
    tenantHelpersEnsureTables();
    setupEnquiriesTable();
    setupBlocksTable();
    setupCustomersTable();
    setupDeadStoreLeadSubmissions();

    seedDeadCacheContactSite('deadstore-customer-invalidate');

    killTheCacheStore();

    $response = $this->withHeader('Origin', 'https://deadstore-customer-invalidate.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'name' => 'Real Visitor',
            'email' => 'visitor@example.test',
            'subject' => config('partna.contact_subject_defaults')[0],
            'message' => 'Please call me back about a booking.',
            'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
        ]);

    $response->assertOk();

    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(1);
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
