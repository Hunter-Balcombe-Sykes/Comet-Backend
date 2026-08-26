<?php

use App\Http\Middleware\Auth\EnsurePartnaStaff;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentUser;
use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Customer;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\MenuProjectionMapper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\ShopProductProjection;
use App\Services\Shop\StoreRecord;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Support\HigherOrderTapProxy;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
// Note: Unit tests like MediaJobReliabilityTest opt into Tests\TestCase via
// their own `uses(TestCase::class)->in(__FILE__)` call. Don't add Unit here
// or you'll get "test case can not be used: already uses" clash.

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

require_once __DIR__.'/Helpers/EnquiryInboxTestHelpers.php';
require_once __DIR__.'/Helpers/PoolTestHelpers.php';
require_once __DIR__.'/Helpers/ProjectionTestHelpers.php';
require_once __DIR__.'/Feature/Platforms/GoldenMaster/golden_master_helpers.php';

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Auth Helpers
|--------------------------------------------------------------------------
|
| The app uses Supabase JWT — Auth::user() is always null. The real
| middleware chain is:
|   supabase.jwt  → verifies Bearer token, sets supabase_uid attribute
|   current.pro  → loads Professional by supabase_uid, sets professional attribute
|
| For HTTP-layer tests (using $this->getJson / postJson), actingAsUser()
| bypasses both middleware by injecting the professional directly via a fake
| middleware stub. This mirrors how tenantRequestAs() works for direct-controller
| tests, but at the HTTP layer.
|
*/

/**
 * Authenticate the test HTTP client as a given Professional.
 *
 * Stubs out VerifySupabaseJwt and LoadCurrentUser so the
 * real JWT verification never runs. The professional is injected into
 * request attributes before the controller sees the request — exactly
 * what the two middleware would have done in production.
 *
 * Usage: actingAsUser($pro)->getJson('/api/me')
 */
function actingAsUser(
    User $professional,
    array $claims = [],
): HigherOrderTapProxy {
    $supabaseUid = $professional->auth_user_id ?? (string) Str::uuid();

    // Default claims mirror a verified user. Tests that need to exercise the
    // unverified path should bind their own stub before calling actingAsProfessional
    // (or bypass it entirely and hit the real middleware).
    $defaultClaims = [
        'sub' => $supabaseUid,
        'email' => $professional->primary_email,
        'email_verified' => true,
        'aal' => 'aal1',
        'amr' => [],
        'session_id' => (string) Str::uuid(),
    ];
    $resolvedClaims = array_merge($defaultClaims, $claims);

    // Not a JWT claim — a knob for the `revocation.strict` gate. The real
    // VerifySupabaseJwt sets supabase_revocation_verified=true after isRevoked()
    // answers, so a stub standing in for a SUCCESSFUL verification must do the
    // same or every strict route would 503 in tests that have nothing to do with
    // revocation. Pass ['revocation_verified' => false] to simulate a Redis
    // outage at route level. Pulled out of the claim set so it never leaks into
    // the supabase_claims attribute.
    $revocationVerified = $resolvedClaims['revocation_verified'] ?? true;
    unset($resolvedClaims['revocation_verified']);

    // Replace both auth middleware with stubs that set the request attributes.
    // We can't use withoutMiddleware() because some route action callbacks
    // (registered in AppServiceProvider::boot) read supabase_uid and throw if
    // missing — those callbacks fire AFTER the middleware pipeline, so the
    // attributes have to be set by something in the pipeline. Container
    // rebinding is the cleanest way to swap the production middleware out
    // without changing route definitions.
    //
    // app()->resolving(Request::class, ...) doesn't help here: Laravel's HTTP
    // testing layer creates the Request via createFromBase, not via container
    // resolution, so resolving() callbacks never fire.
    app()->bind(VerifySupabaseJwt::class, function () use ($supabaseUid, $resolvedClaims, $revocationVerified) {
        return new class($supabaseUid, $resolvedClaims, $revocationVerified)
        {
            public function __construct(
                private readonly string $uid,
                private readonly array $claims,
                private readonly bool $revocationVerified,
            ) {}

            public function handle(Request $request, Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', $this->claims['aal'] ?? 'aal1');
                $request->attributes->set('supabase_amr', $this->claims['amr'] ?? []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id'] ?? null);
                $request->attributes->set('supabase_revocation_verified', $this->revocationVerified);

                return $next($request);
            }
        };
    });

    app()->bind(LoadCurrentUser::class, function () use ($professional) {
        return new class($professional)
        {
            public function __construct(private readonly User $pro) {}

            public function handle(Request $request, Closure $next)
            {
                $request->attributes->set('professional', $this->pro);

                return $next($request);
            }
        };
    });

    return test();
}

/**
 * Build an aal2 claim set with a TOTP verification timestamp in the
 * amr array. Use when a test needs "the user just verified MFA".
 *
 * @param  int  $verifiedSecondsAgo  How long ago the totp verify happened
 * @return array{aal: string, amr: array<int, array{method: string, timestamp: int}>}
 */
function aal2ClaimsWithFreshTotp(int $verifiedSecondsAgo = 0): array
{
    return [
        'aal' => 'aal2',
        'amr' => [
            ['method' => 'totp', 'timestamp' => time() - $verifiedSecondsAgo],
            ['method' => 'magiclink', 'timestamp' => time() - $verifiedSecondsAgo - 60],
        ],
    ];
}

/**
 * Authenticate the test HTTP client as a PartnaStaff member with AAL2 claims.
 * Stubs out VerifySupabaseJwt + EnsurePartnaStaff so route attributes are set
 * the same way the production middleware would set them.
 *
 * Mirrors actingAsUser() (tests/Pest.php). Pairs with aal2ClaimsWithFreshTotp()
 * when a staff route needs fresh-AAL2 instead of sticky-AAL2.
 */
function actingAsStaff(
    PartnaStaff $staff,
    array $claims = [],
): HigherOrderTapProxy {
    $authUserId = $staff->auth_user_id ?? (string) Str::uuid();

    // Staff routes are gated by require.aal2 — default to AAL2 unless the test
    // overrides (e.g. to assert the gate rejects AAL1).
    $defaultClaims = array_merge([
        'sub' => $authUserId,
        'email' => $staff->primary_email ?? "staff-{$staff->id}@partna.au",
        'email_verified' => true,
        'aal' => 'aal2',
        'amr' => [['method' => 'totp', 'timestamp' => time()]],
        'session_id' => (string) Str::uuid(),
    ], $claims);

    // See actingAsUser: every staff route carries `revocation.strict`, so a stub
    // standing in for a successful verification must report the revocation check
    // as answered. ['revocation_verified' => false] simulates a Redis outage.
    $revocationVerified = $defaultClaims['revocation_verified'] ?? true;
    unset($defaultClaims['revocation_verified']);

    // Stub VerifySupabaseJwt — same shape as actingAsUser uses.
    app()->bind(VerifySupabaseJwt::class, function () use ($authUserId, $defaultClaims, $revocationVerified) {
        return new class($authUserId, $defaultClaims, $revocationVerified)
        {
            public function __construct(
                private readonly string $uid,
                private readonly array $claims,
                private readonly bool $revocationVerified,
            ) {}

            public function handle(Request $request, Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', $this->claims['aal'] ?? 'aal1');
                $request->attributes->set('supabase_amr', $this->claims['amr'] ?? []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id'] ?? null);
                $request->attributes->set('supabase_revocation_verified', $this->revocationVerified);

                return $next($request);
            }
        };
    });

    // Stub EnsurePartnaStaff to inject the staff record on the request.
    app()->bind(EnsurePartnaStaff::class, function () use ($staff) {
        return new class($staff)
        {
            public function __construct(private readonly PartnaStaff $s) {}

            public function handle(Request $request, Closure $next)
            {
                $request->attributes->set('partna_staff', $this->s);

                return $next($request);
            }
        };
    });

    return test();
}

/*
|--------------------------------------------------------------------------
| Schema Bootstrap Helpers
|--------------------------------------------------------------------------
|
| BaseModel forces the 'pgsql' connection on every Eloquent model. In tests
| we redirect 'pgsql' to in-memory SQLite (see TestCase::setUp), but SQLite
| has no real schema support, so we ATTACH DATABASE for each schema name
| and CREATE TABLE under the right "schema". All schema setup must run on
| the 'pgsql' connection explicitly so the model-facing PDO handle sees it.
|
| Each helper is idempotent (CREATE TABLE IF NOT EXISTS, ATTACH wrapped in
| try/catch). Tests call only the helpers they need.
|
*/

/**
 * Attach all schema namespaces the project uses. Safe to call from any
 * test; idempotent within a single PDO connection.
 */
function attachTestSchemas(): void
{
    $conn = DB::connection('pgsql');
    if ($conn->getDriverName() !== 'sqlite') {
        return;
    }

    // SQLite caps ATTACHed databases at 10, and a failed ATTACH is swallowed
    // by the catch below — so this list must hold only schemas that exist.
    // brand/commerce/billing/retail were dropped from the platform (see the
    // repo CLAUDE.md schema list); attaching them cost four slots and bought
    // nothing, which is what pushed catalog/routing over the limit.
    foreach (['core', 'site', 'audit', 'moderation', 'notifications', 'analytics', 'catalog', 'routing'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (Throwable $e) {
            // already attached — ignore
        }
    }
}

/**
 * Swap the cache repository for one that throws on every operation, the way a
 * dead Redis does.
 *
 * Shared here rather than in a test file because more than one suite needs it and
 * cross-file global function definitions depend on Pest's load order — a
 * single-file run would fatal on the missing function.
 *
 * FIDELITY NOTE. A real dead Redis still hands back working manager/store/lock
 * OBJECTS and throws only when a command reaches the wire. A bare Repository
 * implementation raises `Error: call to undefined method` for store()/lock()/
 * driver()/tags() instead, which downstream `catch (Throwable)` arms absorb
 * identically — so a test could pass while exercising the wrong exception class,
 * and would not catch a layer that narrowed its catch to RedisException. __call()
 * routes those to the same throw so the failure mode is at least the right one.
 */
function throwingCacheRepository(): Repository
{
    return new class implements Repository
    {
        private function boom(): never
        {
            throw new RedisException('Connection refused');
        }

        public function get($key, $default = null): mixed
        {
            $this->boom();
        }

        public function put($key, $value, $ttl = null): bool
        {
            $this->boom();
        }

        public function add($key, $value, $ttl = null): bool
        {
            $this->boom();
        }

        public function increment($key, $value = 1)
        {
            $this->boom();
        }

        public function decrement($key, $value = 1)
        {
            $this->boom();
        }

        public function forever($key, $value): bool
        {
            $this->boom();
        }

        public function forget($key): bool
        {
            $this->boom();
        }

        public function has($key): bool
        {
            $this->boom();
        }

        public function many(array $keys): array
        {
            $this->boom();
        }

        public function putMany(array $values, $ttl = null): bool
        {
            $this->boom();
        }

        public function pull($key, $default = null): mixed
        {
            $this->boom();
        }

        public function remember($key, $ttl, Closure $callback): mixed
        {
            $this->boom();
        }

        public function sear($key, Closure $callback): mixed
        {
            $this->boom();
        }

        public function rememberForever($key, Closure $callback): mixed
        {
            $this->boom();
        }

        public function missing($key): bool
        {
            $this->boom();
        }

        public function clear(): bool
        {
            $this->boom();
        }

        public function delete($key): bool
        {
            $this->boom();
        }

        public function deleteMultiple($keys): bool
        {
            $this->boom();
        }

        public function getMultiple($keys, $default = null): iterable
        {
            $this->boom();
        }

        public function setMultiple($values, $ttl = null): bool
        {
            $this->boom();
        }

        public function set($key, $value, $ttl = null): bool
        {
            $this->boom();
        }

        public function getStore()
        {
            $this->boom();
        }

        public function __call($method, $parameters)
        {
            $this->boom();
        }
    };
}

/** Swap the Cache facade for the throwing repository above. */
function bindThrowingCacheStore(): void
{
    Cache::swap(throwingCacheRepository());
}

/**
 * core.users — mirrors the NOT NULL / CHECK constraints of the real dev
 * Postgres (T3 triage; guarded by SchemaDriftGuardTest). Columns prod leaves
 * nullable stay nullable here: auth_user_id and primary_email are NULL for
 * provisional pre-account users, which is why they are NOT tightened.
 * Defaults mirror prod too — a column that is NOT NULL DEFAULT x in Postgres
 * must carry that default here, or the test schema is stricter than prod and
 * manufactures failures prod would never see.
 */
function setupUsersTable(): void
{
    attachTestSchemas();
    // user_handle_aliases rides along: UserDashboardResource reads it on every
    // /me serialization (reclaimable_handles, 2026-08-06), so any lane that
    // seeds users can now serialize them without a bespoke setup call.
    setupHandleAliasesTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY NOT NULL,
        auth_user_id TEXT NULL,
        -- handle / handle_lc / display_name / first_name are NOT NULL in prod with NO
        -- DEFAULT, so every seed must supply them. Mirroring that here means a fixture
        -- can never again pass CI green while seeding a row prod would reject.
        handle TEXT NOT NULL,
        handle_lc TEXT NOT NULL,
        display_name TEXT NOT NULL,
        first_name TEXT NOT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        -- Mirrors users_account_type_check in production. SQLite enforces CHECK,
        -- so a test seeding a retired value (\'individual\', \'brand\', \'partner\')
        -- fails at the INSERT rather than passing silently — enum casts are lazy,
        -- so an invalid value otherwise only throws if something reads it back.
        -- Deliberately stricter than prod, which still tolerates \'staff\'.
        account_type TEXT NOT NULL DEFAULT \'partna\' CHECK (account_type IN (\'partna\',\'business\')),
        status TEXT NOT NULL DEFAULT \'active\' CHECK (status IN (\'active\',\'suspended\',\'disabled\',\'pending_deletion\',\'unclaimed\')),
        -- bio: RE-ADDED 20260819140000 (identity plan) — the About Me
        -- paragraph, with live consumers now. (It was dropped as dead by
        -- 20260705120002; this is a deliberate re-add, not drift.)
        -- icon_bucket/icon_path/headshot_bucket/headshot_path: dropped pre-baseline
        -- (see baseline:313-315 comment) and never existed in any migration here.
        bio TEXT NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        onboarding_step INTEGER NOT NULL DEFAULT 0,
        public_contact_number TEXT NULL,
        public_contact_email TEXT NULL,
        sector TEXT NULL,
        sector_source TEXT NULL,
        location_street_address TEXT NULL,
        location_postcode TEXT NULL,
        location_city TEXT NULL,
        location_state TEXT NULL,
        location_country TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Defensive ALTERs for suites that created core.users before the sector
    // columns existed (SQLite's CREATE TABLE IF NOT EXISTS won't add columns to
    // an already-created table within a run). Mirrors migrations 20260705150100
    // and 20260819140000 (bio).
    foreach (['sector', 'sector_source', 'bio'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.users ADD COLUMN {$col} TEXT NULL");
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }

    // GET /me (UserSelfController) now always resolves the optional
    // core.partna_staff link to expose is_staff, so the users-schema stub must
    // include that table too. Idempotent — no-op when already created.
    setupPartnaStaffTable();
}

/**
 * Permissive core.pre_account_builds table — columns nullable except the
 * load-bearing default and user_id, which is NOT NULL to mirror prod
 * (migration 20260718200000_pre_account_sites.sql). PARITY-1: every creation
 * path already sets user_id via ->user()->associate(), so the constraint holds.
 */
function setupPreAccountBuildsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.pre_account_builds (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        source_type TEXT NULL,
        source_ref TEXT NULL,
        source_ref_lc TEXT NULL,
        built_via TEXT NULL,
        built_by_staff_id TEXT NULL,
        build_state TEXT NULL DEFAULT \'pending\',
        failure_code TEXT NULL,
        created_ip_hash TEXT NULL,
        expires_at TEXT NULL,
        claimed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Defensive ALTER for suites that created core.pre_account_builds before
    // contact_email existed. Mirrors migration 20260721120000.
    foreach ([
        'contact_email TEXT NULL',
        'invited_at TEXT NULL',
        'auto_invite INTEGER NOT NULL DEFAULT 1',
        // Mirrors migration 20260811090000 (thin-scrape marker).
        'thin_scrape_at TEXT NULL',
        // Mirrors migration 20260825170000 (ManyChat claim links).
        'claim_token_hash TEXT NULL',
        'claim_token_issued_at TEXT NULL',
        'claim_idempotency_key TEXT NULL',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement('ALTER TABLE core.pre_account_builds ADD COLUMN '.$col);
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }

    // ClaimNotifier::notify() serializes concurrent sends with
    // pg_advisory_xact_lock(hashtext(...)); register the SQLite no-op shims so
    // pre-account tests exercise the real locked code path.
    shimPgAdvisoryLockForSqlite();
}

/**
 * Alias for setupSitesTable() — ensures site.platform_connections
 * (IntegrationConnection's table) exists. setupSitesTable() already creates it
 * as part of the full sites stub; this named wrapper lets a test spell out the
 * table it actually depends on (mirrors the setupSiteMediaTable() alias
 * elsewhere in this file, which wraps setupMediaTables() the same way).
 */
function setupIntegrationConnectionsTable(): void
{
    setupSitesTable();
}

/**
 * An unsaved User with just enough shape for actingAsUser() to derive JWT
 * claims (sub + email) for a Supabase auth id with no core.users row — the
 * pre-claim state ClaimSiteService expects. auth_user_id isn't fillable, so
 * it's set directly. Suite-global: shared by ClaimEndpointTest and
 * BootstrapRetirementTest.
 */
function claimJwtUser(string $uid, ?string $email): User
{
    $user = new User(['primary_email' => $email]);
    $user->auth_user_id = $uid;

    return $user;
}

/**
 * Build a claim-ready pre-account site: an unclaimed User + Site + a READY
 * PreAccountBuild linked via associate(). Returns [$user, $site, $build].
 * Suite-global: shared by ClaimSiteServiceTest and ClaimEndpointTest.
 */
function makeReadyBuild(string $subdomain = 'janedoe'): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => $subdomain, 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['build_state' => PreAccountBuild::STATE_READY]);
    $build->user()->associate($user);
    $build->save();

    return [$user, $site, $build];
}

/**
 * Slice 5a: a user with one connected shop store.
 *
 * Was makeShopBrand(), which wrote a `site.shop_brands` row. The shop re-home
 * dropped that table (20260819000210), so content.collections +
 * content.storefronts is the ONLY storage a store has — and this fixture
 * writes it through the production writer (ShopContentWriter::upsertStore())
 * plus the per-store anchor ShopConnections::anchor() mints, rather than
 * hand-rolled DB rows, so it can never drift from what production writes.
 *
 * $attrs merges into the StoreRecord via StoreRecord::with(), so its keys are
 * the record's camelCase property names (externalRef, sourceUrl, fetchMode,
 * connectStatus, productsCuratedAt, …) — NOT the legacy snake_case columns.
 *
 * The returned record is READ BACK off content.*, so it carries collectionId
 * (and userId), which every content.* assertion in the shop tests keys on.
 *
 * @return array{0: User, 1: StoreRecord}|array{0: User, 1: StoreRecord, 2: Site}
 */
function makeShopStore(array $attrs = [], bool $withSite = false): array
{
    setupUsersTable();
    setupSitesTable();
    setupContentTables();

    $handle = 'shop'.Str::lower(Str::random(8));
    $user = User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);

    $site = null;
    if ($withSite) {
        $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => $handle]);
    }

    $record = (new StoreRecord(
        externalRef: 'brand-'.Str::lower(Str::random(8)),
        provider: 'shopify',
        name: 'Test Store',
        position: 0,
        url: 'https://store.test',
        sourceUrl: 'https://store.test',
        currency: 'AUD',
        discountCode: '',
        referralQuery: '',
        isIndividual: false,
    ))->with($attrs);

    // One connection per store, keyed on the store id (convergence Phase 6,
    // owner ruling 4) — the anchor every connect poll and cache refresh
    // resolves through ShopConnections::anchorFor().
    app(ShopConnections::class)->anchor($user, $record->provider, $record->externalRef);

    app(ShopContentWriter::class)->upsertStore($record, (string) $user->id);

    $store = app(ShopConnections::class)->store($user, $record->externalRef);

    return $withSite ? [$user, $store, $site] : [$user, $store];
}

/**
 * A shop-capable user with NO store of any kind.
 *
 * For the tests that must observe content.* empty, or that build their store
 * through the endpoints under test rather than a fixture. makeShopStore()
 * cannot serve them: it writes a storefront row, which is the very absence
 * those tests turn on. Before the re-home they used makeShopBrand(), whose
 * legacy row had no content.* presence at all — that incidental property is
 * what this helper makes explicit.
 *
 * @return array{0: User}|array{0: User, 1: Site}
 */
function makeShopUser(bool $withSite = false): array
{
    setupUsersTable();
    setupSitesTable();
    setupContentTables();

    $handle = 'shop'.Str::lower(Str::random(8));
    $user = User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);

    return $withSite
        ? [$user, Site::factory()->create(['user_id' => $user->id, 'subdomain' => $handle])]
        : [$user];
}

