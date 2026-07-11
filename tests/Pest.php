<?php

use App\Http\Middleware\Auth\EnsurePartnaStaff;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentUser;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Staff\PartnaStaff;
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

    // Stub VerifySupabaseJwt — same shape as actingAsUser uses.
    app()->bind(VerifySupabaseJwt::class, function () use ($authUserId, $defaultClaims) {
        return new class($authUserId, $defaultClaims)
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
        country_code TEXT NULL,
        timezone TEXT NULL,
        onboarding_step INTEGER NULL,
        public_contact_number TEXT NULL,
        public_contact_email TEXT NULL,
        sector TEXT NULL,
        sector_source TEXT NULL,
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

    // Defensive ALTERs for suites that created core.users before the sector
    // columns existed (SQLite's CREATE TABLE IF NOT EXISTS won't add columns to
    // an already-created table within a run). Mirrors migration 20260705150100.
    foreach (['sector', 'sector_source'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.users ADD COLUMN {$col} TEXT NULL");
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
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
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        architecture_id TEXT NULL,
        subdomain_changed_at TEXT NULL,
        is_published INTEGER NULL,
        unpublished_at TEXT NULL,
        settings TEXT NULL,
        moderation_state TEXT NOT NULL DEFAULT \'active\',
        custom_domain TEXT NULL,
        custom_domain_status TEXT NULL,
        custom_domain_verified_at TEXT NULL,
        custom_domain_cf_id TEXT NULL,
        custom_domain_primary INTEGER NOT NULL DEFAULT 0,
        hero_title TEXT NULL,
        hero_subtitle TEXT NULL,
        primary_button_text TEXT NULL,
        primary_button_url TEXT NULL,
        bio_text TEXT NULL,
        show_branding INTEGER NULL,
        charlie_enabled INTEGER NULL,
        services_auto_sync_enabled INTEGER NULL,
        content_instagram_auto_enabled INTEGER NULL,
        booking_mode TEXT NULL,
        manual_booking_url TEXT NULL,
        shop_link_mode TEXT NULL DEFAULT \'checkout\',
        shop_auto_latest INTEGER NULL DEFAULT 1,
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
        'hero_title' => 'TEXT NULL',
        'hero_subtitle' => 'TEXT NULL',
        'primary_button_text' => 'TEXT NULL',
        'primary_button_url' => 'TEXT NULL',
        'bio_text' => 'TEXT NULL',
        'show_branding' => 'INTEGER NULL',
        'charlie_enabled' => 'INTEGER NULL',
        'services_auto_sync_enabled' => 'INTEGER NULL',
        'content_instagram_auto_enabled' => 'INTEGER NULL',
        'booking_mode' => 'TEXT NULL',
        'manual_booking_url' => 'TEXT NULL',
        // Global shop link controls (2026-07-08) — site-level columns read by
        // the shop-settings endpoint + the public payload's linkMode stamp.
        'shop_link_mode' => "TEXT NULL DEFAULT 'checkout'",
        'shop_auto_latest' => 'INTEGER NULL DEFAULT 1',
    ];
    foreach ($promotedCols as $col => $type) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS {$col} {$type}");
        } catch (Throwable $e) {
            // already exists / unsupported — ignore
        }
    }

    // site.platform_connections — per-user platform integration selections
    // (Shopify/Apple/Instagram/...). Read by the public platforms endpoint +
    // dashboard; any test that sets up a site may touch it. Mirrors the
    // production migration 20260602020000 (jsonb→TEXT, bool→INTEGER).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        platform TEXT NULL,
        resource_id TEXT NULL,
        payload TEXT NULL,
        sort_order INTEGER NULL DEFAULT 0,
        is_active INTEGER NULL DEFAULT 1,
        last_visited_at TEXT NULL,
        last_refreshed_at TEXT NULL,
        last_refresh_status TEXT NULL,
        last_refresh_error TEXT NULL,
        consecutive_failures INTEGER NULL DEFAULT 0,
        apify_status TEXT NULL,
        place_id TEXT NULL,
        refresh_etag TEXT NULL,
        refresh_last_modified TEXT NULL,
        display_settings TEXT NULL,
        canonical_key TEXT NULL,
        resource_kind TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
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
            ON platform_connections (user_id, platform, canonical_key)
            WHERE canonical_key IS NOT NULL AND deleted_at IS NULL');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }

    // site.shop_brands + site.shop_products — FOUND-25: the shop connection's
    // brand-keyed JSONB payload map, relationalized. Mirrors migration
    // 20260704160000 (uuid FKs → TEXT, boolean → INTEGER, jsonb `data` → TEXT).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.shop_brands (
        id TEXT PRIMARY KEY,
        connection_id TEXT NULL,
        brand_id TEXT NULL,
        provider TEXT NULL,
        url TEXT NULL,
        source_url TEXT NULL,
        name TEXT NULL,
        currency TEXT NULL,
        favicon TEXT NULL,
        logo TEXT NULL,
        discount_code TEXT NULL,
        fetch_mode TEXT NULL,
        is_individual INTEGER NULL DEFAULT 0,
        position INTEGER NULL DEFAULT 0,
        style_analysis TEXT NULL,
        selection_mode TEXT NULL DEFAULT \'manual\',
        link_mode TEXT NULL DEFAULT \'product\',
        referral_query TEXT NULL DEFAULT \'\',
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.shop_products (
        id TEXT PRIMARY KEY,
        brand_id TEXT NULL,
        product_id TEXT NULL,
        position INTEGER NULL DEFAULT 0,
        data TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // site.menus + site.menu_categories + site.menu_items + site.menu_platform_links
    // + site.menu_item_platforms — the relational fetched menu (Uber Eats / DoorDash),
    // one menu row per user. Mirrors migrations 20260617130000 + 20260619050000
    // (jsonb→TEXT, numeric→REAL) + 20260701140000 + 20260701140100 (child tables).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menus (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        content_source TEXT NULL,
        store_name TEXT NULL,
        logo_url TEXT NULL,
        rating REAL NULL,
        review_count INTEGER NULL,
        currency TEXT NULL,
        pickup_platform TEXT NULL,
        delivery_platform TEXT NULL,
        fetch_status TEXT NULL,
        last_fetched_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

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
        id TEXT PRIMARY KEY,
        menu_id TEXT NULL,
        category_id TEXT NULL,
        position INTEGER NULL,
        name TEXT NULL,
        description TEXT NULL,
        image_url TEXT NULL,
        rating REAL NULL,
        rating_count INTEGER NULL,
        badges TEXT NULL,
        base_price REAL NULL,
        pickup_price REAL NULL,
        pickup_source TEXT NULL,
        delivery_price REAL NULL,
        delivery_source TEXT NULL,
        dd_external_id TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

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
        previous_website_analysis TEXT NULL,
        category TEXT NULL,
        description TEXT NULL,
        opening_hours TEXT NULL,
        contact_email TEXT NULL,
        field_sources TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    // Defensive ALTERs for suites that created the table before these columns
    // existed (mirrors the setupSitesTable pattern). previous_website_analysis
    // predates the central-identity columns (opening_hours/contact_email/
    // field_sources — migration 20260705150000).
    foreach ([
        'ALTER TABLE site.workplaces ADD COLUMN previous_website_analysis TEXT NULL',
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
        product_gid TEXT NULL,
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
 * 20260705150200_create_content_selection.sql). SQLite does NOT enforce the
 * position range / ref-shape CHECKs or the UNIQUE(site_id, position) index
 * beyond what's declared — that's fine; the service enforces shape in PHP and
 * the constraint semantics are exercised against real Postgres.
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
 * site.design_kits — per-site design token table (1:1 with site.sites).
 * All token columns are NULLABLE (TEXT except the boolean night-shift flag)
 * so tests can insert partial rows and the resolver falls back to defaults
 * for unset columns. Mirrors the production schema subset tests touch —
 * 2026-07-10 rework: color_bg dropped; theme_mode / theme_night_shift_auto /
 * effect_surface added (migration 20260710160000); the size-named text_*
 * columns became the nine semantic slots (migration 20260710190000);
 * effect_button_fill dropped + the glass knobs effect_glass_blur /
 * motion_glass_shine_duration added (migration 20260710210000). The
 * production trigger trg_create_empty_design_kit is absent in SQLite — tests
 * that need a kit row must insert one manually.
 */
function setupDesignKitsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
        site_id TEXT PRIMARY KEY,
        color_accent TEXT NULL,
        color_accent_contrast TEXT NULL,
        color_text TEXT NULL,
        color_text_muted TEXT NULL,
        border_radius TEXT NULL,
        button_primary_bg TEXT NULL,
        button_primary_text TEXT NULL,
        text_caption TEXT NULL,
        text_body TEXT NULL,
        text_h3 TEXT NULL,
        text_h2 TEXT NULL,
        text_h1 TEXT NULL,
        text_display TEXT NULL,
        text_desktop_body TEXT NULL,
        text_desktop_h1 TEXT NULL,
        text_desktop_display TEXT NULL,
        theme_mode TEXT NULL,
        theme_night_shift_auto INTEGER NULL,
        effect_surface TEXT NULL,
        effect_glass_blur TEXT NULL,
        motion_glass_shine_duration TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.design_kit_contributions — per-factor design-kit preset contributions.
 * priority is INTEGER; UNIQUE(site_id, source, target_var) mirrors production.
 * Mirrors migration 20260701130000.
 */
function setupDesignKitContributionsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kit_contributions (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        source TEXT NOT NULL,
        integration TEXT NOT NULL,
        priority INTEGER NOT NULL,
        mode TEXT NOT NULL,
        target_var TEXT NOT NULL,
        value TEXT NOT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE(site_id, source, target_var)
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
