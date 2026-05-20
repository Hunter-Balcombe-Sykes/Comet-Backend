<?php

use App\Enums\AccountType;
use App\Enums\BrandStatus;
use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandPartnerLink;
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
    // default => 'pgsql' (not 'sqlite') so DB::transaction() in BootstrapController
    // opens its transaction on the SAME connection the models write through.
    // With default='sqlite' the controller's writes (pgsql connection) would
    // autocommit and a rolled-back transaction would NOT undo them — the
    // "not found" branches below specifically assert transaction rollback.
    config([
        'database.default' => 'pgsql',
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
})->group('bootstrap-brand-attach');

/**
 * Builds a BootstrapController with a fully-mocked SiteProvisioningService and
 * AccountTypeDefaultsService. The defaults service is permissive (it just
 * needs to not blow up) — the branches we care about are exercised separately.
 *
 * @return array{0: BootstrapController, 1: AccountTypeDefaultsService}
 */
function makeBrandAttachController(): array
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

/**
 * Invokes BootstrapController::bootstrap() directly (no HTTP route). Both
 * brand-attach service dependencies are injectable so each test can configure
 * its own success/failure expectations.
 */
function callBrandAttachBootstrap(
    BootstrapController $controller,
    AccountTypeDefaultsService $accountDefaults,
    array $data,
    string $uid,
    ?BrandPartnerLinkService $linkService = null,
    ?BrandAffiliateInviteService $inviteService = null,
) {
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', $data);
    $request->attributes->set('supabase_uid', $uid);
    $request->setContainer(app());
    $request->validateResolved();

    return $controller->bootstrap(
        $request,
        $inviteService ?? Mockery::mock(BrandAffiliateInviteService::class),
        $linkService ?? new BrandPartnerLinkService,
        $accountDefaults,
    );
}

/**
 * Seeds a brand Professional (+ optional brand_profiles row). Mirrors the
 * signup-code test's dual-write pattern so Rule::unique validation queries
 * against the non-prefixed `professionals` table also resolve.
 *
 * @return array{professional_id: string, handle_lc: string}
 */
function seedBrandAttachBrand(?string $brandStatus = null): array
{
    $brandProId = (string) Str::uuid();
    // Lower-case only: BootstrapController lower-cases join_brand_handle in
    // prepareForValidation, then matches on handle_lc — a mixed-case value
    // here would never match and the join branch would silently skip.
    $handleLc = 'brand'.strtolower(Str::random(6));

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandProId,
        'auth_user_id' => 'brand-uid-'.Str::random(6),
        'handle' => $handleLc,
        'handle_lc' => $handleLc,
        'display_name' => 'Brand Co',
        'primary_email' => Str::random(6).'@brand.com',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
    ]);
    DB::statement('INSERT INTO professionals (id, auth_user_id, handle, handle_lc, display_name, primary_email, professional_type, account_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $brandProId, 'brand-uid-'.Str::random(6), 'brandx'.Str::random(4), 'brandx'.Str::random(4),
        'Brand Co', Str::random(6).'@brand.com', 'brand', 'brand', 'active',
    ]);

    if ($brandStatus !== null) {
        DB::connection('pgsql')->table('brand.brand_profiles')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $brandProId,
            'setup_complete' => 1,
            'brand_status' => $brandStatus,
            'signup_code_active' => 1,
        ]);
    }

    return ['professional_id' => $brandProId, 'handle_lc' => $handleLc];
}

/**
 * Seeds a NON-brand professional row in the non-prefixed `professionals`
 * table only. This lets `brand_partner_professional_id` pass the request's
 * Rule::exists('professionals','id') validation while still failing the
 * controller's `->where('professional_type','brand')` lookup — exercising
 * the "Brand partner not found." guard rather than a 422 validation error.
 */
function seedNonBrandProfessional(): string
{
    $proId = (string) Str::uuid();
    DB::statement('INSERT INTO professionals (id, auth_user_id, handle, handle_lc, display_name, primary_email, professional_type, account_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $proId, 'nonbrand-uid-'.Str::random(6), 'np'.Str::random(5), 'np'.Str::random(5),
        'Not A Brand', Str::random(6).'@np.com', 'professional', 'individual', 'active',
    ]);

    return $proId;
}

/** Standard new-affiliate request payload. */
function brandAttachSignupData(string $handle, array $extra): array
{
    return array_merge([
        'handle' => $handle,
        'display_name' => 'New Partner',
        'primary_email' => "{$handle}@example.com",
        'first_name' => 'New',
        'professional_type' => 'professional',
    ], $extra);
}

/** Mock a BrandPartnerLinkService that reports a successful link. */
function mockSuccessfulLinkService(string $brandProfessionalId): BrandPartnerLinkService
{
    $link = new BrandPartnerLink([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandProfessionalId,
        'affiliate_professional_id' => 'placeholder',
        'slot' => 0,
    ]);

    $service = Mockery::mock(BrandPartnerLinkService::class);
    $service->shouldReceive('isConnected')->andReturn(false);
    $service->shouldReceive('connectBrandToAffiliate')->andReturn($link);
    $service->shouldReceive('getLinksForAffiliate')->andReturn(collect([$link]));

    return $service;
}