/**
 * A SECOND (third, …) store for a user who already has one — the shape that
 * used to be a bare `ShopBrand::create(['connection_id' => $first->connection_id,
 * …])`, which is no longer possible: one connection per store (convergence
 * Phase 6, owner ruling 4) means each store mints its own anchor, and the
 * store itself lives in content.*.
 *
 * Same $attrs contract as makeShopStore(). Returns the record read back off
 * content.*, so it carries collectionId and userId.
 */
function addShopStore(User $user, array $attrs = []): StoreRecord
{
    $record = (new StoreRecord(
        externalRef: 'brand-'.Str::lower(Str::random(8)),
        provider: 'shopify',
        position: 0,
        url: 'https://store.test',
        sourceUrl: 'https://store.test',
        currency: 'AUD',
        discountCode: '',
        referralQuery: '',
        isIndividual: false,
    ))->with($attrs);

    app(ShopConnections::class)->anchor($user, $record->provider, $record->externalRef);
    app(ShopContentWriter::class)->upsertStore($record, (string) $user->id);

    return app(ShopConnections::class)->store($user, $record->externalRef);
}

/**
 * One product in $store's content.* catalogue.
 *
 * Was makeShopProduct(), which wrote a `site.shop_products` row (dropped by
 * 20260819000200). $data merges into the upstream-shaped raw blob
 * (url/price/title/productId/... — exactly what
 * ShopProductProjection::fromBlob() reads) EXCEPT 'position', which is not a
 * blob key: it is the content.collection_items position, the same split the
 * legacy row carried as a real column.
 *
 * Written through ProjectionWriter::writeManualItem() — the identical lane
 * ShopContentWriter::syncStore() uses — so the item, its facets and its offers
 * land exactly as a real sync leaves them. Returns the blob, which is what
 * callers assert against.
 *
 * @return array<string, mixed>
 */
function makeShopStoreProduct(StoreRecord $store, array $data = []): array
{
    setupIngestTables();
    setupContentTables();

    $collectionId = (string) $store->collectionId;

    $position = array_key_exists('position', $data)
        ? (int) $data['position']
        : (int) (DB::table('content.collection_items')
            ->where('collection_id', $collectionId)->max('position') ?? -1) + 1;
    unset($data['position']);

    // Every key here is one ShopProductProjection::fromBlob() reads WITHOUT a
    // ?? fallback (title/currency/productId/price/image) — a real scraped
    // blob always carries all of them (possibly null), so the fixture must
    // too or fromBlob() throws "Undefined array key" on a merge that omits one.
    $blob = array_merge([
        'productId' => (string) Str::uuid(),
        'title' => 'Test Product',
        'url' => 'https://s.test/'.Str::lower(Str::random(8)),
        'price' => '10.00',
        'currency' => null,
        'available' => true,
        'image' => null,
        'images' => [],
        'variants' => [],
    ], $data);

    // Mirrors syncStore()'s own coord choice: URL when there is one, else the
    // collection-namespaced product id — a urlless product (Squarespace,
    // BigCartel) is identified, not dropped.
    $url = trim((string) ($blob['url'] ?? ''));
    $coord = $url !== ''
        ? ShopProductProjection::coordFor($url)
        : ShopProductProjection::coordForProductId($collectionId, (string) $blob['productId']);

    $itemId = app(ProjectionWriter::class)->writeManualItem(
        (string) $store->userId,
        $coord,
        ShopProductProjection::fromBlob($blob, $store->currency),
    );

    DB::table('content.collection_items')->upsert([[
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => null, 'position' => $position,
    ]], ['collection_id', 'item_id'], ['position']);

    return $blob;
}

/**
 * A store already landed in content.*, plus $withProducts products — the
 * thin wrapper the endpoint tests use when they want the collection id and
 * the store id as bare strings rather than a record. Task 8's endpoint URLs
 * are keyed by the store's external_ref (the provider-scoped key, not the
 * content.collections uuid) — hence the third return value.
 *
 * @return array{0: User, 1: string, 2: string} [$user, $collectionId, $externalRef]
 */
function makeStoreCollection(int $withProducts = 0): array
{
    setupIngestTables();

    [$user, $store] = makeShopStore();

    for ($i = 0; $i < $withProducts; $i++) {
        makeShopStoreProduct($store, ['position' => $i]);
    }

    return [$user, (string) $store->collectionId, $store->externalRef];
}

/**
 * Task 8: content.* replacement for the pre-Task-8 `ShopProduct::where(
 * 'brand_id', $brand->id)->orderBy('position')->pluck('product_id')->all()`
 * assertion idiom — setProducts()/addProduct()/removeProduct()/syncLatest()
 * all reconcile into content.* now instead of site.shop_products, so tests
 * read productId back off content.f_catalog.sku through the brand's
 * storefront collection, in content.collection_items position order.
 * Shared here (not file-local) because more than one Shop test file needs
 * it — PHP has no per-file function scoping, so a second `function
 * orderedProductIdsFor()` declaration in another file would fatal with
 * "Cannot redeclare" the moment both load in the same process.
 *
 * @return list<string>
 */
function orderedProductIdsFor(string $brandId): array
{
    $collectionId = DB::table('content.storefronts')->where('external_ref', $brandId)->value('collection_id');

    return DB::table('content.collection_items as ci')
        ->join('content.f_catalog as f', 'f.item_id', '=', 'ci.item_id')
        ->where('ci.collection_id', $collectionId)
        ->orderBy('ci.position')
        ->pluck('f.sku')
        ->all();
}

/**
 * Task 8: content.* replacement for `$brand->products_curated_at` —
 * setProducts()/updateBrand() no longer write the legacy column (#SEM-1's
 * real source of truth is content.storefronts.products_curated_at now;
 * ShopContentWriter::isCurated() reads it first). Shared for the same
 * redeclaration reason as orderedProductIdsFor() above.
 */
function curatedAtFor(string $brandId): ?string
{
    return DB::table('content.storefronts')->where('external_ref', $brandId)->value('products_curated_at');
}

/**
 * TestCase::mock()/instance() are `protected` — callable from a test method,
 * not from a plain global helper function bound to no class. Container::
 * instance() is public, so replicate the same Mockery-registration TestCase::
 * mock() does, reachable via the app() helper instead.
 */
function mockShopService(string $abstract, Closure $callback): void
{
    app()->instance($abstract, Mockery::mock($abstract, $callback));
}

/**
 * Task 6: binds a mocked ShopifyScraper (makeShopStore()'s default provider)
 * so app(ShopCatalog::class)->syncLatest($store) exercises the REAL
 * container-resolved ShopCatalog + ShopContentWriter wiring — not a hand-built
 * ShopCatalog — without a network fetch. $products is the raw scraper shape
 * ShopifyScraper::fetchProducts() itself returns (url/title/price/...).
 *
 * @param  list<array<string,mixed>>  $products
 */
function fakeProviderCatalog(StoreRecord $store, array $products): void
{
    mockShopService(ShopifyScraper::class, function ($mock) use ($store, $products) {
        $mock->shouldReceive('fetchProducts')->with($store->url, $store->currency)->andReturn($products);
    });
}

/**
 * Calls each of shop's 9 write endpoints once with a valid payload — used
 * only by Task 8 step 5's inertness proof (that none of them touch
 * content.*). Order matters: $b (the caller's existing brand) is used for the
 * updates/catalog/selection calls; a SEPARATE brand is minted by addBrand()
 * and removed by removeBrand(), so removing it doesn't disturb the calls
 * still using $b. forget() runs last because it wipes everything. Assumes
 * the caller already has a current site (e.g. makeShopStore(withSite: true))
 * — updateSettings() resolves one. $t is accepted (not currently used
 * internally) so a future write endpoint that needs a TestCase-bound
 * assertion can be added here without changing the call sites in Tasks 5/6/8.
 */
function exerciseAllShopWrites(TestCase $t, User $u, StoreRecord $b): void
{
    $exerciseBrandId = 'exercise-'.Str::lower(Str::random(8));

    mockShopService(ShopifyScraper::class, function ($m) use ($exerciseBrandId) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('probeMeta')->andReturn(['id' => $exerciseBrandId, 'name' => 'Exercise Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => $exerciseBrandId, 'name' => 'Exercise Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
        $m->shouldReceive('fetchProducts')->andReturn([]);
    });
    actingAsUser($u)->postJson('/api/platforms/shop/brands', ['url' => 'https://exercise-brand.test'])
        ->assertSuccessful();

    actingAsUser($u)->patchJson("/api/platforms/shop/brands/{$b->externalRef}", ['discountCode' => 'EXERCISE'])
        ->assertSuccessful();

    mockShopService(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('productsFromClient')->andReturn([
            ['productId' => 'ex-catalog-1', 'title' => 'Catalog Product', 'url' => 'https://exercise.test/c1'],
        ]);
    });
    actingAsUser($u)->postJson("/api/platforms/shop/brands/{$b->externalRef}/catalog", [
        'products' => [['id' => 'ex-catalog-1', 'title' => 'Catalog Product']],
    ])->assertSuccessful();

    actingAsUser($u)->putJson("/api/platforms/shop/brands/{$b->externalRef}/selection", ['productIds' => []])
        ->assertSuccessful();

    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
            'product' => ['productId' => 'ex-p1', 'title' => 'Exercise Product', 'url' => 'https://exercise.test/p1', 'price' => '10.00'],
            'storeUrl' => null,
        ]);
    });
    actingAsUser($u)->postJson('/api/platforms/shop/products', ['url' => 'https://exercise.test/p1'])
        ->assertSuccessful();

    actingAsUser($u)->deleteJson('/api/platforms/shop/products/ex-p1')
        ->assertSuccessful();

    actingAsUser($u)->deleteJson("/api/platforms/shop/brands/{$exerciseBrandId}")
        ->assertSuccessful();

    actingAsUser($u)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'checkout'])
        ->assertSuccessful();

    actingAsUser($u)->deleteJson('/api/platforms/shop')
        ->assertSuccessful();
}

/**
 * core.feedback table for in-app feedback submission tests.
 * Mirrors columns from migration 20260526210001 + OV-D's
 * 20260711153000_feedback_type_area_target (type/area/target).
 */
function setupFeedbackTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement("CREATE TABLE IF NOT EXISTS core.feedback (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        reply_email TEXT NULL,
        kind TEXT NOT NULL,
        severity TEXT NULL,
        type TEXT NULL,
        area TEXT NULL,
        target TEXT NULL,
        message TEXT NOT NULL,
        page_url TEXT NULL,
        user_agent TEXT NULL,
        viewport TEXT NULL,
        app_version TEXT NULL,
        request_id TEXT NULL,
        status TEXT NOT NULL DEFAULT 'new',
        internal_notes TEXT NOT NULL DEFAULT '[]',
        tags TEXT NOT NULL DEFAULT '[]',
        source TEXT NOT NULL DEFAULT 'dashboard',
        ip_hash TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )");

    // Defensive ALTERs for suites that created core.feedback before the OV-D
    // columns existed within the same test run (SQLite's CREATE TABLE IF NOT
    // EXISTS won't add columns to an already-created table).
    foreach (['type', 'area', 'target'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.feedback ADD COLUMN {$col} TEXT NULL");
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
}

/**
 * site.sites table — minimal columns, all nullable.
 */
function setupSitesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        subdomain TEXT NOT NULL,
        architecture_id TEXT NOT NULL DEFAULT \'staple\' CHECK (architecture_id IN (\'staple\', \'scroll\')),
        subdomain_changed_at TEXT NULL,
        is_published INTEGER NOT NULL DEFAULT 0,
        unpublished_at TEXT NULL,
        settings TEXT NOT NULL DEFAULT \'{}\',
        moderation_state TEXT NOT NULL DEFAULT \'active\' CHECK (moderation_state IN (\'active\',\'warned\',\'hidden\')),
        custom_domain TEXT NULL,
        custom_domain_status TEXT NULL CHECK (custom_domain_status IS NULL OR custom_domain_status IN (\'pending\',\'active\',\'error\')),
        custom_domain_verified_at TEXT NULL,
        custom_domain_cf_id TEXT NULL,
        custom_domain_primary INTEGER NOT NULL DEFAULT 0,
        show_branding INTEGER NULL,
        charlie_enabled INTEGER NULL,
        services_auto_sync_enabled INTEGER NULL,
        booking_mode TEXT NULL CHECK (booking_mode IS NULL OR booking_mode IN (\'manual\',\'none\')),
        manual_booking_url TEXT NULL,
        shop_link_mode TEXT NOT NULL DEFAULT \'checkout\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    // Ensure moderation_state column exists for tests run against a pre-existing table.
    // Wrapped in try-catch because SQLite's ADD COLUMN IF NOT EXISTS syntax differs from Postgres.
    try {
        DB::connection('pgsql')->statement(
            "ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS moderation_state TEXT NOT NULL DEFAULT 'active'"
        );
    } catch (Throwable $e) {
        // Column already exists or SQLite doesn't support this syntax — ignore
    }

    // Custom-domain columns — defensive ALTER for any pre-existing test table
    // (mirrors the moderation_state pattern above).
    foreach (['custom_domain', 'custom_domain_status', 'custom_domain_verified_at', 'custom_domain_cf_id'] as $cdCol) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS {$cdCol} TEXT NULL");
        } catch (Throwable $e) {
            // already exists / unsupported — ignore
        }
    }
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS custom_domain_primary INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    // FOUND-16 promoted columns — defensive ALTER for any pre-existing test table.
    $promotedCols = [
        'show_branding' => 'INTEGER NULL',
        'charlie_enabled' => 'INTEGER NULL',
        'services_auto_sync_enabled' => 'INTEGER NULL',
        'booking_mode' => 'TEXT NULL',
        'manual_booking_url' => 'TEXT NULL',
        // Global shop link controls (2026-07-08) — site-level columns read by
        // the shop-settings endpoint + the public payload's linkMode stamp.
        'shop_link_mode' => "TEXT NULL DEFAULT 'checkout'",
    ];
    foreach ($promotedCols as $col => $type) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS {$col} {$type}");
        } catch (Throwable $e) {
            // already exists / unsupported — ignore
        }
    }

    // site.site_subdomain_aliases — SiteProvisioningService now rejects candidates held
    // by an ACTIVE alias before inserting, so every site-provisioning path reads this
    // table. Created here (idempotent) rather than left to each test's opt-in
    // setupSubdomainAliasesTable() call, which most signup tests never made.
    setupSubdomainAliasesTable();

    // site.platform_connections — per-user platform integration selections
    // (Shopify/Apple/Instagram/...). Read by the public platforms endpoint +
    // dashboard; any test that sets up a site may touch it. Mirrors the
    // production migration 20260602020000 (jsonb→TEXT, bool→INTEGER).
    // Mirrors migration 20260727110000: surface_key is the identity column and
    // `platform` is a GENERATED alias (14 special back-mappings + brand-prefix
    // default). SQLite lacks split_part → substr/instr does the prefix branch.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        surface_key TEXT NOT NULL,
        routing_class TEXT NOT NULL CHECK (routing_class IN (\'social\',\'content\',\'events\',\'shop\',\'booking\',\'reservations\',\'ordering\',\'link\',\'ignore\')),
        is_primary INTEGER NOT NULL DEFAULT 0,
        created_by_detector TEXT NULL,
        created_by_catalog_digest TEXT NULL,
        platform TEXT GENERATED ALWAYS AS (CASE surface_key
            WHEN \'apple_music.artist\' THEN \'apple-music\'
            WHEN \'apple_podcasts.show\' THEN \'apple-podcast\'
            WHEN \'bella_booking.book\' THEN \'bella-booking\'
            WHEN \'google_business.listing\' THEN \'google-business\'
            WHEN \'ko_fi.page\' THEN \'ko-fi\'
            WHEN \'resident_advisor.tickets\' THEN \'resident-advisor\'
            WHEN \'square.order\' THEN \'square-ordering\'
            WHEN \'youtube_music.channel\' THEN \'youtube-music\'
            WHEN \'partna.custom_link\' THEN \'custom\'
            WHEN \'partna.manual_event\' THEN \'events-custom\'
            WHEN \'partna.storefront\' THEN \'shop\'
            WHEN \'partna.booking_link\' THEN \'booking\'
            WHEN \'partna.reserve_link\' THEN \'reservations\'
            WHEN \'partna.order_link\' THEN \'online-ordering\'
            ELSE substr(surface_key, 1, instr(surface_key, \'.\') - 1)
        END) STORED,
        resource_id TEXT NOT NULL,
        payload TEXT NOT NULL DEFAULT \'{}\',
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        last_visited_at TEXT NULL,
        last_refreshed_at TEXT NULL,
        last_refresh_status TEXT NULL CHECK (last_refresh_status IN (\'ok\',\'unavailable\',\'error\',\'pending\',\'action_needed\')),
        last_refresh_error TEXT NULL,
        consecutive_failures INTEGER NOT NULL DEFAULT 0,
        apify_status TEXT NULL CHECK (apify_status IS NULL OR apify_status IN (\'pending\',\'ok\',\'unavailable\')),
        place_id TEXT NULL,
        refresh_etag TEXT NULL,
        refresh_last_modified TEXT NULL,
        display_settings TEXT NULL,
        canonical_key TEXT NULL,
        resource_kind TEXT NULL CHECK (resource_kind IS NULL OR resource_kind IN (\'event\',\'link\')),
        -- #PARITY-1: 20260729150016..150018 sets created_at/updated_at NOT
        -- NULL in Postgres. Mirrored here instead of left nullable — the
        -- earlier DECLINED note (this comment used to live here) rested on
        -- DueForRefreshScopeTest.php:88\'s explicit
        -- `->update([\'updated_at\' => null])`, but that write is now
        -- unreachable in prod too (the migration forbids it), so keeping the
        -- stand-in permissive was mirroring a state that no longer exists.
        -- See tests/Feature/Platforms/DueForRefreshScopeTest.php and
        -- tests/Postgres/PlatformConnectionsTimestampsNotNullTest.php for
        -- where that test\'s coverage moved. DEFAULT is required: several
        -- insert sites across the suite legitimately omit both columns,
        -- relying on the same DEFAULT now() Postgres declares.
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT NULL
    )');

    // Plan 5 conditional-request validators — defensive ALTER for any pre-existing
    // test table (SQLite's CREATE TABLE IF NOT EXISTS won't add columns to an
    // already-created table within a run).
    foreach (['refresh_etag', 'refresh_last_modified', 'canonical_key', 'resource_kind', 'display_settings'] as $vCol) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS {$vCol} TEXT NULL");
        } catch (Throwable $e) {
            // already exists / unsupported — ignore
        }
    }

    // FOUND-14 — DB-level dedupe guarantee: one active account row per
    // (user, platform, canonical_key). NULL canonical_key (single-selection
    // default / event-* / link-* rows) is excluded by the partial predicate.
    // SQLite's CREATE INDEX grammar puts the schema qualifier on the INDEX
    // name (not the table name) — an index must live in the same attached
    // database as its table.
    try {
        DB::connection('pgsql')->statement('CREATE UNIQUE INDEX IF NOT EXISTS site.idx_platform_connections_canonical
            ON platform_connections (user_id, surface_key, canonical_key)
            WHERE canonical_key IS NOT NULL AND deleted_at IS NULL');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    // Mirror of idx_platform_connections_unique_active (20260727110000) — the
    // operative one-row-per (user, surface, resource) constraint updateOrCreate
    // concurrency relies on.
    try {
        DB::connection('pgsql')->statement('CREATE UNIQUE INDEX IF NOT EXISTS site.idx_platform_connections_unique_active
            ON platform_connections (user_id, surface_key, resource_id)
            WHERE deleted_at IS NULL');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    // site.shop_brands + site.shop_products used to be provisioned here
    // (FOUND-25 relational storage, migration 20260704160000). Both tables are
    // GONE — dropped by 20260819000200 / 20260819000210 — and a store now lives
    // entirely in content.collections + content.storefronts (see
    // setupContentTables()). The stand-ins go with them: leaving them would let
    // a test write a legacy row that production has nowhere to put, which is
    // exactly the drift this lane exists to catch.

    // site.menus + site.menu_categories + site.menu_items + site.menu_platform_links
    // + site.menu_item_platforms + site.menu_item_categories — the relational
    // fetched menu (Uber Eats / DoorDash),
    // one menu row per user. Mirrors migrations 20260617130000 + 20260619050000
    // (jsonb→TEXT, numeric→REAL) + 20260701140000 + 20260701140100 (child tables)
    // + 20260715090000 (menu_items.currency, menus.dining_modes)
    // + 20260717170000 (menu_items.images)
    // + 20260717210000 (menus.scan_items)
    // + 20260718000000 (menu_items.is_manual, menus.suppressed_items)
    // + 20260721090000 (multi-category: menu_item_categories pivot; menu_items
    //   loses category_id + position — they live per-membership on the pivot)
    // + 20260730120000 (menus.last_successful_fetch_at — LIFE-12 episode boundary).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menus (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        content_source TEXT NULL,
        store_name TEXT NULL,
        logo_url TEXT NULL,
        rating REAL NULL,
        review_count INTEGER NULL,
        currency TEXT NULL,
        pickup_platform TEXT NULL,
        delivery_platform TEXT NULL,
        fetch_status TEXT NOT NULL DEFAULT \'pending\' CHECK (fetch_status IN (\'pending\',\'ok\',\'unavailable\')),
        dining_modes TEXT NULL,
        scan_items TEXT NULL,
        suppressed_items TEXT NULL,
        last_fetched_at TEXT NULL,
        last_successful_fetch_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
    // Defensive ALTERs for suites that created site.menus before later
    // columns existed (SQLite's CREATE TABLE IF NOT EXISTS won't add columns
    // to an already-created table within a run).
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menus ADD COLUMN dining_modes TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menus ADD COLUMN scan_items TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menus ADD COLUMN suppressed_items TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menus ADD COLUMN last_successful_fetch_at TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_categories (
        id TEXT PRIMARY KEY,
        menu_id TEXT NULL,
        name TEXT NULL,
        position INTEGER NULL,
        source_platform TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_items (
        id TEXT PRIMARY KEY NOT NULL,
        menu_id TEXT NOT NULL,
        name TEXT NOT NULL,
        description TEXT NULL,
        image_url TEXT NULL,
        images TEXT NULL,
        rating REAL NULL,
        rating_count INTEGER NULL,
        badges TEXT NULL,
        base_price REAL NULL,
        pickup_price REAL NULL,
        pickup_source TEXT NULL,
        delivery_price REAL NULL,
        delivery_source TEXT NULL,
        dd_external_id TEXT NULL,
        currency TEXT NULL,
        is_manual INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    // Defensive ALTER for suites that created site.menu_items before currency
    // existed (mirrors the site.menus dining_modes pattern above).
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menu_items ADD COLUMN currency TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menu_items ADD COLUMN images TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.menu_items ADD COLUMN is_manual INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // already exists — ignore
    }

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_platform_links (
        id TEXT PRIMARY KEY,
        menu_id TEXT NULL,
        platform TEXT NULL,
        store_url TEXT NULL,
        synced_at TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_item_platforms (
        id TEXT PRIMARY KEY,
        menu_item_id TEXT NULL,
        platform TEXT NULL,
        pickup_price REAL NULL,
        pickup_url TEXT NULL,
        delivery_price REAL NULL,
        delivery_url TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Item ↔ category memberships (multi-category, 20260721090000). Display
    // position is per-membership. Composite PK mirrors Postgres.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_item_categories (
        menu_item_id TEXT NOT NULL,
        menu_category_id TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        PRIMARY KEY (menu_item_id, menu_category_id)
    )');

    // Wire in workplace table so any test calling setupSitesTable() has the
    // full site-owned schema. Idempotent (CREATE IF NOT EXISTS).
    setupWorkplacesTable();
}

/**
 * site.workplaces — 1:1 child of site.sites, promoted from settings JSONB (FOUND-4).
 * FK is omitted on SQLite (not enforced anyway); site_id is the PK.
 */
function setupWorkplacesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.workplaces (
        site_id TEXT PRIMARY KEY,
        name TEXT NULL,
        address TEXT NULL,
        address_line1 TEXT NULL,
        city TEXT NULL,
        state TEXT NULL,
        postcode TEXT NULL,
        country TEXT NULL,
        latitude REAL NULL,
        longitude REAL NULL,
        phone TEXT NULL,
        website TEXT NULL,
        previous_website TEXT NULL,
        category TEXT NULL,
        description TEXT NULL,
        opening_hours TEXT NULL,
        contact_email TEXT NULL,
        field_sources TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    // Defensive ALTERs for suites that created the table before these columns
    // existed (mirrors the setupSitesTable pattern) — the central-identity
    // columns (opening_hours/contact_email/field_sources, migration
    // 20260705150000).
    foreach ([
        'ALTER TABLE site.workplaces ADD COLUMN opening_hours TEXT NULL',
        'ALTER TABLE site.workplaces ADD COLUMN contact_email TEXT NULL',
        "ALTER TABLE site.workplaces ADD COLUMN field_sources TEXT NOT NULL DEFAULT '{}'",
    ] as $ddl) {
        try {
            DB::connection('pgsql')->statement($ddl);
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
}

/**
 * site.public_site_payload — Postgres view in production, plain table here.
 */
function setupPublicSitePayloadTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.public_site_payload (
        site_id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        payload TEXT NULL
    )');
}

/**
 * site.site_media + site.media_variants for media upload / processing tests.
 * (Both tables live under the 'site' schema in production.)
 */
function setupMediaTables(): void
{
    attachTestSchemas();
    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        pool TEXT NULL,
        bucket TEXT NULL,
        path TEXT NULL,
        original_path TEXT NULL,
        original_mime TEXT NULL,
        original_filename TEXT NULL,
        original_size_bytes INTEGER NULL,
        media_type TEXT NULL,
        processing_state TEXT NULL,
        processing_error TEXT NULL,
        duration_ms INTEGER NULL,
        poster_path TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        alt_text TEXT NULL,
        caption TEXT NULL,
        purpose TEXT NULL,
        dominant_color TEXT NULL,
        palette TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    // media_variants column list mirrors the production migration
    // (supabase/migrations/20260403000000_v2_baseline.sql) — every column
    // nullable here, but all column names match.
    $conn->statement('CREATE TABLE IF NOT EXISTS site.media_variants (
        id TEXT PRIMARY KEY,
        media_id TEXT NULL,
        variant_key TEXT NULL,
        artifact_type TEXT NULL,
        disk TEXT NULL,
        path TEXT NULL,
        mime TEXT NULL,
        width INTEGER NULL,
        height INTEGER NULL,
        bitrate_kbps INTEGER NULL,
        file_size_bytes INTEGER NULL,
        duration_ms INTEGER NULL,
        metadata TEXT NULL,
        content_hash TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.content_selection — ordered background-content picks (≤15) for a site.
 * Mirrors the applied Postgres schema (supabase/migrations/
 * 20260705150200_create_content_selection.sql).
 *
 * Slice 7 unit E retired every read/write path into this table; its ONLY
 * remaining caller is ContentSelectionMigratorTest (the 1b carry-forward
 * migrator, which reads the table via DB::table). Both go when phase 6 drops
 * the table — do not wire new tests onto this.
 */
function setupContentSelectionTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.content_selection (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        position INTEGER NOT NULL,
        entry_type TEXT NOT NULL,
        media_id TEXT NULL,
        external_ref TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE (site_id, position)
    )');
}

/**
 * Register SQLite UDFs that mimic the Postgres functions our advisory-locking
 * code paths rely on. Production calls `pg_advisory_xact_lock(hashtext(?))`
 * to serialize concurrent reorder/upsert writes per site; under SQLite both
 * functions are absent. The shims are no-ops (locks aren't meaningful in
 * single-process in-memory SQLite anyway), which lets us exercise the real
 * production code path in tests instead of branching on driver.
 */
function shimPgAdvisoryLockForSqlite(): void
{
    $conn = DB::connection('pgsql');
    if ($conn->getDriverName() !== 'sqlite') {
        return;
    }

    $pdo = $conn->getPdo();
    $pdo->sqliteCreateFunction('hashtext', fn ($value) => crc32((string) $value), 1);
    $pdo->sqliteCreateFunction('pg_advisory_xact_lock', fn ($value) => null, 1);
}

/**
 * site.blocks — user_id/site_id NOT NULL (prod parity, DISC-9); other columns
 * nullable. Used by backfill command tests and any test that exercises Block
 * Eloquent operations in SQLite.
 */
function setupBlocksTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.blocks (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        site_id TEXT NOT NULL,
        block_group TEXT NULL,
        block_type TEXT NULL,
        title TEXT NULL,
        url TEXT NULL,
        icon_key TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        is_enabled INTEGER NULL,
        settings TEXT NULL,
        live_check_enabled INTEGER NULL DEFAULT 0,
        category TEXT NULL,
        platform TEXT NULL,
        handle TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * notifications.notification_preferences for the email-preference tests.
 */
function setupNotificationPreferencesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_preferences (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        channel TEXT NULL,
        category TEXT NULL,
        enabled INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/*
|--------------------------------------------------------------------------
| Tenant Isolation Helpers
|--------------------------------------------------------------------------
| Shared between tests/Feature/Security/TenantIsolation/*. Each helper
| creates a minimal but realistic tenant (professional + site) and returns
| the live Eloquent model so tests can wire it to a Request.
*/

function tenantHelpersEnsureTables(): void
{
    attachTestSchemas();
    setupUsersTable();
    setupSitesTable();
}

/**
 * Create an isolated tenant. Returns the freshly-loaded Professional model
 * with its Site eager-loaded. Handle namespaces records so sequential calls
 * never collide.
 */
/**
 * @param  array<string, mixed>  $overrides  core.users column overrides —
 *                                           account_type/sector/status, for tests that exercise capability gating.
 */
function createTenant(string $handle, array $overrides = []): User
{
    tenantHelpersEnsureTables();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    // $overrides FIRST: PHP's + keeps the left operand on key collision, so
    // this is what makes an override actually override.
    DB::connection('pgsql')->table('core.users')->insert($overrides + [
        'id' => $proId,
        'auth_user_id' => 'auth-'.Str::random(12),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        // NOT NULL in prod (core.users.first_name) — supplying it here keeps the
        // helper's rows insertable against the real schema, not just SQLite's.
        'first_name' => ucfirst($handle),
        'primary_email' => $handle.'@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => $handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return User::query()->with('site')->findOrFail($proId);
}

/**
 * Two fully-independent tenants for cross-tenant isolation tests.
 * Handles are deliberately neutral — the brand/affiliate account types this
 * helper used to distinguish were retired, and the old handle prefixes implied
 * a distinction that no longer exists.
 */
function createTwoTenants(): array
{
    return [createTenant('tenant-a'), createTenant('tenant-b')];
}

/**
 * Make a Request that simulates authenticated access as $tenant.
 * Mirrors the pattern from DocumentControllerIntegrationTest — `current.pro`
 * middleware normally sets this attribute at runtime.
 *
 * Named tenantRequestAs() to avoid collision with the local requestAs() helper
 * declared in UserEnquiryControllerTest (different signature).
 */
function tenantRequestAs(User $tenant, array $input = [], string $method = 'GET'): Request
{
    $req = Request::create('/', $method, $input);
    $req->attributes->set('professional', $tenant);
    $req->setUserResolver(fn () => (object) ['professional' => $tenant]);

    return $req;
}

/**
 * audit.user_deletion_audit — all columns nullable, minimal for purge tests.
 */
function setupUserDeletionAuditTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.user_deletion_audit (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        user_id TEXT NULL,
        professional_handle_snapshot TEXT NULL,
        professional_email_snapshot TEXT NULL,
        event TEXT NULL,
        actor_type TEXT NULL,
        actor_id TEXT NULL,
        actor_handle_snapshot TEXT NULL,
        reason TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        metadata TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * core.professional_integrations — superset of all columns webhook controllers query.
 * Includes shopify_shop_domain (production has it; the older WebhookCrossTenantTest
 * schema omits it). All columns nullable.
 */
function setupUserIntegrationsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        provider TEXT NULL,
        external_account_id TEXT NULL,
        shopify_shop_domain TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        storefront_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        expires_at TEXT NULL,
        reconciled_through TEXT NULL,
        disconnected_at TEXT NULL,
        webhook_registration_state TEXT NULL,
        last_catalog_sync_error TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Sign a body string with the Shopify HMAC scheme: base64(HMAC-SHA256(body, secret)).
 * Mirrors the production controller's verification in ValidatesShopifyWebhookHmac.
 */
function signShopifyBody(string $body, string $secret): string
{
    return base64_encode(hash_hmac('sha256', $body, $secret, true));
}

/**
 * Sign a body string with Square's HMAC scheme: base64(HMAC-SHA256(notification_url + body, key)).
 * The notification_url MUST match config('services.square.webhook_notification_url') OR the
 * request's full URL — controller tries both.
 */
function signSquareBody(string $notificationUrl, string $body, string $key): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
}

/**
 * site.service_categories — minimal columns for Square sync tests.
 */
function setupServiceCategoriesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        title TEXT NULL,
        sort_order INTEGER NULL,
        source TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.service_categories ADD COLUMN source TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }
}

/**
 * site.services — all columns nullable except PK. Includes deleted_origin for sync-origin tracking.
 */
function setupServicesTable(): void
{
    attachTestSchemas();
    setupServiceCategoriesTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        title TEXT NULL,
        description TEXT NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        duration_minutes INTEGER NULL,
        is_active INTEGER NULL,
        sort_order INTEGER NULL,
        deleted_origin TEXT NULL,
        source TEXT NULL,
        is_manual INTEGER NOT NULL DEFAULT 0,
        external_id TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    // Defensive ALTERs for suites that created site.services before the Fresha
    // projection columns existed (mirrors the site.menus pattern above).
    foreach (['source TEXT NULL', 'is_manual INTEGER NOT NULL DEFAULT 0', 'external_id TEXT NULL'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.services ADD COLUMN {$col}");
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }

    // services_user_sort_order_uq (supabase/migrations/20260726000000_baseline_pilot.sql:3307):
    // UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL — GLOBAL per
    // user, NOT scoped by source. Slice 3a Task 5 review round 3: without
    // this in the test schema, a Fresha-only sort_order renumber (the exact
    // defect UserServiceController::renumberLegacySortOrder() exists to fix
    // — see its docblock) has nothing to collide with, so no test can prove
    // the fix does anything. SQLite quirk (verified empirically — a QUOTED
    // "site.name" identifier resolves `ON services` against `main.services`
    // and fails with "no such table", silently swallowed by a bare
    // try/catch): the working form is UNQUOTED `site.name`, matching the
    // pre-existing throwaway indexes in ServiceLayoutReorderTest.php et al.
    try {
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS site.services_user_sort_order_uq
             ON services (user_id, sort_order)
             WHERE deleted_at IS NULL'
        );
    } catch (Throwable $e) {
        // already exists — ignore
    }

    // Service ↔ category memberships (multi-category, 20260721180000).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.service_category_assignments (
        service_id TEXT NOT NULL,
        service_category_id TEXT NOT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        PRIMARY KEY (service_id, service_category_id)
    )');
}

/**
 * site.customers — all columns nullable, mirrors the production schema.
 */
function setupCustomersTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.customers (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        full_name TEXT NULL,
        source TEXT NULL,
        notes TEXT NULL,
        external_id TEXT NULL,
        marketing_opt_in_cached INTEGER NULL,
        redacted_at TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Insert a Customer row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createCustomerFor(User $pro, array $overrides = []): Customer
{
    setupCustomersTable();

    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'email' => 'customer-'.Str::random(6).'@example.test',
        'full_name' => 'Test Customer',
        'source' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.customers')->insert($row);

    return Customer::query()->findOrFail($id);
}

/**
 * Insert a Service row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createServiceFor(User $pro, array $overrides = []): Service
{
    setupServicesTable();

    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    // Multi-category (20260721180000): category_id is no longer a services
    // column — a passed override becomes a pivot membership instead, so the
    // many existing call sites keep working unchanged.
    $categoryId = $overrides['category_id'] ?? null;
    unset($overrides['category_id']);

    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'title' => 'Test Service',
        'price_cents' => 5000,
        'currency_code' => 'AUD',
        'is_active' => 1,
        // services_user_sort_order_uq is UNIQUE(user_id, sort_order) WHERE
        // deleted_at IS NULL — hardcoding 0 here mints an impossible state
        // (two rows sharing sort_order=0) the moment a second call for the
        // same $pro doesn't override it. Next free slot by default; an
        // explicit ['sort_order' => N] override still wins via array_merge.
        'sort_order' => nextServiceSortOrderFor($pro->id),
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.services')->insert($row);
    if ($categoryId !== null) {
        DB::connection('pgsql')->table('site.service_category_assignments')->insert([
            'service_id' => $id,
            'service_category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return Service::withTrashed()->findOrFail($id);
}

/**
 * Insert a raw, owner-authored site.services row (source IS NULL) and
 * return its id. Slice 3a: ServiceBackfillerTest is the sole caller today,
 * but later slice-3 tasks land more service-backfill tests — kept global
 * for the same reason seedContentItem() is, and distinct from
 * createServiceFor() (which returns an Eloquent model and defaults to a
 * generic "Test Service" row rather than the specific title/description/
 * duration ServiceBackfillerTest's assertions are keyed on).
 *
 * @param  array<string, mixed>  $overrides
 */
function ownerService(string $userId, array $overrides = []): string
{
    setupServicesTable();

    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.services')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'Consultation',
        'description' => 'A chat about your hair.',
        'price_cents' => 6500,
        'currency_code' => 'AUD',
        'duration_minutes' => 45,
        'is_active' => 1,
        // See createServiceFor()'s identical comment — hardcoding 0 mints an
        // impossible state under services_user_sort_order_uq as soon as a
        // second row lands for the same user without an explicit override.
        'sort_order' => nextServiceSortOrderFor($userId),
        'source' => null,
        'is_manual' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

/**
 * The next free site.services.sort_order for $userId — max(sort_order)+1
 * over their LIVE rows (services_user_sort_order_uq is scoped WHERE
 * deleted_at IS NULL, so a trashed row's value is free to reuse), falling
 * back to 0 when they have none. Shared by createServiceFor() and
 * ownerService() so both fixture helpers default to a collision-free slot
 * instead of hardcoding 0 — assumes setupServicesTable() has already run
 * (both callers call it first).
 */
function nextServiceSortOrderFor(string $userId): int
{
    $max = DB::connection('pgsql')->table('site.services')
        ->where('user_id', $userId)
        ->whereNull('deleted_at')
        ->max('sort_order');

    return $max === null ? 0 : ((int) $max + 1);
}

/**
 * Seed a tenant (core.users + site.sites pair, via createTenant()) and
 * return [userId, siteId] — the pair Task 3's pin/invalidation tests and
 * Task 4's public-read tests both need. Kept global, not file-local: a
 * file-local definition only resolves when Pest loads multiple test files
 * together (e.g. `--filter`), not when a single file runs by explicit path
 * — the same load-order hazard ownerService()'s docblock above already
 * flags for cross-file helpers.
 *
 * Distinct from the file-local serviceOwner() in ServiceBackfillerTest.php,
 * which returns only the user id (a string) — callers that don't need the
 * site id keep using that one; this one exists because they do.
 *
 * @return array{0: string, 1: string} [userId, siteId]
 */
function seedUserWithSite(): array
{
    $tenant = createTenant('svc-'.Str::lower(Str::random(8)));

    return [$tenant->id, $tenant->site->id];
}

/**
 * Slice 4: a tenant plus one menu, its categories and its dishes, wired the
 * way MenuFetchJob::persist() writes them — every dish holds a membership in
 * every category, and one menu_item_platforms row per platform.
 *
 * Global rather than file-local for the reason seedUserWithSite()'s docblock
 * gives: MenuBackfillerTest and MenuSlugLaneTest both need it, and a
 * file-local definition only resolves when Pest happens to load both files
 * together — which is also a fatal under --parallel.
 *
 * @param  list<string>  $names  dish names, in menu order
 * @param  list<string>  $categories
 * @param  list<string>  $platforms
 * @return array{0: string, 1: string, 2: string, 3: array<string, string>} [userId, siteId, menuId, categoryIdsByName]
 */
function seedMenuWithDishes(
    array $names,
    array $categories = ['Menu'],
    array $platforms = ['uber_eats'],
    ?string $currency = 'AUD',
): array {
    [$userId, $siteId] = seedUserWithSite();

    $menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId,
        'user_id' => $userId,
        'content_source' => 'uber-eats',
        'currency' => $currency,
        'fetch_status' => 'ok',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Phase 6: this used to insert site.menu_categories / menu_items /
    // menu_item_categories / menu_item_platforms and leave callers to run
    // MenuBackfiller. Those four tables are dropped, so the helper writes the
    // content lane directly through the SAME writer the live paths use —
    // ManualMenuWriter + MenuProjectionMapper — which is what the backfiller
    // produced anyway. site.menus survives and is still seeded above.
    $writer = app(ManualMenuWriter::class);

    $categoryEntries = [];
    $categoryIds = [];
    foreach (array_values($categories) as $position => $name) {
        $categoryEntries[] = ['id' => '', 'name' => $name, 'position' => $position];
        $categoryIds[$name] = MenuProjectionMapper::categoryRef($name);
    }

    $platformRows = array_map(
        static fn (string $platform) => (object) [
            'platform' => $platform,
            'delivery_price' => 6.5,
            'delivery_url' => 'https://example.test/'.$platform,
        ],
        array_values($platforms),
    );

    foreach (array_values($names) as $position => $name) {
        $dish = (object) [
            'name' => $name,
            'description' => null,
            'base_price' => 5.5,
            'pickup_price' => null,
            'delivery_price' => null,
            'currency' => $currency,
            'image_url' => null,
            'images' => null,
            'rating' => null,
            'rating_count' => null,
            'badges' => null,
        ];

        $writer->write(
            $userId,
            MenuProjectionMapper::coordFor($menuId, $name),
            $writer->projectionFor($dish, $categoryEntries, $platformRows, (object) ['currency' => $currency]),
        );
    }

    return [$userId, $siteId, $menuId, $categoryIds];
}

/**
 * The `order_platform` collection's `content.storefronts` sidecar — the shape
 * MenuFetchJob::syncOrderPlatforms() writes from a site.menu_platform_links
 * row. Fixture-only: it is the INPUT to the store-card rendering under test,
 * and MenuBackfiller (which used to seed it from the same table) retired with
 * the legacy menu tables in slice 7 Phase 6.
 */
function seedOrderPlatformSidecar(
    string $userId,
    string $platform,
    ?string $storeUrl = null,
    ?string $currency = 'AUD',
): void {
    $ref = MenuProjectionMapper::orderPlatformRef($platform);

    $collectionId = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)
        ->where('kind', 'order_platform')
        ->where('external_ref', $ref)
        ->value('id');

    if ($collectionId === null) {
        return;
    }

    // Mirrors MenuFetchJob::syncOrderPlatforms()'s own upsert, user_id
    // included — that column is NOT NULL in production since re-home Task 11.
    DB::connection('pgsql')->table('content.storefronts')->upsert([[
        'collection_id' => (string) $collectionId,
        'user_id' => $userId,
        'provider' => $platform,
        'url' => $storeUrl,
        'external_ref' => $ref,
        'currency' => $currency,
        'referral_query' => '',
        'is_individual' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]], ['collection_id'], ['user_id', 'url', 'currency', 'updated_at']);
}

/**
 * Insert a ServiceCategory row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createServiceCategoryFor(User $pro, array $overrides = []): ServiceCategory
{
    setupServiceCategoriesTable();

    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'title' => 'Test Category',
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.service_categories')->insert($row);

    return ServiceCategory::withoutGlobalScopes()->findOrFail($id);
}

/**
 * Insert a link-type Block row for $pro and return the Eloquent model.
 */
function createLinkBlockFor(User $pro, array $overrides = []): Block
{
    setupBlocksTable();

    $id = (string) Str::uuid();
    $site = $pro->relationLoaded('site') ? $pro->site : $pro->load('site')->site;
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'site_id' => $site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Test Link',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => 1,
        'live_check_enabled' => 0,
        'category' => null,
        'platform' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.blocks')->insert($row);

    return Block::query()->findOrFail($id);
}

/**
 * notifications.notifications — minimal columns for notification policy enforcement tests.
 */
function setupNotificationsTable(): void
{
    attachTestSchemas();
    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        critical INTEGER NOT NULL DEFAULT 0,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        email_sent_at TEXT NULL,
        dedupe_key TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Defensive ALTER for suites that created the table before the OV-A
    // critical column existed (mirrors migration 20260711000400).
    try {
        $conn->statement('ALTER TABLE notifications.notifications ADD COLUMN critical INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // already exists — ignore
    }

    // Defensive ALTER for suites that created the table before the dedupe_key
    // column existed (mirrors baseline migration line 1031).
    try {
        $conn->statement('ALTER TABLE notifications.notifications ADD COLUMN dedupe_key TEXT NULL');
    } catch (Throwable $e) {
        // already exists — ignore
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
        id TEXT PRIMARY KEY,
        notification_id TEXT NULL,
        user_id TEXT NULL,
        read_at TEXT NULL,
        dismissed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE(notification_id, user_id)
    )');
}

/**
 * Insert a SiteMedia document-pool row for $pro's site and return the model.
 */
function createDocumentFor(User $pro, array $overrides = []): SiteMedia
{
    setupMediaTables();

    $id = (string) Str::uuid();
    $site = $pro->relationLoaded('site') ? $pro->site : $pro->load('site')->site;
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_DOCUMENTS,
        'media_type' => 'application/pdf',
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'alt_text' => 'Test Document',
        'original_filename' => 'test.pdf',
        'path' => 'documents/test.pdf',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.site_media')->insert($row);

    return SiteMedia::query()->findOrFail($id);
}

/**
 * core.feature_flags + core.feature_flag_overrides — needed by any test that
 * exercises feature gating (the `feature:` middleware, FeatureFlagService, the
 * flag-prune command).
 *
 * CANONICAL definition — Tests\Feature\FeatureFlags\FeatureFlagTestCase::boot()
 * calls this rather than keeping its own copy. Columns must match
 * supabase/migrations-archive/20260526000000_baseline_standalone_user.sql:575-590
 * exactly (nine, after the professional_id -> user_id rename in 20260527030000).
 * Never add a column that has no migration backing: a phantom `brand_id` in the
 * old duplicate masked a live Postgres 42703 for two months (#FFLAG-1).
 * Enforced by tests/Feature/Database/FixtureSchemaParityTest.php.
 */
function setupFeatureFlagsTable(): void
{
    attachTestSchemas();
    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.feature_flags (
        key TEXT PRIMARY KEY,
        description TEXT,
        default_enabled INTEGER DEFAULT 0,
        rollout_percent INTEGER DEFAULT 0,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.feature_flag_overrides (
        id TEXT PRIMARY KEY,
        flag_key TEXT,
        user_id TEXT,
        enabled INTEGER DEFAULT 0,
        reason TEXT,
        expires_at TEXT,
        created_by TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
}

/**
 * core.partna_staff — internal staff accounts, linked to Supabase auth users.
 */
function setupPartnaStaffTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        role TEXT NULL,
        primary_email TEXT NULL,
        name TEXT NULL,
        phone TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.user_segments + core.user_segment_members — OV-A staff segments.
 * Mirrors migration 20260711000100.
 */
function setupSegmentsTables(): void
{
    attachTestSchemas();
    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.user_segments (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT NULL,
        filters TEXT NOT NULL DEFAULT \'{}\',
        created_by TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.user_segment_members (
        id TEXT PRIMARY KEY,
        segment_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        added_by TEXT NULL,
        created_at TEXT NULL,
        UNIQUE(segment_id, user_id)
    )');
}

/**
 * core.feature_availability — OV-A staff availability rules.
 * Mirrors migration 20260711000200.
 */
function setupFeatureAvailabilityTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.feature_availability (
        id TEXT PRIMARY KEY,
        feature_key TEXT NOT NULL,
        mode TEXT NOT NULL,
        segment_id TEXT NULL,
        created_by TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.early_access_signups — OV-A early-access lifecycle rows.
 * Mirrors migration 20260711000300.
 */
function setupEarlyAccessTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.early_access_signups (
        id TEXT PRIMARY KEY,
        email TEXT NOT NULL,
        email_lc TEXT NOT NULL UNIQUE,
        type TEXT NOT NULL,
        workplace_or_industry TEXT NULL,
        platforms TEXT NOT NULL DEFAULT \'[]\',
        status TEXT NOT NULL DEFAULT \'waitlist\',
        source TEXT NOT NULL DEFAULT \'marketing\',
        invited_at TEXT NULL,
        invite_token_hash TEXT NULL,
        invite_meta TEXT NULL,
        invited_by TEXT NULL,
        signed_up_at TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Defensive ALTERs for suites that created core.early_access_signups
    // before source_type/source_ref/user_id existed. Mirrors migration
    // 20260721120000.
    foreach (['source_type TEXT NULL', 'source_ref TEXT NULL', 'user_id TEXT NULL'] as $col) {
        try {
            DB::connection('pgsql')->statement('ALTER TABLE core.early_access_signups ADD COLUMN '.$col);
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
}

/**
 * analytics.site_visits — raw visit events used by live analytics read queries.
 */
function setupSiteVisitsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        visitor_id TEXT NULL,
        session_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        device_type TEXT NULL,
        country_code TEXT NULL,
        region_code TEXT NULL,
        city TEXT NULL,
        latitude REAL NULL,
        longitude REAL NULL,
        referrer TEXT NULL,
        utm_source TEXT NULL,
        utm_medium TEXT NULL,
        utm_campaign TEXT NULL,
        occurred_at TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * analytics.link_clicks — minimal columns for click dedup and analytics tests.
 */
function setupLinkClicksTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.link_clicks (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        link_block_id TEXT NULL,
        occurred_at TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        utm_source TEXT NULL,
        utm_medium TEXT NULL,
        utm_campaign TEXT NULL,
        url TEXT NULL,
        platform TEXT NULL,
        product_id TEXT NULL,
        product_title TEXT NULL,
        section_key TEXT NULL,
        label TEXT NULL,
        country_code TEXT NULL,
        region_code TEXT NULL,
        device_type TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * analytics.site_sessions — v2 session heartbeats (live-now + avg duration).
 *
 * #DINT-1: composite PRIMARY KEY (id, site_id), matching the prod end-state
 * after 20260711160200/20260711160300 — id alone is no longer unique so two
 * sites can hold a row for the same client-minted session id. site_id is
 * NOT NULL (matches prod DDL; the writer always supplies it).
 */
function setupSiteSessionsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_sessions (
        id TEXT NOT NULL,
        user_id TEXT NULL,
        site_id TEXT NOT NULL,
        visitor_id TEXT NULL,
        started_at TEXT NULL,
        last_seen_at TEXT NULL,
        duration_seconds INTEGER NOT NULL DEFAULT 0,
        country_code TEXT NULL,
        region_code TEXT NULL,
        device_type TEXT NULL,
        referrer TEXT NULL,
        created_at TEXT NULL,
        PRIMARY KEY (id, site_id)
    )');
}

/**
 * analytics.section_views — Phase 5 storefront section-seen events.
 */
function setupSectionViewsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.section_views (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        block_id TEXT NULL,
        section_key TEXT NOT NULL,
        occurred_at TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        utm_source TEXT NULL,
        utm_medium TEXT NULL,
        utm_campaign TEXT NULL,
        country_code TEXT NULL,
        device_type TEXT NULL,
        duration_ms INTEGER NULL,
        created_at TEXT NULL
    )');
}

/**
 * analytics.item_views — item-level impression events (popularity scoring).
 * Mirrors the applied Postgres DDL (20260709042911_create_item_views.sql):
 * section_views' visitor/session/geo columns with an item grain. user_id is
 * nullable here (matches prod — fail-open on the new write path).
 */
function setupItemViewsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.item_views (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NOT NULL,
        item_type TEXT NOT NULL,
        item_id TEXT NOT NULL,
        item_title TEXT NULL,
        section_key TEXT NULL,
        occurred_at TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        country_code TEXT NULL,
        device_type TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * analytics.action_events — raw exposure/tap events for the unified actions
 * system. Mirrors the applied Postgres DDL (20260723090000_create_action_events.sql),
 * same posture as setupItemViewsTable() above (site_id/event CHECK+FK are
 * app-validated in tests via plain inserts, not enforced by SQLite here).
 */
function setupActionEventsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.action_events (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NOT NULL,
        action_id TEXT NOT NULL,
        event TEXT NOT NULL,
        occurred_at TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        country_code TEXT NULL,
        device_type TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * analytics.content_popularity_scores — polymorphic popularity ranks per
 * (site, content_type, content_key). Mirrors the applied Postgres DDL
 * (20260709042716_create_content_popularity_scores.sql). SQLite doesn't enforce
 * the double-precision / integer types beyond affinity, which is fine — the
 * upsert/read shape is what the tests exercise.
 */
function setupContentPopularityScoresTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.content_popularity_scores (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        content_type TEXT NOT NULL,
        content_key TEXT NOT NULL,
        score REAL NOT NULL,
        rank INTEGER NOT NULL,
        computed_at TEXT NULL,
        UNIQUE (site_id, content_type, content_key)
    )');
}

/**
 * analytics.scoring_watermarks — the popularity sweep's single-row
 * last-successful-run watermark (20260827070000).
 */
function setupScoringWatermarksTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.scoring_watermarks (
        id TEXT PRIMARY KEY,
        last_completed_at TEXT NOT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * notifications.email_subscriptions — minimal columns for broadcast fan-out tests.
 */
function setupEmailSubscriptionsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        list_key TEXT NOT NULL DEFAULT "marketing",
        email TEXT NOT NULL,
        email_lc TEXT NOT NULL,
        full_name TEXT NULL,
        status TEXT NOT NULL DEFAULT "subscribed",
        subscribed_at TEXT NULL,
        unsubscribed_at TEXT NULL,
        unsubscribe_token TEXT NOT NULL,
        confirmation_sent_at TEXT NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * ingest.* schema (supabase/migrations/20260727130000_ingest_schema.sql) —
 * SQLite mirror. record_versions is HASH-partitioned in production purely
 * for volume; SQLite has no partitioning, so it is one plain table here —
 * what the tests actually depend on is the content-addressed
 * UNIQUE(stream_id, key, doc_hash) index, which is what makes
 * insertOrIgnore's "unchanged content writes nothing" property hold on
 * either engine.
 *
 * Lander::foldAbsence() embeds a raw `now()` SQL fragment inside an UPDATE
 * (Postgres has that function; SQLite doesn't), so a `now` UDF is shimmed
 * here the same way shimPgAdvisoryLockForSqlite() shims hashtext /
 * pg_advisory_xact_lock elsewhere in this file.
 */
/**
 * content + site section/document tables (migrations 20260727140000 and
 * 20260727150000) — SQLite mirror. Only the tables the builder and resolvers
 * actually touch; the full 33-table content schema is exercised in the
 * Postgres lane, not here.
 */
function setupSectionsTables(): void
{
    attachTestSchemas();
    $pg = DB::connection('pgsql');

    // Attached HERE rather than in attachTestSchemas()' shared list: SQLite
    // caps attachments at 10, and only the tests that touch content need it.
    // (Same pattern setupIngestTables() uses for `ingest`.)
    try {
        $pg->statement("ATTACH DATABASE ':memory:' AS content");
    } catch (Throwable $e) {
        // already attached — ignore
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS content.items (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        kind TEXT NOT NULL CHECK (kind IN (
            \'video\', \'track\', \'release\', \'episode\', \'channel\', \'service\', \'menu_item\',
            \'product\', \'event\', \'link\', \'media\', \'review\', \'document\', \'article\'
        )),
        headline_cache TEXT NULL,
        facets_cache TEXT NOT NULL DEFAULT \'[]\',
        removed_at TEXT NULL,
        review_flag TEXT NULL,
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS site.pages (
        id TEXT PRIMARY KEY NOT NULL,
        site_id TEXT NOT NULL,
        key TEXT NOT NULL,
        label TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        order_mode TEXT NOT NULL DEFAULT \'manual\' CHECK (order_mode IN (\'manual\', \'auto\')),
        is_hidden INTEGER NOT NULL DEFAULT 0,
        capability TEXT NULL,
        preset_key TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    // All seven CHECKs copied verbatim from
    // supabase/migrations/20260727150000_sections_and_documents.sql:42-59
    // (#PARITY-1).
    $pg->statement('CREATE TABLE IF NOT EXISTS site.sections (
        id TEXT PRIMARY KEY NOT NULL,
        page_id TEXT NOT NULL,
        site_id TEXT NOT NULL,
        key TEXT NULL,
        label TEXT NULL,
        slot TEXT NOT NULL DEFAULT \'body\' CHECK (slot IN (\'body\', \'header_actions\', \'footer\')),
        kind TEXT NOT NULL CHECK (kind IN (\'collection\', \'richtext\', \'contact_form\', \'newsletter\', \'map\', \'document\', \'policy\')),
        sort_order INTEGER NOT NULL DEFAULT 0,
        rule TEXT NOT NULL DEFAULT \'{}\',
        mode TEXT NOT NULL DEFAULT \'automatic\' CHECK (mode IN (\'hand_picked\', \'automatic\', \'mixed\')),
        order_by TEXT NOT NULL DEFAULT \'recency\',
        limit_n INTEGER NULL,
        group_by TEXT NULL CHECK (group_by IS NULL OR group_by IN (\'category\', \'source\', \'month\', \'letter\')),
        render TEXT NOT NULL DEFAULT \'cards\' CHECK (render IN (\'cards\', \'rows\', \'tiles\', \'buttons\', \'carousel\')),
        density TEXT NULL,
        price_mode TEXT NULL,
        min_items INTEGER NOT NULL DEFAULT 1,
        on_empty TEXT NOT NULL DEFAULT \'hide\' CHECK (on_empty IN (\'hide\', \'show_message\', \'show_anyway\')),
        stale_display TEXT NOT NULL DEFAULT \'inherit\' CHECK (stale_display IN (\'inherit\', \'show\', \'hide\')),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    // section_items.item_id -> content.items(id) has NO FK here — SQLite
    // cannot express a cross-attached-database FK (content and site are
    // separate ATTACHed :memory: databases; REFERENCES takes no schema
    // qualifier). The FK is real in Postgres (migration 20260729150007/8)
    // and covered on the Postgres lane by tests/Postgres/SectionItemsItemFkTest.php.
    $pg->statement('CREATE TABLE IF NOT EXISTS site.section_items (
        id TEXT PRIMARY KEY NOT NULL,
        section_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        state TEXT NOT NULL CHECK (state IN (\'pinned\', \'excluded\')),
        sort_key REAL NULL,
        created_at TEXT NOT NULL,
        -- Mirrors section_items_unique (20260727150000_sections_and_documents.sql:87).
        -- Postgres has it; the stand-in did not, so a double pin — which a
        -- hand-add folding onto an already-pinned item now produces — raised
        -- 23505 in production and nothing under `composer test`.
        UNIQUE (section_id, item_id)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS site.site_documents (
        id TEXT PRIMARY KEY NOT NULL,
        site_id TEXT NOT NULL,
        version INTEGER NOT NULL,
        channel TEXT NOT NULL DEFAULT \'live\' CHECK (channel IN (\'live\', \'draft\')),
        document TEXT NOT NULL,
        content_hash TEXT NOT NULL,
        builder_revision INTEGER NOT NULL,
        warnings TEXT NOT NULL DEFAULT \'[]\',
        built_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS site.site_build_state (
        site_id TEXT PRIMARY KEY NOT NULL,
        content_revision INTEGER NOT NULL DEFAULT 0,
        built_revision INTEGER NOT NULL DEFAULT 0,
        building_since TEXT NULL,
        popularity_floor_at TEXT NULL,
        last_built_at TEXT NULL,
        updated_at TEXT NOT NULL
    )');
}

/**
 * Pins a dummy item into the watch/listen/events pool sections so
 * PoolResolver::hasSelection() answers true from the pinned row alone,
 * without ever reaching its `latest_per_auto_source`/`upcoming_occurrence`
 * rule candidates — the only place those probes would touch
 * content.sources/content.source_items. Used by presence-probe fault tests
 * (PresenceProbeLoggingTest, PresenceProbeEscalationTest) that deliberately
 * fault the SERVICES probe on those same two missing tables and need the
 * pool probes to stay clean so their fault counts/log assertions aren't
 * diluted. Global here (not file-local) — both test files use it, and
 * cross-file global function definitions depend on Pest's load order (same
 * reasoning as ownerService()'s docblock above).
 */
function pinPoolPresence(Site $site): void
{
    // custom_links joined the presence loop 2026-08-19 (pool-only links must
    // flip the Links page) — pinned here for the same reason as the others:
    // the probe tests need every pool probe short-circuited so only the
    // probe under test faults.
    foreach (['watch', 'listen', 'events', 'custom_links'] as $pool) {
        $section = app(PoolSectionProvisioner::class)->ensure($site, $pool);
        DB::connection('pgsql')->table('site.section_items')->insert([
            'id' => (string) Str::uuid(),
            'section_id' => $section->id,
            'item_id' => (string) Str::uuid(),
            'state' => 'pinned',
            'sort_key' => 0,
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}

/**
 * Shared fixture builders for DocumentBuilder tests (DocumentBuilderTest,
 * DocumentBuilderQueryCountTest). Global here — not file-local — so both
 * test files can use them regardless of which one PHPUnit loads first.
 */
function buildTestSite(): array
{
    $pro = createTenant('builder-'.Str::lower(Str::random(6)));

    return [$pro->id, DB::table('site.sites')->where('user_id', $pro->id)->value('id')];
}

function addPage(string $siteId, string $key, string $label, int $order = 0): string
{
    $id = (string) Str::uuid();
    DB::table('site.pages')->insert([
        'id' => $id, 'site_id' => $siteId, 'key' => $key, 'label' => $label,
        'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function addSection(string $siteId, string $pageId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('site.sections')->insert(array_merge([
        'id' => $id, 'site_id' => $siteId, 'page_id' => $pageId,
        'kind' => 'collection', 'slot' => 'body', 'mode' => 'automatic',
        'render' => 'cards', 'order_by' => 'recency', 'on_empty' => 'hide',
        'min_items' => 1, 'stale_display' => 'inherit',
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]]),
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides));

    return $id;
}

function addItem(string $userId, string $kind, string $headline): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'headline_cache' => $headline, 'facets_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * content.* plane beyond content.items (migration 20260727140000) — SQLite
 * mirror for projection/identity tests: the channel + per-record grains,
 * identity evidence, anchors, and the typed facet tables ProjectionWriter
 * writes. jsonb→TEXT, bool→INTEGER, timestamptz→TEXT per house convention.
 */
function setupContentTables(): void
{
    setupSectionsTables();
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.sources (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        kind TEXT NOT NULL CHECK (kind IN (\'connection\',\'manual\',\'import\')),
        connection_id TEXT NULL,
        import_run_id TEXT NULL,
        label TEXT NULL,
        priority INTEGER NOT NULL DEFAULT 100,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    // Mirrors idx_content_sources_manual (20260727140000): one manual source
    // per user. SQLite puts the schema qualifier on the INDEX name, not the
    // table — same quirk as idx_platform_connections_canonical above.
    try {
        $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS content.idx_content_sources_manual
            ON sources (user_id) WHERE kind = \'manual\'');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS content.source_items (
        id TEXT PRIMARY KEY NOT NULL,
        source_id TEXT NOT NULL,
        coord TEXT NOT NULL,
        stream_id TEXT NULL,
        record_key TEXT NULL,
        item_id TEXT NULL,
        kind TEXT NOT NULL CHECK (kind IN (
            \'video\', \'track\', \'release\', \'episode\', \'channel\', \'service\', \'menu_item\',
            \'product\', \'event\', \'link\', \'media\', \'review\', \'document\', \'article\'
        )),
        projector_version INTEGER NOT NULL DEFAULT 1,
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        removed_at TEXT NULL,
        UNIQUE (source_id, coord)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.identity_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_item_id TEXT NOT NULL,
        key_class TEXT NOT NULL,
        key_value TEXT NOT NULL,
        tier TEXT NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.identity_decisions (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        verdict TEXT NOT NULL CHECK (verdict IN (\'same\',\'different\')),
        left_coord TEXT NOT NULL,
        right_coord TEXT NOT NULL,
        decided_at TEXT NOT NULL,
        decided_by TEXT NULL,
        UNIQUE (user_id, left_coord, right_coord)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_anchors (
        coord TEXT NOT NULL,
        user_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        bound_at TEXT NOT NULL,
        superseded_by TEXT NULL,
        PRIMARY KEY (user_id, coord)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_merges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        kept_item_id TEXT NULL,
        discarded_item_id TEXT NULL,
        reason TEXT NOT NULL,
        detail TEXT NOT NULL DEFAULT \'{}\',
        merged_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.identity_candidates (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        left_item_id TEXT NOT NULL,
        right_item_id TEXT NOT NULL,
        score INTEGER NOT NULL,
        evidence TEXT NOT NULL DEFAULT \'{}\',
        dismissed_at TEXT NULL,
        created_at TEXT NOT NULL,
        UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.manual_overrides (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        facet TEXT NOT NULL,
        column_name TEXT NOT NULL,
        value TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE (item_id, facet, column_name)
    )');

    // Singleton facets: one row per (item, source); columns per 20260727140000.
    $singletons = [
        'f_text' => 'headline TEXT NULL, body TEXT NULL, summary TEXT NULL',
        'f_link' => 'url TEXT NOT NULL, canonical_url TEXT NULL',
        'f_duration' => 'seconds INTEGER NULL',
        'f_published' => 'published_from TEXT NULL, published_to TEXT NULL, verbatim TEXT NULL, precision TEXT NULL CHECK (precision IS NULL OR precision IN (\'year\', \'month\', \'day\', \'time\'))',
        'f_occurrence' => 'starts_at_local TEXT NULL, ends_at_local TEXT NULL, timezone TEXT NULL, zone_confidence TEXT NULL CHECK (zone_confidence IS NULL OR zone_confidence IN (\'explicit\', \'inferred\', \'assumed\', \'offset_only\')), starts_at_utc TEXT NULL, is_all_day INTEGER NOT NULL DEFAULT 0',
        'f_embed' => 'provider TEXT NOT NULL, embed_key TEXT NOT NULL, variant TEXT NULL',
        'f_playable' => 'stream_url TEXT NULL, preview_url TEXT NULL, is_explicit INTEGER NULL',
        'f_authored' => 'creator TEXT NULL, creator_url TEXT NULL, collaborators TEXT NULL',
        // handle/vendor/variant_ref: slice 5a Task 7 fix round 1, Finding 3
        // (supabase/migrations/20260813100002_f_catalog_product_fields.sql —
        // applied to the shared dev database in fix round 2; this stand-in
        // mirrors it for the SQLite lane, which never runs real migrations).
        'f_catalog' => 'release_type TEXT NULL, track_number INTEGER NULL, disc_number INTEGER NULL, isrc TEXT NULL, gtin TEXT NULL, sku TEXT NULL, handle TEXT NULL, vendor TEXT NULL, variant_ref TEXT NULL, collection_title TEXT NULL',
        'f_place' => 'venue_name TEXT NULL, address TEXT NULL, locality TEXT NULL, region TEXT NULL, country_code TEXT NULL, latitude REAL NULL, longitude REAL NULL',
        'f_rated' => 'rating REAL NULL, rating_max REAL NULL, ratings_count INTEGER NULL',
        // author_uri: slice 6 Task 1 (supabase/migrations/
        // 20260813110000_f_review_author_uri.sql). Third-party PII, redacted
        // when_unclaimed by the connector manifest exactly as author_name and
        // author_photo_url already are.
        'f_review' => 'author_name TEXT NULL, author_photo_url TEXT NULL, author_uri TEXT NULL, rating REAL NULL, text TEXT NULL, reviewed_at TEXT NULL',
        'f_channel' => 'handle TEXT NULL, followers INTEGER NULL, avatar_url TEXT NULL, is_live INTEGER NULL, verified INTEGER NULL',
        'f_file' => 'file_url TEXT NULL, mime_type TEXT NULL, size_bytes INTEGER NULL, page_count INTEGER NULL',
    ];
    foreach ($singletons as $facet => $columns) {
        $pg->statement("CREATE TABLE IF NOT EXISTS content.{$facet} (
            item_id TEXT NOT NULL,
            source_id TEXT NOT NULL,
            {$columns},
            updated_at TEXT NOT NULL,
            PRIMARY KEY (item_id, source_id)
        )");
    }

    // Slice 6: source-level aggregates. Not in $singletons — keyed by
    // source_id alone, with no item_id, so it does not fit that loop's shape.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.source_stats (
        source_id TEXT NOT NULL PRIMARY KEY,
        rating_avg REAL NULL,
        rating_count INTEGER NULL,
        summary_text TEXT NULL,
        updated_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.media_assets (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        fingerprint TEXT NOT NULL,
        source_url TEXT NULL,
        storage_path TEXT NULL,
        site_media_id TEXT NULL,
        mime_type TEXT NULL,
        width INTEGER NULL,
        height INTEGER NULL,
        dims_confidence TEXT NULL CHECK (dims_confidence IS NULL OR dims_confidence IN (\'measured\', \'declared\', \'guessed\')),
        palette TEXT NULL,
        variant_family TEXT NULL CHECK (variant_family IS NULL OR variant_family IN (\'google\', \'shopify\', \'ytimg\', \'native\', \'proxy\')),
        blurhash TEXT NULL,
        attribution TEXT NULL,
        mirror_eligible INTEGER NULL,
        mirror_attempts INTEGER NOT NULL DEFAULT 0,
        mirror_last_attempt_at TEXT NULL,
        mirror_last_reason TEXT NULL,
        created_at TEXT NOT NULL,
        UNIQUE (user_id, fingerprint)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_media (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NOT NULL,
        source_item_id TEXT NULL,
        asset_id TEXT NULL,
        role TEXT NOT NULL CHECK (role IN (\'cover\',\'gallery\',\'poster\',\'avatar\',\'logo\',\'video\')),
        position INTEGER NOT NULL DEFAULT 0,
        alt_text TEXT NULL,
        created_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.offers (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NOT NULL,
        source_item_id TEXT NULL,
        channel TEXT NULL,
        variant_label TEXT NULL,
        amount_minor INTEGER NULL,
        currency TEXT NULL,
        qualifier TEXT NOT NULL DEFAULT \'exact\' CHECK (qualifier IN (\'exact\', \'from\', \'upto\', \'range\', \'free\', \'variable\', \'on_request\')),
        amount_max_minor INTEGER NULL,
        url TEXT NULL,
        availability TEXT NULL,
        platform TEXT NULL,
        item_url TEXT NULL,
        external_ref TEXT NULL,
        updated_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_tags (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NOT NULL,
        source_item_id TEXT NULL,
        tag TEXT NOT NULL,
        tag_type TEXT NULL
    )');

    // image_url: slice 5a Task 8 fix round 2, D1
    // (supabase/migrations/20260813100003_item_variants_image_url.sql — the
    // coordinator applies that to dev; this stand-in mirrors it for the SQLite
    // lane, which never runs real migrations).
    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_variants (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NOT NULL,
        source_item_id TEXT NULL,
        label TEXT NOT NULL,
        sku TEXT NULL,
        image_url TEXT NULL,
        position INTEGER NOT NULL DEFAULT 0
    )');

    // Hand-saved cross-platform links (migration 20260805090000).
    // Public URL slugs for content items. PoolResolver::itemPayloads() reads
    // this on EVERY pool resolve (it serves slug/aliases onto the public
    // payload), so it belongs in the base content set rather than the
    // curation-only one it started in.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_slugs (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        slug TEXT NOT NULL,
        is_current INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        retired_at TEXT NULL,
        UNIQUE (user_id, slug)
    )');

    // Mirrors idx_content_item_slugs_one_current (20260812040000): one CURRENT
    // slug per item. Without it a retire-then-promote written in the wrong
    // order leaves two current slugs and the suite stays green while the
    // item's public URL flips between them run to run.
    try {
        $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS content.idx_content_item_slugs_one_current
            ON item_slugs (item_id) WHERE is_current');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS content.item_links (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        platform TEXT NOT NULL,
        url TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (item_id, platform)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.f_action (
        id TEXT PRIMARY KEY NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NOT NULL,
        intent TEXT NOT NULL,
        label TEXT NULL,
        url TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0
    )');

    // external_ref/removed_at: slice 3b Task 1 (migration
    // 20260813090000_slice3b_collections_keys_and_selection_ref.sql) — a
    // natural key for machine-derived collections plus an owner soft-delete.
    // The unique index mirrors collections_user_kind_external_ref_uq: Task
    // 5's upsert() targets ['user_id','kind','external_ref'] via Laravel's
    // upsert(), which SQLite renders as ON CONFLICT (user_id, kind,
    // external_ref) too, and SQLite rejects an ON CONFLICT target with no
    // matching unique index. Without this index here, Task 5's SQLite-lane
    // tests fail for a reason that has nothing to do with Task 5's code.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.collections (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        parent_id TEXT NULL,
        label TEXT NOT NULL,
        kind TEXT NULL,
        external_ref TEXT NULL,
        removed_at TEXT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        is_user_created INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    // IF NOT EXISTS already covers the one benign case (re-running this
    // setup against an already-provisioned schema); anything else — wrong
    // schema qualification, wrong table name, a syntax SQLite rejects —
    // must throw, not be swallowed, or the index silently fails to exist
    // and Task 5's ON CONFLICT either errors confusingly or (worse) inserts
    // duplicates, which is the exact failure mode this index prevents.
    $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS content.collections_user_kind_external_ref_uq
        ON collections (user_id, kind, external_ref)');

    $pg->statement('CREATE TABLE IF NOT EXISTS content.collection_items (
        collection_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        source_id TEXT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (collection_id, item_id)
    )');

    // Slice 5a §3.1 (migration 20260813100000): 1:1 sidecar on a
    // content.collections row of kind='storefront'. external_ref (migration
    // 20260813100001, fix round 1): the provider's own store id
    // (site.shop_brands.brand_id) — the stable identity upsertStore() keys
    // its lookup on, since the collection's label (the store's display name)
    // is user-editable and must never be the key.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.storefronts (
        collection_id TEXT PRIMARY KEY NOT NULL,
        provider TEXT NOT NULL,
        url TEXT NULL,
        source_url TEXT NULL,
        currency TEXT NULL,
        discount_code TEXT NULL,
        referral_query TEXT NOT NULL DEFAULT \'\',
        is_individual INTEGER NOT NULL DEFAULT 0,
        fetch_mode TEXT NULL,
        connect_status TEXT NULL,
        connect_error TEXT NULL,
        products_curated_at TEXT NULL,
        products_autoselected_at TEXT NULL,
        logo_url TEXT NULL,
        favicon_url TEXT NULL,
        logo_mark_url TEXT NULL,
        logo_mark_svg_url TEXT NULL,
        external_ref TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        -- Re-home Task 11 (20260819000100): the owner is denormalised here so
        -- store identity (user_id, provider, external_ref) is enforceable in
        -- one table. NULLABLE in this stand-in where production is NOT NULL —
        -- SQLite cannot express the partial unique index that makes it
        -- meaningful either, so the real constraint is pinned in the PG lane
        -- (tests/Postgres/ShopStorefrontUpsertConflictTest.php). Tightening it
        -- here would only fail fixtures over a rule this engine cannot enforce.
        user_id TEXT NULL
    )');
}

function setupIngestTables(): void
{
    attachTestSchemas();
    $pg = DB::connection('pgsql');

    try {
        $pg->statement("ATTACH DATABASE ':memory:' AS ingest");
    } catch (Throwable $e) {
        // already attached — ignore
    }

    if ($pg->getDriverName() === 'sqlite') {
        $pg->getPdo()->sqliteCreateFunction('now', fn () => now()->toDateTimeString(), 0);
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.sources (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        connection_id TEXT NULL,
        source_key TEXT NOT NULL,
        surface_key TEXT NOT NULL,
        identifier TEXT NOT NULL,
        cost_units INTEGER NOT NULL DEFAULT 1,
        min_interval_secs INTEGER NOT NULL DEFAULT 3600,
        max_interval_secs INTEGER NOT NULL DEFAULT 604800,
        change_rate REAL NOT NULL DEFAULT 0.5,
        next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_run_at TEXT NULL,
        visibility REAL NOT NULL DEFAULT 1.0,
        in_flight_since TEXT NULL,
        in_flight_run_id TEXT NULL,
        health TEXT NOT NULL DEFAULT \'ok\' CHECK (health IN (\'ok\',\'degraded\',\'unavailable\',\'shape\',\'suppressed\',\'dead\')),
        consecutive_failures INTEGER NOT NULL DEFAULT 0,
        auto_sync INTEGER NOT NULL DEFAULT 1,
        needs_eager_run INTEGER NOT NULL DEFAULT 0,
        scope TEXT NOT NULL DEFAULT \'all\' CHECK (scope IN (\'all\',\'latest_n\')),
        scope_n INTEGER NULL,
        selection_ref TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (connection_id, source_key)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.streams (
        id TEXT PRIMARY KEY NOT NULL,
        source_id TEXT NOT NULL,
        stream_name TEXT NOT NULL,
        cursor TEXT NULL,
        coverage TEXT NULL,
        observed_schema TEXT NOT NULL DEFAULT \'{}\',
        schema_hash TEXT NULL,
        health TEXT NOT NULL DEFAULT \'ok\' CHECK (health IN (\'ok\',\'degraded\',\'unavailable\',\'shape\',\'suppressed\')),
        consecutive_failures INTEGER NOT NULL DEFAULT 0,
        suppressed_until TEXT NULL,
        guard_tripped_at TEXT NULL,
        run_seq INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (source_id, stream_name)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.runs (
        id TEXT PRIMARY KEY NOT NULL,
        source_id TEXT NOT NULL,
        trigger TEXT NOT NULL CHECK (trigger IN (\'schedule\',\'manual\',\'connect\',\'backfill\',\'reproject\')),
        started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at TEXT NULL,
        outcome TEXT NULL CHECK (outcome IS NULL OR outcome IN (\'ok\',\'not_modified\',\'unavailable\',\'shape\',\'degraded\',\'budget_skipped\',\'deferred\',\'error\')),
        error_class TEXT NULL,
        records_seen INTEGER NOT NULL DEFAULT 0,
        records_changed INTEGER NOT NULL DEFAULT 0,
        records_tombstoned INTEGER NOT NULL DEFAULT 0,
        effects_count INTEGER NOT NULL DEFAULT 0,
        cost_claimed INTEGER NOT NULL DEFAULT 0,
        detail TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    // Single plain table (no partitioning) — the UNIQUE index below is the
    // load-bearing bit: it is what makes insertOrIgnore's "unchanged content
    // writes nothing" property hold, mirroring idx_record_versions_content.
    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.record_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stream_id TEXT NOT NULL,
        key TEXT NOT NULL,
        doc_hash TEXT NOT NULL,
        doc TEXT NOT NULL,
        first_seen_run TEXT NULL,
        first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_current INTEGER NOT NULL DEFAULT 1,
        UNIQUE (stream_id, key, doc_hash)
    )');
    try {
        $pg->statement('CREATE INDEX IF NOT EXISTS ingest.idx_record_versions_current ON record_versions (stream_id, key) WHERE is_current');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.record_state (
        stream_id TEXT NOT NULL,
        key TEXT NOT NULL,
        current_version_id INTEGER NULL,
        last_seen_run TEXT NULL,
        last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        absent_since TEXT NULL,
        absent_runs INTEGER NOT NULL DEFAULT 0,
        tombstoned_at TEXT NULL,
        PRIMARY KEY (stream_id, key)
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.effects (
        digest TEXT PRIMARY KEY,
        run_id TEXT NULL,
        source_id TEXT NULL,
        kind TEXT NOT NULL CHECK (kind IN (\'http\', \'actor\', \'api\', \'ai\')),
        cost_tag TEXT NULL,
        cost_units INTEGER NOT NULL DEFAULT 0,
        claimed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        settled_at TEXT NULL,
        status TEXT NOT NULL DEFAULT \'claimed\' CHECK (status IN (\'claimed\',\'ok\',\'failed\',\'refused\',\'abandoned\')),
        body_ref TEXT NULL,
        meta TEXT NOT NULL DEFAULT \'{}\'
    )');

    // 7-value domain per supabase/migrations/20260729150004_anomalies_kind_check_not_valid.sql
    // — includes 'effect_abandoned' (EffectLedger::markAbandoned()), which the
    // stale column comment on the ORIGINAL 20260727130000 migration omitted.
    $pg->statement('CREATE TABLE IF NOT EXISTS ingest.anomalies (
        id TEXT PRIMARY KEY NOT NULL,
        stream_id TEXT NULL,
        source_id TEXT NULL,
        run_id TEXT NULL,
        kind TEXT NOT NULL CHECK (kind IN (
            \'delete_guard\', \'shape\', \'drift\', \'stranded\', \'schema\', \'projection\',
            \'effect_abandoned\'
        )),
        severity TEXT NOT NULL DEFAULT \'warning\' CHECK (severity IN (\'info\',\'warning\',\'critical\')),
        summary TEXT NOT NULL,
        detail TEXT NOT NULL DEFAULT \'{}\',
        detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at TEXT NULL,
        resolved_by TEXT NULL,
        resolution TEXT NULL
    )');
}

/**
 * site.site_subdomain_aliases — minimal columns for cache-invalidation paths
 * that iterate over historical aliases for a site.
 */
/**
 * routing schema (migration 20260727120000) — SQLite mirror. The real table
 * is RANGE-partitioned by observed_at; SQLite has no partitioning, so this is
 * a single table with the same columns. Partition routing itself is covered
 * by the Postgres lane, not here.
 */
/**
 * catalog runtime tables (migration 20260727100000) — SQLite mirror.
 *
 * "Runtime" as opposed to the compiled half (brands/surfaces/detectors/…),
 * which `catalog:sync` upserts from the artefact and which no test needs a
 * table for because CompiledCatalog reads the PHP artefact directly. These two
 * are the ACCUMULATED half — written by the router, never by the compiler, and
 * not re-derivable from a recompile.
 *
 * Column types follow the SQLite mirror convention used throughout this file
 * (timestamps as TEXT); the NOT NULLs and the primary keys are copied verbatim
 * from the migration, because those are what a write path can actually
 * violate.
 */
function setupCatalogRuntimeTables(): void
{
    attachTestSchemas();
    $pg = DB::connection('pgsql');

    // supabase/migrations/20260727100000_catalog_schema.sql:132-138.
    // No FK to catalog.detectors, deliberately — a suspension must outlive the
    // detector being recompiled or tombstoned.
    $pg->statement('CREATE TABLE IF NOT EXISTS catalog.detector_suspensions (
        detector_id TEXT PRIMARY KEY NOT NULL,
        reason TEXT NOT NULL,
        set_by TEXT NULL,
        set_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    )');

    // supabase/migrations/20260727100000_catalog_schema.sql:143-152.
    $pg->statement('CREATE TABLE IF NOT EXISTS catalog.unmatched_domains (
        registrable_key TEXT PRIMARY KEY NOT NULL,
        sample_path_shape TEXT NULL,
        hits INTEGER NOT NULL DEFAULT 1,
        has_detectors INTEGER NOT NULL DEFAULT 0,
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        triaged_at TEXT NULL
    )');
}

function setupRoutingTables(): void
{
    attachTestSchemas();
    $pg = DB::connection('pgsql');

    // CHECKs copied verbatim from
    // supabase/migrations/20260727120000_routing_schema.sql:14-30 (#PARITY-1 Tier 2).
    $pg->statement('CREATE TABLE IF NOT EXISTS routing.link_observations (
        id TEXT NOT NULL,
        user_id TEXT NULL,
        observed_at TEXT NOT NULL,
        -- \'commerce_probe\' added 2026-08-18 to match migration
        -- 20260819001000 (X3). The stand-in had faithfully reproduced the very
        -- asymmetry that migration exists to fix: source_intents.origin below
        -- already listed it, link_observations.source did not, so every probe
        -- observation failed the CHECK and was silently dropped.
        source TEXT NOT NULL CHECK (source IN (\'paste\', \'website_import\', \'link_in_bio\', \'bio_harvest\', \'google_business\', \'staff\', \'reproject\', \'commerce_probe\')),
        import_run_id TEXT NULL,
        raw_url TEXT NOT NULL,
        canonical_url TEXT NULL,
        registrable_key TEXT NULL,
        evidence TEXT NOT NULL DEFAULT \'{}\',
        detector_id TEXT NULL,
        surface_key TEXT NULL,
        confidence INTEGER NULL CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100)),
        margin INTEGER NULL,
        verdict TEXT NULL CHECK (verdict IS NULL OR verdict IN (\'place\', \'choose\', \'note\', \'hold\', \'reject\')),
        block_reason TEXT NULL,
        catalog_digest TEXT NULL,
        PRIMARY KEY (id, observed_at)
    )');

    // CHECKs copied verbatim from
    // supabase/migrations/20260727120000_routing_schema.sql:58-69 (#PARITY-1).
    $pg->statement('CREATE TABLE IF NOT EXISTS routing.source_intents (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        surface_key TEXT NOT NULL,
        routing_class TEXT NOT NULL,
        identifier TEXT NOT NULL,
        identifier_label TEXT NULL,
        canonical_url TEXT NULL,
        state TEXT NOT NULL DEFAULT \'proposed\' CHECK (state IN (\'proposed\', \'applied\', \'blocked\', \'dismissed\', \'superseded\')),
        block_reason TEXT NULL CHECK (block_reason IS NULL OR block_reason IN (
            \'gate\', \'capability\', \'conflict\', \'cap_reached\', \'below_threshold\',
            \'tombstoned\', \'unservable\', \'invalid_identifier\', \'duplicate\'
        )),
        conflicting_connection_id TEXT NULL,
        connection_id TEXT NULL,
        confidence INTEGER NULL CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100)),
        origin TEXT NOT NULL CHECK (origin IN (\'paste\', \'website_import\', \'link_in_bio\', \'bio_harvest\', \'google_business\', \'staff\', \'reproject\', \'commerce_probe\')),
        import_run_id TEXT NULL,
        detector_id TEXT NULL,
        catalog_digest TEXT NULL,
        first_seen_at TEXT NOT NULL,
        resolved_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    try {
        $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS routing.idx_source_intents_live
            ON source_intents (user_id, surface_key, identifier)
            WHERE state IN (\'proposed\', \'applied\', \'blocked\')');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS routing.item_tombstones (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        source_ref TEXT NOT NULL,
        scope TEXT NOT NULL DEFAULT \'this_source\' CHECK (scope IN (\'this_source\', \'any_source\')),
        reason TEXT NULL,
        created_at TEXT NOT NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS routing.import_runs (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        kind TEXT NOT NULL CHECK (kind IN (\'website\', \'link_in_bio\', \'bio_harvest\', \'menu_scan\')),
        source_url TEXT NULL,
        started_at TEXT NOT NULL,
        finished_at TEXT NULL,
        outcome TEXT NULL CHECK (outcome IS NULL OR outcome IN (\'ok\', \'partial\', \'unavailable\', \'error\', \'cooldown\', \'budget\')),
        observations_count INTEGER NOT NULL DEFAULT 0,
        intents_count INTEGER NOT NULL DEFAULT 0,
        items_count INTEGER NOT NULL DEFAULT 0,
        error_class TEXT NULL,
        detail TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NOT NULL
    )');
}

function setupSubdomainAliasesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        subdomain TEXT NULL,
        reclaim_until TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * site.enquiries — visitor PII from contact-form submissions.
 */
function setupEnquiriesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        customer_id TEXT NULL,
        name TEXT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        subject TEXT NULL,
        message TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        read_at TEXT NULL,
        email_sent_at TEXT NULL,
        confirmation_sent_at TEXT NULL,
        notifications_pending_since TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Insert an Enquiry row for $pro's site and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createEnquiryFor(User $pro, array $overrides = []): Enquiry
{
    setupEnquiriesTable();
    setupSitesTable();

    $site = $pro->relationLoaded('site') ? $pro->site : $pro->load('site')->site;
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'site_id' => $site->id,
        'name' => 'Test Visitor',
        'email' => 'visitor@example.test',
        'subject' => 'Test enquiry',
        'message' => 'Hello from a test visitor.',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.enquiries')->insert($row);

    return Enquiry::withTrashed()->findOrFail($id);
}

/**
 * notifications.broadcast_email_receipts — dedup sentinel for broadcast emails.
 * PK (notification_id, subscription_id) is the idempotency guard.
 */
function setupBroadcastEmailReceiptsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.broadcast_email_receipts (
        notification_id TEXT NOT NULL,
        subscription_id TEXT NOT NULL,
        email_sent_at TEXT NULL,
        PRIMARY KEY (notification_id, subscription_id)
    )');
}

/**
 * notifications.notification_email_policies — per-pro and global send-mode overrides.
 * Empty in most tests; default behaviour (no rows) resolves to enabled.
 */
function setupNotificationEmailPoliciesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_key TEXT NULL,
        mode TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * notifications.notification_email_preferences — per-pro category opt-outs.
 * Empty in most tests; default behaviour (no rows) resolves to enabled.
 */
function setupNotificationEmailPreferencesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_key TEXT NULL,
        enabled INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * audit.auth_factor_events — append-only MFA audit log.
 * Mirrors the production schema closely enough for hook + brute-force tests.
 */
function setupAuthFactorEventsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.auth_factor_events (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        session_id TEXT NULL,
        event_type TEXT NOT NULL,
        factor_id TEXT NULL,
        factor_type TEXT NULL,
        ip TEXT NULL,
        user_agent TEXT NULL,
        metadata TEXT NULL DEFAULT \'{}\',
        webhook_id TEXT NULL,
        created_at TEXT NULL
    )');

    // WHK-101 — durable dedup backstop to the Redis-only idempotency anchor.
    // SQLite's CREATE INDEX grammar puts the schema qualifier on the INDEX
    // name (not the table name), same quirk as idx_platform_connections_canonical
    // above. SQLite supports partial unique indexes with the same
    // NULLs-are-distinct semantics as Postgres, so this faithfully mirrors
    // the production auth_factor_events_webhook_id_uk index.
    try {
        DB::connection('pgsql')->statement('CREATE UNIQUE INDEX IF NOT EXISTS audit.auth_factor_events_webhook_id_uk
            ON auth_factor_events (webhook_id)
            WHERE webhook_id IS NOT NULL');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }
}

function setupHandleAliasesTable(): void
{
    attachTestSchemas();
    // handle_lc included: this helper now also runs via setupUsersTable's
    // ride-along, and CREATE IF NOT EXISTS means whichever runs first wins —
    // so this shared shape must be the SUPERSET of every lane's needs
    // (BootstrapHandleAliasUniquenessTest inserts handle_lc).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        reclaim_until TEXT NULL,
        expires_at TEXT NULL,
        notified_t3_at TEXT NULL,
        notified_t1_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * audit.handle_change_log — append-only audit log for handle/subdomain renames.
 * In production, UPDATE/DELETE are blocked by a DB trigger. In SQLite tests,
 * plain INSERT works fine — the trigger constraint is absent, which is correct.
 */
function setupHandleChangeLogTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.handle_change_log (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        old_handle TEXT NULL,
        new_handle TEXT NULL,
        reason TEXT NULL,
        actor_id TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        changed_at TEXT NULL
    )');
}

/*
|--------------------------------------------------------------------------
| Bot Protection Test Helpers
|--------------------------------------------------------------------------
| has_auth_middleware: used by BotProtectionCoverageTest to skip auth-
|   protected routes when sweeping for missing bot.token middleware.
|
| The FakeProvider beforeEach hook binds a fresh instance per test so
| scripted results don't bleed between tests.
*/

function has_auth_middleware($route): bool
{
    return collect($route->gatherMiddleware())->some(fn ($m) => $m === 'supabase.jwt'
        || $m === 'professional.api'
        || str_starts_with((string) $m, 'professional.api')
        || $m === 'staff'
        || $m === 'staff.admin'
        || str_starts_with((string) $m, 'auth:')
    );
}

uses()->beforeEach(function () {
    config(['partna.bot_protection.driver' => 'fake']);
    app()->instance(
        FakeProvider::class,
        new FakeProvider,
    );
})->in('Feature/Http/Middleware', 'Feature/PublicSite', 'Feature/Security');

/*
|--------------------------------------------------------------------------
| Moderation schema SQLite helpers (Trust & Safety Foundation)
|--------------------------------------------------------------------------
|
| The moderation schema runs on PostgreSQL in production. For SQLite-based
| tests we ATTACH DATABASE 'moderation' and create lightweight table stubs.
| All columns are nullable TEXT so factory insertions work without type coercion.
|
| Postgres-group tests (CHECK constraints, UNIQUE indexes, EXPLAIN plans)
| still target real PostgreSQL; these helpers are for the SQLite test path only.
|
*/

function setupModerationCasesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS moderation.cases (
        id TEXT PRIMARY KEY,
        case_type TEXT NOT NULL DEFAULT \'content_report\',
        reportable_type TEXT NOT NULL DEFAULT \'Site\',
        reportable_id TEXT NOT NULL,
        reportable_owner_user_id TEXT NULL,
        severity INTEGER NOT NULL DEFAULT 2,
        status TEXT NOT NULL DEFAULT \'open\',
        signal_count INTEGER NOT NULL DEFAULT 1,
        auto_actioned INTEGER NOT NULL DEFAULT 0,
        priority INTEGER NOT NULL DEFAULT 5,
        sla_due_at TEXT NULL,
        triaged_at TEXT NULL,
        triaged_by_staff_id TEXT NULL,
        resolved_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

function setupModerationCaseSignalsTable(): void
{
    attachTestSchemas();
    setupModerationCasesTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS moderation.case_signals (
        id TEXT PRIMARY KEY,
        case_id TEXT NOT NULL,
        signal_source TEXT NOT NULL DEFAULT \'content_report\',
        signal_data TEXT NOT NULL DEFAULT \'[]\',
        reporter_user_id TEXT NULL,
        reporter_email TEXT NULL,
        reporter_ip_hash TEXT NULL,
        reason_code TEXT NOT NULL DEFAULT \'spam\',
        reason_details TEXT NULL,
        dedup_hash TEXT NOT NULL,
        created_at TEXT NULL
    )');
}

function setupModerationEvidenceTable(): void
{
    attachTestSchemas();
    setupModerationCasesTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS moderation.evidence (
        id TEXT PRIMARY KEY,
        case_id TEXT NOT NULL,
        signal_id TEXT NULL,
        evidence_type TEXT NOT NULL DEFAULT \'content_snapshot\',
        payload TEXT NOT NULL DEFAULT \'[]\',
        content_hash TEXT NULL,
        captured_at TEXT NULL
    )');
}

function setupModerationDecisionsTable(): void
{
    attachTestSchemas();
    setupModerationCasesTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS moderation.decisions (
        id TEXT PRIMARY KEY,
        case_id TEXT NOT NULL,
        decision_type TEXT NOT NULL DEFAULT \'dismiss\',
        reason TEXT NULL,
        decided_by_staff_id TEXT NULL,
        decided_by_system INTEGER NOT NULL DEFAULT 0,
        auto_actioned INTEGER NOT NULL DEFAULT 0,
        supersedes_decision_id TEXT NULL,
        second_staff_approval_id TEXT NULL,
        second_staff_approved_at TEXT NULL,
        decided_at TEXT NULL
    )');
}

function setupModerationActionLogTable(): void
{
    attachTestSchemas();
    setupModerationDecisionsTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS moderation.action_log (
        id TEXT PRIMARY KEY,
        decision_id TEXT NOT NULL,
        action_type TEXT NOT NULL DEFAULT \'notify_reporter\',
        action_target TEXT NOT NULL DEFAULT \'[]\',
        job_uuid TEXT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        attempts INTEGER NOT NULL DEFAULT 0,
        failure_reason TEXT NULL,
        dispatched_at TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

function setupAuditModerationEventsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.moderation_events (
        id TEXT PRIMARY KEY,
        actor_kind TEXT NOT NULL DEFAULT \'system\',
        actor_staff_id TEXT NULL,
        action TEXT NOT NULL,
        target_type TEXT NULL,
        target_id TEXT NULL,
        payload TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT NULL
    )');
}

/**
 * Setup all moderation tables at once (convenience for integration tests).
 */
function setupAllModerationTables(): void
{
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
    setupModerationEvidenceTable();
    setupModerationDecisionsTable();
    setupModerationActionLogTable();
    setupAuditModerationEventsTable();
}

/**
 * Alias for setupMediaTables() — ensures site.site_media exists.
 * Named to match the "setup*" pattern used in CSAM webhook tests.
 */
function setupSiteMediaTable(): void
{
    setupMediaTables();
}

/**
 * audit.data_export_audit — export lifecycle + artifact tracking.
 * Mirrors DataExportAudit::$fillable + the cast columns needed by the prune command.
 * All columns nullable; no CHECK constraints (SQLite can't enforce them).
 */
function setupDataExportAuditTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.data_export_audit (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        professional_handle_snapshot TEXT NULL,
        professional_email_snapshot TEXT NULL,
        triggered_by TEXT NULL,
        triggered_by_staff_id TEXT NULL,
        recipient_email TEXT NULL,
        send_to TEXT NULL,
        status TEXT NOT NULL DEFAULT \'queued\',
        file_path TEXT NULL,
        file_size_bytes INTEGER NULL,
        file_sha256 TEXT NULL,
        record_counts TEXT NULL,
        error_message TEXT NULL,
        email_sent_at TEXT NULL,
        email_delivery_status TEXT NULL,
        created_at TEXT NULL,
        completed_at TEXT NULL
    )');
}

/**
 * Insert a DataExportAudit row and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createDataExportAudit(array $overrides = []): DataExportAudit
{
    setupDataExportAuditTable();

    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'status' => DataExportAudit::STATUS_COMPLETED,
        'created_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('audit.data_export_audit')->insert($row);

    return DataExportAudit::query()->findOrFail($id);
}

/**
 * site.design_kits — per-site design kit (1:1 with site.sites).
 *
 * REGENERATED 2026-08-09 against the post-migration truth. This mirror is the
 * whole table now, not a subset: the preset-only migration
 * (20260809090001) took it from 57 value columns to 8, so there is no longer
 * anything to leave out. It had also gone stale — it still created
 * border_style, typography_uppercase, typography_tracking, motion_pace and
 * theme_contrast, all dropped from Postgres by 20260806090001.
 *
 * Three of the five are SELECTIONS with a fixed vocabulary
 * (DesignKitValidationRules::designKitRules() is the authority):
 *   text_size         small | medium | large
 *   spacing           default | spacious
 *   corners           default | rounded
 * (border_thickness, theme_mode and theme_night_shift_auto dropped
 * 2026-08-27, plan 02 — migration 20260827080000.)
 * No CHECK constraint backs them in Postgres, so none is mirrored here.
 *
 * Every column stays NULLABLE — null means "use the package default", and the
 * production trigger trg_create_empty_design_kit inserts `(site_id)` alone.
 * That trigger is absent in SQLite: tests that need a kit row insert one.
 */
function setupDesignKitsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
        site_id TEXT PRIMARY KEY NOT NULL,
        color_accent TEXT NULL,
        typography_font_family TEXT NULL,
        text_size TEXT NULL,
        spacing TEXT NULL,
        corners TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
}

/**
 * WHK-3: core.supabase_email_events — forensic trail for auth-email webhook outcomes.
 * All columns nullable (SQLite permissiveness); raw_payload stored as TEXT.
 * Mirrors columns from migration 20260625000000.
 */
function setupSupabaseEmailEventsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement("CREATE TABLE IF NOT EXISTS core.supabase_email_events (
        id TEXT PRIMARY KEY,
        webhook_id TEXT NOT NULL UNIQUE,
        request_id TEXT NULL,
        action_type TEXT NOT NULL,
        recipient_email_hash TEXT NULL,
        raw_payload TEXT NOT NULL DEFAULT '{}',
        status TEXT NOT NULL DEFAULT 'queued',
        error TEXT NULL,
        queued_at TEXT NULL,
        failed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");
}

/**
 * core.email_suppressions — send-time suppression list (Resend bounce/complaint).
 * Keeps the UNIQUE(email_hash) + reason CHECK from migration 20260721190000 so
 * the idempotency-upsert and constraint tests exercise real behaviour (SQLite
 * enforces both). email_hash stored as TEXT; timestamps as TEXT.
 */
function setupEmailSuppressionsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement("CREATE TABLE IF NOT EXISTS core.email_suppressions (
        id TEXT PRIMARY KEY,
        email_hash TEXT NOT NULL UNIQUE,
        reason TEXT NOT NULL CHECK (reason IN ('hard_bounce','complaint','manual')),
        source TEXT NULL,
        detail TEXT NULL,
        first_seen_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");
}

/**
 * The rest of the curation surface (migrations 20260727140000 / 20260727150000)
 * — SQLite mirror layered on top of setupSectionsTables().
 *
 * Split from that helper rather than folded into it because it serves the
 * document builder, which needs items/pages/sections and nothing else. What
 * follows is the identity and override storage the curation API writes; the
 * UNIQUE constraints are the load-bearing part, since every endpoint here has
 * upsert semantics that depend on them.
 */
function setupContentCurationTables(): void
{
    // setupContentTables() already declares every content.* table this
    // function used to duplicate (sources, source_items, identity_decisions,
    // identity_candidates, item_anchors, item_merges, manual_overrides,
    // collections, collection_items) — and does so WITH the CHECK
    // constraints those duplicate copies lacked (#PARITY-1 §2: the curation
    // lane was silently running against unchecked stand-ins). It also calls
    // setupSectionsTables(), so no coverage is lost by switching the base
    // call. section_groups below is the only table genuinely unique to the
    // curation lane now — content.item_slugs moved into setupContentTables()
    // when PoolResolver started serving slugs from it on every pool read
    // (slice 2 Task 9), so it is no longer curation-only and is provisioned by
    // the base call above. This function's own contract is unchanged.
    setupContentTables();
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE TABLE IF NOT EXISTS site.section_groups (
        id TEXT PRIMARY KEY NOT NULL,
        section_id TEXT NOT NULL,
        group_key TEXT NOT NULL,
        label TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_hidden INTEGER NOT NULL DEFAULT 0,
        UNIQUE (section_id, group_key)
    )');
}

/**
 * One content.items row for a tenant, with sane defaults. Returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedContentItem(string $userId, array $overrides = []): string
{
    $id = $overrides['id'] ?? (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('content.items')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'kind' => 'video',
        'headline_cache' => 'An item',
        'facets_cache' => '[]',
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return (string) $id;
}

/**
 * A page plus one section on a tenant's site.
 *
 * @param  array<string, mixed>  $sectionOverrides
 * @return array{0: string, 1: string} [pageId, sectionId]
 */
function seedPageWithSection(string $siteId, array $sectionOverrides = []): array
{
    $pageId = (string) Str::uuid();
    $sectionId = $sectionOverrides['id'] ?? (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.pages')->insert([
        'id' => $pageId,
        'site_id' => $siteId,
        'key' => 'work-'.substr($pageId, 0, 8),
        'label' => 'Work',
        'sort_order' => 0,
        'order_mode' => 'manual',
        'is_hidden' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sections')->insert(array_merge([
        'id' => $sectionId,
        'page_id' => $pageId,
        'site_id' => $siteId,
        'kind' => 'collection',
        'label' => 'Everything',
        'sort_order' => 0,
        'rule' => '{}',
        'mode' => 'automatic',
        'order_by' => 'recency',
        'render' => 'cards',
        'min_items' => 1,
        'on_empty' => 'hide',
        'stale_display' => 'inherit',
        'created_at' => $now,
        'updated_at' => $now,
    ], $sectionOverrides));

    return [$pageId, (string) $sectionId];
}

/**
 * site.design_kit_restyles (migration 20260727150000) — SQLite mirror for the
 * §13 "Restyle from brand" undo snapshots.
 */
function setupDesignKitRestylesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kit_restyles (
        id TEXT PRIMARY KEY NOT NULL,
        site_id TEXT NOT NULL,
        snapshot TEXT NOT NULL,
        applied_at TEXT NOT NULL,
        undone_at TEXT NULL
    )');
}

/**
 * content.brand_asset_refs (migration 20260728130000) — SQLite mirror for the
 * store-brand plane: owned-asset refs per (connection, role). media_assets
 * comes from setupContentTables(), which this calls first.
 */
function setupBrandAssetRefsTable(): void
{
    setupContentTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS content.brand_asset_refs (
        id TEXT PRIMARY KEY NOT NULL,
        connection_id TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN (\'logo_square\', \'logo_full\', \'favicon\')),
        asset_id TEXT NULL,
        source_url TEXT NULL,
        attribution TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE (connection_id, role)
    )');
}
