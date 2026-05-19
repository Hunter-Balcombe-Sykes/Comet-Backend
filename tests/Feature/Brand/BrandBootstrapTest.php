<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    // Override pgsql connection to use SQLite in-memory
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'partna.waitlist.enabled' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    // Attach schemas for schema-prefixed model tables
    foreach (['core', 'site', 'brand', 'notifications', 'billing'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    // Non-prefixed table on default (sqlite) connection for validation rules (Rule::unique('professionals'))
    DB::statement('CREATE TABLE IF NOT EXISTS professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        primary_email TEXT,
        professional_type TEXT,
        account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        status TEXT DEFAULT "active",
        deleted_at TEXT
    )');

    // Non-prefixed table on pgsql connection (for queries that don't use schema prefix)
    $conn->statement('CREATE TABLE IF NOT EXISTS professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        primary_email TEXT,
        professional_type TEXT,
        account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        status TEXT DEFAULT "active",
        deleted_at TEXT
    )');

    // Schema-prefixed tables for models
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        bio TEXT,
        first_name TEXT,
        last_name TEXT,
        phone TEXT,
        primary_email TEXT,
        public_contact_number TEXT,
        public_contact_email TEXT,
        professional_type TEXT DEFAULT "professional",
        account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        status TEXT DEFAULT "active",
        onboarding_step INTEGER DEFAULT 0,
        country_code TEXT,
        timezone TEXT,
        location_street_address TEXT,
        location_city TEXT,
        location_state TEXT,
        location_postcode TEXT,
        location_country TEXT,
        stripe_connect_account_id TEXT,
        stripe_customer_id TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        subdomain TEXT,
        theme_id TEXT,
        is_published INTEGER DEFAULT 0,
        settings TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE,
        abn TEXT,
        acn TEXT,
        legal_business_name TEXT,
        business_type TEXT,
        industries TEXT,
        estimated_annual_income TEXT,
        business_website TEXT,
        affiliate_visibility TEXT,
        brand_status TEXT,
        setup_complete INTEGER DEFAULT 0,
        signup_code TEXT,
        signup_code_active INTEGER NOT NULL DEFAULT 1,
        signup_code_rotated_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        list_key TEXT,
        email TEXT,
        email_lc TEXT,
        full_name TEXT,
        status TEXT DEFAULT "subscribed",
        unsubscribe_token TEXT,
        subscribed_at TEXT,
        unsubscribed_at TEXT,
        consent_source TEXT,
        metadata TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        type TEXT,
        title TEXT,
        body TEXT,
        cta_url TEXT,
        severity TEXT,
        starts_at TEXT,
        ends_at TEXT,
        read_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        plan_key TEXT,
        status TEXT,
        started_at TEXT,
        ends_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
})->group('brand-bootstrap');

function makeBrandBootstrapController(): array
{
    $siteProvisioning = Mockery::mock(SiteProvisioningService::class);
    $siteProvisioning->shouldReceive('generateQrSlug')->andReturn('qr-'.Str::random(6));
    $siteProvisioning->shouldReceive('subdomainBaseFromHandle')->andReturnUsing(fn ($h) => $h);
    $siteProvisioning->shouldReceive('createSiteWithRetry')->andReturnUsing(function ($proId, $base) {
        $site = new Site([
            'professional_id' => $proId,
            'subdomain' => $base,
            'is_published' => false,
        ]);
        $site->id = (string) Str::uuid();
        $site->save();

        return $site;
    });
    $siteProvisioning->shouldReceive('ensureFreeSubscription')->andReturnNull();

    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);
    $accountDefaults->shouldReceive('applyDefaults');

    $controller = new BootstrapController($siteProvisioning);

    return [$controller, $accountDefaults];
}

function callBrandBootstrap(
    BootstrapController $controller,
    AccountTypeDefaultsService $accountDefaults,
    array $data,
    string $uid,
) {
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', $data);
    $request->attributes->set('supabase_uid', $uid);
    $request->setContainer(app());
    $request->validateResolved();

    return $controller->bootstrap(
        $request,
        Mockery::mock(BrandAffiliateInviteService::class),
        Mockery::mock(BrandPartnerLinkService::class),
        $accountDefaults,
    );
}

it('creates a BrandProfile when bootstrapping a brand account', function () {
    [$controller, $accountDefaults] = makeBrandBootstrapController();

    $uid = 'brand-uid-'.Str::random(8);
    $handle = 'testbrand'.Str::random(4);

    $response = callBrandBootstrap($controller, $accountDefaults, [
        'handle' => $handle,
        'display_name' => 'Test Brand',
        'primary_email' => "{$handle}@example.com",
        'phone' => '0400000000',
        'first_name' => 'Test',
        'professional_type' => 'brand',
    ], $uid);

    expect($response->getStatusCode())->toBe(200);

    $professional = Professional::where('auth_user_id', $uid)->first();
    expect($professional)->not->toBeNull();
    expect($professional->professional_type)->toBe('brand');

    $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();
    expect($brandProfile)->not->toBeNull();
    expect((bool) $brandProfile->setup_complete)->toBeFalse();
});

it('returns 409 EMAIL_ALREADY_REGISTERED when a different auth user owns the email', function () {
    [$controller, $accountDefaults] = makeBrandBootstrapController();

    // Existing professional owned by a different Supabase user.
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'original-owner-uid',
        'handle' => 'taken',
        'handle_lc' => 'taken',
        'display_name' => 'Taken',
        'primary_email' => 'collision@example.com',
        'status' => 'active',
    ]);

    $response = callBrandBootstrap($controller, $accountDefaults, [
        'handle' => 'newuser'.Str::random(4),
        'display_name' => 'New User',
        'primary_email' => 'COLLISION@example.com', // case-insensitive match
        'phone' => '0400000002',
        'first_name' => 'New',
        'professional_type' => 'professional',
        // Satisfies the invite-only signup rule for non-brand types.
        'join_brand_handle' => 'no-such-brand-for-test',
    ], uid: 'new-google-user-uid');

    expect($response->getStatusCode())->toBe(409);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('EMAIL_ALREADY_REGISTERED');
});

it('does not create a BrandProfile for non-brand types', function () {
    [$controller, $accountDefaults] = makeBrandBootstrapController();

    $uid = 'pro-uid-'.Str::random(8);
    $handle = 'testpro'.Str::random(4);

    $response = callBrandBootstrap($controller, $accountDefaults, [
        'handle' => $handle,
        'display_name' => 'Test Pro',
        'primary_email' => "{$handle}@example.com",
        'phone' => '0400000001',
        'first_name' => 'Test',
        'professional_type' => 'professional',
        // Satisfies the invite-only signup rule for non-brand types.
        'join_brand_handle' => 'no-such-brand-for-test',
    ], $uid);

    expect($response->getStatusCode())->toBe(200);

    $professional = Professional::where('auth_user_id', $uid)->first();
    expect($professional)->not->toBeNull();

    $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();
    expect($brandProfile)->toBeNull();
});