function brandAttachAccountTypeValue(Professional $pro): string
{
    return $pro->account_type->value ?? (string) $pro->account_type;
}

// ── invite_token branch ────────────────────────────────────────────────────

it('invite_token happy path: claims invite and promotes to partner', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $invite = new BrandAffiliateInvite(['brand_professional_id' => $brand['professional_id']]);

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $inviteService->shouldReceive('findByToken')->andReturn($invite);
    $inviteService->shouldReceive('claimInvite')->andReturn($invite);

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['invite_token' => 'tok-'.Str::random(20)]),
        $uid,
        mockSuccessfulLinkService($brand['professional_id']),
        $inviteService,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Partner->value);
});

it('invite_token not found: throws RuntimeException and rolls back the whole transaction', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $inviteService->shouldReceive('findByToken')->andReturn(null);

    expect(fn () => callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['invite_token' => 'tok-'.Str::random(20)]),
        $uid,
        null,
        $inviteService,
    ))->toThrow(RuntimeException::class, 'Invite not found.');

    // Outer catch rethrows → transaction rolled back → no Professional persisted.
    $exists = DB::connection('pgsql')->table('core.professionals')->where('auth_user_id', $uid)->exists();
    expect($exists)->toBeFalse();
});

it('invite_token claimInvite throws: inner catch swallows, txn commits, account stays individual', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $invite = new BrandAffiliateInvite(['brand_professional_id' => $brand['professional_id']]);

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $inviteService->shouldReceive('findByToken')->andReturn($invite);
    $inviteService->shouldReceive('claimInvite')
        ->andThrow(new RuntimeException('You are already connected to a brand partner.'));

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['invite_token' => 'tok-'.Str::random(20)]),
        $uid,
        null,
        $inviteService,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Individual->value);
});

// ── brand_partner_professional_id branch ───────────────────────────────────

it('brand_partner_professional_id happy path: connects link and promotes to partner', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['brand_partner_professional_id' => $brand['professional_id']]),
        $uid,
        mockSuccessfulLinkService($brand['professional_id']),
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Partner->value);
});

it('brand_partner_professional_id not found: throws RuntimeException and rolls back', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    // Existing professional row, but professional_type !== 'brand' — passes
    // Rule::exists validation, fails the controller's brand-only lookup.
    $nonBrandId = seedNonBrandProfessional();

    expect(fn () => callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['brand_partner_professional_id' => $nonBrandId]),
        $uid,
    ))->toThrow(RuntimeException::class, 'Brand partner not found.');

    $exists = DB::connection('pgsql')->table('core.professionals')->where('auth_user_id', $uid)->exists();
    expect($exists)->toBeFalse();
});

it('brand_partner_professional_id connect throws: inner catch swallows, account stays individual', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('isConnected')->andReturn(false);
    $linkService->shouldReceive('connectBrandToAffiliate')
        ->andThrow(new RuntimeException('You are already connected to a brand partner.'));

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['brand_partner_professional_id' => $brand['professional_id']]),
        $uid,
        $linkService,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Individual->value);
});

// ── join_brand_handle branch ───────────────────────────────────────────────

it('join_brand_handle happy path: claims open invite and promotes to partner', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand(BrandStatus::ReadyForAffiliates->value);

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $linkService = mockSuccessfulLinkService($brand['professional_id']);

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $inviteService->shouldReceive('claimOpenInvite')
        ->andReturn(new BrandAffiliateInvite(['brand_professional_id' => $brand['professional_id']]));

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['join_brand_handle' => $brand['handle_lc']]),
        $uid,
        $linkService,
        $inviteService,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Partner->value);
});

it('join_brand_handle with unknown handle: branch silently skips, account stays individual', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['join_brand_handle' => 'nosuchbrand'.Str::random(6)]),
        $uid,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Individual->value);
});

it('join_brand_handle with SystemsDown brand: branch skips attach, account stays individual', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand(BrandStatus::SystemsDown->value);

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['join_brand_handle' => $brand['handle_lc']]),
        $uid,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Individual->value);
});

it('join_brand_handle claimOpenInvite throws: inner catch swallows, account stays individual', function () {
    [$controller, $accountDefaults] = makeBrandAttachController();
    $brand = seedBrandAttachBrand(BrandStatus::ReadyForAffiliates->value);

    $uid = 'partner-uid-'.Str::random(8);
    $handle = 'partner'.Str::random(4);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('isConnected')->andReturn(false);

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $inviteService->shouldReceive('claimOpenInvite')
        ->andThrow(new RuntimeException('This brand is temporarily unavailable due to a platform issue.'));

    $response = callBrandAttachBootstrap(
        $controller,
        $accountDefaults,
        brandAttachSignupData($handle, ['join_brand_handle' => $brand['handle_lc']]),
        $uid,
        $linkService,
        $inviteService,
    );

    expect($response->getStatusCode())->toBe(200);

    $pro = Professional::where('auth_user_id', $uid)->first();
    expect($pro)->not->toBeNull();
    expect(brandAttachAccountTypeValue($pro))->toBe(AccountType::Individual->value);
});
