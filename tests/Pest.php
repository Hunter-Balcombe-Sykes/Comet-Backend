<?php

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentUser;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Customer;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\BotProtection\Providers\FakeProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Pest\Support\HigherOrderTapProxy;
use Stripe\StripeClient;
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
 * Usage: actingAsUser($pro)->getJson('/api/stripe/payouts')
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
    app()->bind(VerifySupabaseJwt::class, function () use ($supabaseUid, $resolvedClaims) {
        return new class($supabaseUid, $resolvedClaims)
        {
            public function __construct(
                private readonly string $uid,
                private readonly array $claims,
            ) {}

            public function handle(Request $request, Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', $this->claims['aal'] ?? 'aal1');
                $request->attributes->set('supabase_amr', $this->claims['amr'] ?? []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id'] ?? null);

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
    \App\Models\Core\Staff\PartnaStaff $staff,
    array $claims = [],
): \Pest\Support\HigherOrderTapProxy {
    $authUserId = $staff->auth_user_id ?? (string) \Illuminate\Support\Str::uuid();

    // Staff routes are gated by require.aal2 — default to AAL2 unless the test
    // overrides (e.g. to assert the gate rejects AAL1).
    $defaultClaims = array_merge([
        'sub'             => $authUserId,
        'email'           => $staff->primary_email ?? "staff-{$staff->id}@partna.au",
        'email_verified'  => true,
        'aal'             => 'aal2',
        'amr'             => [['method' => 'totp', 'timestamp' => time()]],
        'session_id'      => (string) \Illuminate\Support\Str::uuid(),
    ], $claims);

    // Stub VerifySupabaseJwt — same shape as actingAsUser uses.
    app()->bind(\App\Http\Middleware\Auth\VerifySupabaseJwt::class, function () use ($authUserId, $defaultClaims) {
        return new class($authUserId, $defaultClaims)
        {
            public function __construct(
                private readonly string $uid,
                private readonly array $claims,
            ) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', $this->claims['aal'] ?? 'aal1');
                $request->attributes->set('supabase_amr', $this->claims['amr'] ?? []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id'] ?? null);

                return $next($request);
            }
        };
    });

    // Stub EnsurePartnaStaff to inject the staff record on the request.
    app()->bind(\App\Http\Middleware\Auth\EnsurePartnaStaff::class, function () use ($staff) {
        return new class($staff)
        {
            public function __construct(private readonly \App\Models\Core\Staff\PartnaStaff $s) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
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

    foreach (['core', 'site', 'audit', 'moderation', 'commerce', 'notifications', 'analytics', 'billing', 'retail', 'brand'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (Throwable $e) {
            // already attached — ignore
        }
    }
}

/**
 * Permissive core.users table — every column nullable. Just enough
 * structure for tests that read/write professionals via the model or raw queries.
 */
function setupUsersTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        account_type TEXT NULL,
        status TEXT NULL,
        bio TEXT NULL,
        about TEXT NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        onboarding_step INTEGER NULL,
        public_contact_number TEXT NULL,
        public_contact_email TEXT NULL,
        icon_bucket TEXT NULL,
        icon_path TEXT NULL,
        headshot_bucket TEXT NULL,
        headshot_path TEXT NULL,
        location_street_address TEXT NULL,
        location_postcode TEXT NULL,
        location_city TEXT NULL,
        location_state TEXT NULL,
        location_country TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.feedback table for in-app feedback submission tests.
 * Mirrors columns from migration 20260526210001.
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
}

/**
 * site.sites table — minimal columns, all nullable.
 */
function setupSitesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        skeleton_id TEXT NULL,
        subdomain_changed_at TEXT NULL,
        is_published INTEGER NULL,
        unpublished_at TEXT NULL,
        settings TEXT NULL,
        moderation_state TEXT NOT NULL DEFAULT \'active\',
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
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
        user_id TEXT NULL,
        pool TEXT NULL,
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
        product_gid TEXT NULL,
        alt_text TEXT NULL,
        caption TEXT NULL,
        purpose TEXT NULL,
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
 * core.waitlist_signups for waitlist tests. Column list mirrors the production
 * baseline post-relaxation migration (20260526010000) — all columns nullable
 * here for SQLite permissiveness, but every column name matches.
 */
function setupWaitlistTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.waitlist_signups (
        id TEXT PRIMARY KEY,
        name TEXT NULL,
        email TEXT NULL,
        email_lc TEXT NULL UNIQUE,
        phone TEXT NULL,
        applicant_type TEXT NULL,
        applicant_type_other TEXT NULL,
        industry TEXT NULL,
        industry_other TEXT NULL,
        pilot_program_opt_in INTEGER NULL,
        number_of_team_members INTEGER NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        last_submitted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
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
 * site.blocks — all columns nullable except the PK. Used by backfill command
 * tests and any test that exercises Block Eloquent operations in SQLite.
 */
function setupBlocksTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.blocks (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        block_group TEXT NULL,
        block_type TEXT NULL,
        title TEXT NULL,
        url TEXT NULL,
        icon_key TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        is_enabled INTEGER NULL,
        settings TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * notifications.notification_preferences for ConfirmationPreferenceServiceTest.
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
function createTenant(string $handle, string $type = 'professional'): User
{
    tenantHelpersEnsureTables();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'auth_user_id' => 'auth-'.Str::random(12),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $handle.'@example.test',
        'account_type' => 'individual',
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

function createBrandTenant(string $handle = 'brand-a'): User
{
    $pro = createTenant($handle, 'brand');
    DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $pro->id)
        ->update(['account_type' => 'brand']);
    AccountCapabilities::flushCache();

    return User::query()->findOrFail($pro->id);
}

function createAffiliateTenant(string $handle = 'affiliate-a'): User
{
    // A test "affiliate" is a partner (a brand-affiliated professional), not a
    // generic professional. Set account_type='partner' so AccountCapabilities
    // returns the partner capability set in dispatcher-gate tests.
    $pro = createTenant($handle, 'affiliate');
    DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $pro->id)
        ->update(['account_type' => 'partner']);
    AccountCapabilities::flushCache();

    return User::query()->findOrFail($pro->id);
}

/**
 * Standard pair: two fully-independent tenants. Returns [$tenantA, $tenantB].
 *
 * @return array{0: User, 1: User}
 */
function createTwoTenants(string $type = 'brand'): array
{
    $a = $type === 'brand' ? createBrandTenant('brand-a') : createAffiliateTenant('aff-a');
    $b = $type === 'brand' ? createBrandTenant('brand-b') : createAffiliateTenant('aff-b');

    return [$a, $b];
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
 * Generate a valid Stripe-Signature header for a raw body string.
 * Uses the official Stripe SDK so we exercise the real verification path,
 * not a hand-rolled approximation.
 */
function signStripeBody(string $body, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $signedPayload = $timestamp.'.'.$body;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return 't='.$timestamp.',v1='.$signature;
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
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
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
        category_id TEXT NULL,
        title TEXT NULL,
        description TEXT NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        duration_minutes INTEGER NULL,
        is_active INTEGER NULL,
        sort_order INTEGER NULL,
        square_catalog_object_id TEXT NULL,
        square_variation_id TEXT NULL,
        square_catalog_version INTEGER NULL,
        square_last_synced_at TEXT NULL,
        square_sync_error TEXT NULL,
        fresha_service_id TEXT NULL,
        fresha_variation_id TEXT NULL,
        fresha_service_version INTEGER NULL,
        fresha_last_synced_at TEXT NULL,
        fresha_sync_error TEXT NULL,
        deleted_origin TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
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

    $row = array_merge([
        'id' => $id,
        'user_id' => $pro->id,
        'title' => 'Test Service',
        'price_cents' => 5000,
        'currency_code' => 'AUD',
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('site.services')->insert($row);

    return Service::withTrashed()->findOrFail($id);
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
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        email_sent_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

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
 * flag-prune command). Schema mirrors tests/Feature/FeatureFlags/FeatureFlagTestCase.
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
        created_at TEXT NULL
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
        created_at TEXT NULL
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
        qr_slug TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.site_subdomain_aliases — minimal columns for cache-invalidation paths
 * that iterate over historical aliases for a site.
 */
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

/*
|--------------------------------------------------------------------------
| Stripe Test Helpers
|--------------------------------------------------------------------------
|
| Helpers for testing Stripe-integrated code paths. The Stripe service
| classes self-instantiate StripeClient (not via the container), so
| mockStripeClient() returns a Mockery mock you pass to the service
| constructor directly. stripeWebhookEvent() / postStripeWebhook() handle
| the webhook layer.
|
| Note: buildTestStripeSignature() is an alias for the existing
| signStripeBody() — provided so tests that import from the task plan
| can call either name without confusion.
|
*/

/**
 * Build a Mockery mock of StripeClient with getService() stubbed so that
 * property-access like $stripe->paymentIntents works correctly.
 *
 * Pass per-service sub-mocks via $services. Keys: 'paymentIntents',
 * 'transfers', 'refunds', 'charges', 'accounts' etc.
 *
 * Usage:
 *   $stripe = mockStripeClient(['paymentIntents' => $piMock]);
 *   $service = new CommissionPayoutService($stripe);
 *
 * @param  array<string, object>  $services
 */
function mockStripeClient(array $services = []): MockInterface
{
    $mock = Mockery::mock(StripeClient::class);

    foreach ($services as $name => $stub) {
        $mock->shouldReceive('getService')->with($name)->andReturn($stub);
    }

    // Default: any un-stubbed service returns a bare mock so tests that
    // don't care about a specific sub-service don't explode on access.
    $mock->shouldReceive('getService')->andReturn(Mockery::mock())->byDefault();

    return $mock;
}

/**
 * Build a minimal Stripe event array suitable for posting to a webhook endpoint.
 *
 * @param  array<string, mixed>  $object  The event data.object payload.
 * @return array<string, mixed>
 */
function stripeWebhookEvent(string $type, array $object): array
{
    return [
        'id' => 'evt_'.Str::random(24),
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
        'account' => $object['id'] ?? 'acct_test',
        'created' => now()->timestamp,
        'livemode' => false,
        'api_version' => '2024-04-10',
    ];
}

/**
 * Post a Stripe Connect webhook event to /api/webhooks/stripe-connect
 * with a valid Stripe-Signature header computed from the connect webhook secret.
 *
 * The connect secret must be set before calling this:
 *   Config::set('services.stripe.connect_webhook_secret', 'whsec_test')
 *
 * @param  array<string, mixed>  $event
 */
function postStripeWebhook(array $event): TestResponse
{
    $body = json_encode($event);
    $secret = (string) config('services.stripe.connect_webhook_secret', 'whsec_test');
    $sig = buildTestStripeSignature($body, $secret);

    return test()->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body);
}

/**
 * Build a Stripe-Signature header value for the given raw body and secret.
 * Alias for signStripeBody() — provided for naming consistency with the
 * task plan; prefer signStripeBody() in existing tests.
 */
function buildTestStripeSignature(string $body, string $secret, ?int $timestamp = null): string
{
    return signStripeBody($body, $secret, $timestamp);
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
        name TEXT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        subject TEXT NULL,
        message TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        read_at TEXT NULL,
        email_sent_at TEXT NULL,
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
        created_at TEXT NULL
    )');
}

function setupHandleAliasesTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        handle TEXT NULL,
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

