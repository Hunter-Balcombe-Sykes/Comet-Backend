<?php

use App\Enums\AccountType;
use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandSignupCodeAuditEntry;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'partna.waitlist.enabled' => false,
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand', 'notifications', 'billing'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    DB::statement('CREATE TABLE IF NOT EXISTS professionals (
        id TEXT PRIMARY KEY, auth_user_id TEXT, handle TEXT, handle_lc TEXT,
        display_name TEXT, primary_email TEXT, professional_type TEXT,
        account_type TEXT NULL, has_historical_partner_links INTEGER NULL,
        status TEXT DEFAULT "active", deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, auth_user_id TEXT, handle TEXT, handle_lc TEXT,
        display_name TEXT, bio TEXT, first_name TEXT, last_name TEXT, phone TEXT,
        primary_email TEXT, public_contact_number TEXT, public_contact_email TEXT,
        professional_type TEXT DEFAULT "professional", account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL, status TEXT DEFAULT "active",
        onboarding_step INTEGER DEFAULT 0, country_code TEXT, timezone TEXT,
        location_street_address TEXT, location_city TEXT, location_state TEXT,
        location_postcode TEXT, location_country TEXT, stripe_connect_account_id TEXT,
        stripe_customer_id TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY, professional_id TEXT, subdomain TEXT, theme_id TEXT,
        is_published INTEGER DEFAULT 0, settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY, professional_id TEXT UNIQUE, abn TEXT, acn TEXT,
        legal_business_name TEXT, business_type TEXT, industries TEXT,
        estimated_annual_income TEXT, business_website TEXT, affiliate_visibility TEXT,
        brand_status TEXT, setup_complete INTEGER DEFAULT 0,
        signup_code TEXT, signup_code_active INTEGER NOT NULL DEFAULT 1,
        signup_code_rotated_at TEXT, created_at TEXT, updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY, brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL, slot INTEGER NOT NULL DEFAULT 0,
        created_at TEXT, updated_at TEXT, deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.signup_code_audit (
        id TEXT PRIMARY KEY, brand_profile_id TEXT NOT NULL, event TEXT NOT NULL,
        actor_type TEXT NOT NULL, actor_professional_id TEXT, staff_user_id TEXT,
        source_ip TEXT, code_prefix_hash TEXT, joined_professional_id TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY, professional_id TEXT, list_key TEXT, email TEXT,
        email_lc TEXT, full_name TEXT, status TEXT DEFAULT "subscribed",
        unsubscribe_token TEXT, subscribed_at TEXT, unsubscribed_at TEXT,
        consent_source TEXT, metadata TEXT, created_at TEXT, updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY, professional_id TEXT, type TEXT, title TEXT, body TEXT,
        cta_url TEXT, severity TEXT, starts_at TEXT, ends_at TEXT, read_at TEXT,
        created_at TEXT, updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY, professional_id TEXT, plan_key TEXT, status TEXT,
        started_at TEXT, ends_at TEXT, created_at TEXT, updated_at TEXT
    )');
})->group('bootstrap-signup-code');

function makeSignupCodeBootstrapController(): array
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
    $accountDefaults->shouldReceive('applyAffiliateDefaults');

    $controller = new BootstrapController($siteProvisioning);

    return [$controller, $accountDefaults];
}

function callSignupCodeBootstrap(
    BootstrapController $controller,
    AccountTypeDefaultsService $accountDefaults,
    array $data,
    string $uid,
    ?BrandPartnerLinkService $linkService = null,
) {
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', $data);
    $request->attributes->set('supabase_uid', $uid);
    $request->setContainer(app());
    $request->validateResolved();

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);

    return $controller->bootstrap(
        $request,
        $inviteService,
        $linkService ?? new BrandPartnerLinkService,
        $accountDefaults,
    );
}

function createBrandWithSignupCode(): array
{
    $brandProId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandProId,
        'auth_user_id' => 'brand-uid-'.Str::random(6),
        'handle' => 'brand'.Str::random(4),
        'handle_lc' => 'brand'.Str::random(4),
        'display_name' => 'Brand Co',
        'primary_email' => Str::random(6).'@brand.com',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
    ]);
    // Also insert in the non-prefixed table for Rule::unique validation queries
    DB::statement('INSERT INTO professionals (id, auth_user_id, handle, handle_lc, display_name, primary_email, professional_type, account_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $brandProId, 'brand-uid-'.Str::random(6), 'brandx'.Str::random(4), 'brandx'.Str::random(4),
        'Brand Co', Str::random(6).'@brand.com', 'brand', 'brand', 'active',
    ]);

    $code = bin2hex(random_bytes(8));
    $profileId = (string) Str::uuid();
    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => $profileId,
        'professional_id' => $brandProId,
        'setup_complete' => 1,
        'signup_code' => $code,
        'signup_code_active' => 1,
    ]);

    return ['professional_id' => $brandProId, 'profile_id' => $profileId, 'code' => $code];
}

it('signup with valid code creates a partner professional + claim audit row', function () {
    [$controller, $accountDefaults] = makeSignupCodeBootstrapController();
    $brand = createBrandWithSignupCode();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    // Mock BrandPartnerLinkService to avoid BrandPartnerLinkObserver touching
    // the default-connection professionals table (observer fires on link create).
    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $fakeLink = new BrandPartnerLink([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'brand_professional_id' => $brand['professional_id'],
        'affiliate_professional_id' => 'placeholder',
        'slot' => 0,
    ]);
    $linkService->shouldReceive('isConnected')->andReturn(false);
    $linkService->shouldReceive('connectBrandToAffiliate')->andReturn($fakeLink);
    $linkService->shouldReceive('getLinksForAffiliate')->andReturn(collect([$fakeLink]));

    $response = callSignupCodeBootstrap($controller, $accountDefaults, [
        'handle' => $handle,
        'display_name' => 'New Partner',
        'primary_email' => "{$handle}@example.com",
        'first_name' => 'New',
        'professional_type' => 'professional',
        'brand_signup_code' => $brand['code'],
    ], $uid, $linkService);

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    // After successful brand-attach, account_type is promoted to partner
    expect($pro->account_type->value ?? (string) $pro->account_type)->toBe(AccountType::Partner->value);

    // Claim audit row must be written
    $audit = BrandSignupCodeAuditEntry::query()
        ->where('brand_profile_id', $brand['profile_id'])
        ->where('event', 'claimed')
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->joined_professional_id)->toBe((string) $pro->id);
});

it('invalid code returns 422 with SIGNUP_CODE_NOT_FOUND', function () {
    [$controller, $accountDefaults] = makeSignupCodeBootstrapController();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $response = callSignupCodeBootstrap($controller, $accountDefaults, [
        'handle' => $handle,
        'display_name' => 'New Partner',
        'primary_email' => "{$handle}@example.com",
        'first_name' => 'New',
        'professional_type' => 'professional',
        'brand_signup_code' => '0000000000000000',
    ], $uid);

    expect($response->getStatusCode())->toBe(422);
    $body = $response->getData(true);
    expect($body['errors']['code'] ?? null)->toBe('SIGNUP_CODE_NOT_FOUND');
});

it('single-brand-cap: returns 422 + preserves the newly-created Professional', function () {
    [$controller, $accountDefaults] = makeSignupCodeBootstrapController();
    $brand = createBrandWithSignupCode();

    $uid = 'existing-uid-'.Str::random(6);
    $handle = 'newpro'.Str::random(4);
    $email = $handle.'@example.com';

    // Mock BrandPartnerLinkService to throw the single-brand cap error on
    // connectBrandToAffiliate. Reflects a user who is already a partner of
    // another brand attempting to claim a second brand's signup code.
    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('isConnected')->andReturn(false);
    $linkService->shouldReceive('connectBrandToAffiliate')
        ->andThrow(new \RuntimeException('You are already connected to a brand partner. Disconnect from your current brand partner before connecting to a new one.'));

    $response = callSignupCodeBootstrap($controller, $accountDefaults, [
        'handle' => $handle,
        'display_name' => 'New',
        'primary_email' => $email,
        'first_name' => 'New',
        'professional_type' => 'professional',
        'brand_signup_code' => $brand['code'],
    ], $uid, $linkService);

    expect($response->getStatusCode())->toBe(422);
    $body = $response->getData(true);
    expect($body['errors']['code'] ?? null)->toBe('BRAND_SIGNUP_CODE_CAP_EXCEEDED')
        ->and($body['message'] ?? null)->toContain('already connected to a brand partner');

    // CRITICAL: the new Professional row MUST be preserved despite the brand-attach
    // failure — otherwise the user loses their account in a single transaction
    // rollback. Reproduces the bug from §28.9 PR code review.
    $exists = DB::connection('pgsql')->table('core.professionals')->where('auth_user_id', $uid)->exists();
    expect($exists)->toBeTrue();
});
