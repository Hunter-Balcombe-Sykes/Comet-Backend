<?php

use App\Http\Controllers\Api\Professional\Affiliate\OpenInviteController;
use App\Http\Controllers\Api\Professional\Brand\BrandAffiliateInviteController;
use App\Http\Controllers\Api\PublicSite\PublicOpenInviteController;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    // TestCase::setUp already redirects 'pgsql' to in-memory SQLite and sets
    // it as the default connection, so we don't need to redefine it here.
    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand', 'notifications'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

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
        created_at TEXT,
        updated_at TEXT
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

    // Brand logos live in site_media (purpose=logo_full) since the design-media
    // upload pipeline took over from the legacy site.settings JSON path.
    // BrandDesignMediaService::getLogoFullUrls queries this + media_variants.
    $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        pool TEXT NOT NULL DEFAULT "gallery",
        purpose TEXT,
        path TEXT,
        alt_text TEXT,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        media_type TEXT DEFAULT "image",
        processing_state TEXT DEFAULT "pending",
        processing_error TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.media_variants (
        id TEXT PRIMARY KEY,
        media_id TEXT NOT NULL,
        variant_key TEXT NOT NULL,
        artifact_type TEXT NOT NULL,
        disk TEXT,
        path TEXT,
        mime TEXT,
        width INTEGER,
        height INTEGER,
        bitrate_kbps INTEGER,
        file_size_bytes INTEGER,
        duration_ms INTEGER,
        metadata TEXT,
        content_hash TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NOT NULL,
        brand_professional_id TEXT NOT NULL,
        slot INTEGER NOT NULL DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_affiliate_invites (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT "pending",
        invite_type TEXT NOT NULL DEFAULT "generic",
        email TEXT,
        email_lc TEXT,
        phone TEXT,
        first_name TEXT,
        last_name TEXT,
        message TEXT,
        claimed_professional_id TEXT,
        accepted_at TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // ProfessionalIntegration is queried by ProfessionalSetupService and
    // related onboarding flows when an affiliate connects to a brand. Without
    // it, the connection-creating tests fail with "no such table".
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        access_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
})->group('open-invite');

function createBrand(string $handle = 'testbrand', string $brandStatus = 'active'): Professional
{
    $brandId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.com",
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'brand_status' => $brandStatus,
        'setup_complete' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Create site for brand
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $brandId,
        'subdomain' => $handle,
        // brand_color still lives in JSON; logo moved to site_media (seeded below).
        'settings' => json_encode(['design' => ['dark_color' => '#000000']]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Seed the full logo via site_media (the modern path) so BrandDesignMediaService
    // resolves it. media_variants supplies the optimized URL via the model accessor.
    $logoMediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $logoMediaId,
        'site_id' => $siteId,
        'pool' => 'design',
        'purpose' => 'logo_full',
        'path' => "images/{$brandId}/{$logoMediaId}/original.png",
        'sort_order' => 0,
        'is_active' => 1,
        'media_type' => 'image',
        'processing_state' => 'ready',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(),
        'media_id' => $logoMediaId,
        'variant_key' => 'optimized',
        'artifact_type' => 'webp',
        'disk' => 'media',
        'path' => "images/{$brandId}/{$logoMediaId}/optimized.webp",
        'mime' => 'image/webp',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($brandId);
}

function createAffiliate(string $handle = 'testaffiliate'): Professional
{
    $affiliateId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $affiliateId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.com",
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Create site for affiliate
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliateId,
        'subdomain' => $handle,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($affiliateId);
}

// --- Public Preview Tests ---

it('returns brand preview for valid active brand', function () {
    $brand = createBrand('acmebrand');

    $controller = new PublicOpenInviteController;
    $response = app()->call([$controller, 'show'], ['handle' => 'acmebrand']);

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data['brand']['handle'])->toBe('acmebrand');
    expect($data['brand']['display_name'])->toBe('Acmebrand');
    expect($data['brand']['professional_id'])->toBe($brand->id);
    expect($data['brand']['brand_logo_url'])->not->toBeNull();
    expect($data['brand']['brand_color'])->toBe('#000000');
});

it('returns 404 for nonexistent handle', function () {
    $controller = new PublicOpenInviteController;
    $response = app()->call([$controller, 'show'], ['handle' => 'nonexistent']);

    expect($response->status())->toBe(404);
});

it('returns 404 for systems_down brand', function () {
    createBrand('deadbrand', 'systems_down');

    $controller = new PublicOpenInviteController;
    $response = app()->call([$controller, 'show'], ['handle' => 'deadbrand']);

    expect($response->status())->toBe(404);
});

it('returns 404 for non-brand professional', function () {
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => 'regularpro',
        'handle_lc' => 'regularpro',
        'display_name' => 'Regular Pro',
        'primary_email' => 'regularpro@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $controller = new PublicOpenInviteController;
    $response = app()->call([$controller, 'show'], ['handle' => 'regularpro']);

    expect($response->status())->toBe(404);
});

it('normalises handle to lowercase for lookup', function () {
    createBrand('mixedcase');

    $controller = new PublicOpenInviteController;
    $response = app()->call([$controller, 'show'], ['handle' => 'MixedCase']);

    expect($response->status())->toBe(200);
});

// --- Authenticated Claim Tests ---

it('creates connection when authenticated affiliate claims open invite', function () {
    $brand = createBrand('claimablebrand');
    $affiliate = createAffiliate('claimingaffiliate');

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);
    $accountDefaults->shouldReceive('applyAffiliateDefaults');

    $cacheService = Mockery::mock(ProfessionalCacheService::class);
    $cacheService->shouldReceive('invalidateProfessional');
    app()->instance(ProfessionalCacheService::class, $cacheService);

    $request = Request::create('/api/join/claimablebrand', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'claimablebrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data['invite']['status'])->toBe('accepted');
    expect($data['invite']['invite_type'])->toBe('generic');
    expect($data['invite']['brand_professional_id'])->toBe($brand->id);
    expect($data['invite']['claimed_professional_id'])->toBe($affiliate->id);

    // Verify BrandPartnerLink was created
    $link = BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brand->id)
        ->first();
    expect($link)->not->toBeNull();
    expect($link->slot)->toBe(0);

    // Verify audit invite record was created
    $invite = BrandAffiliateInvite::where('brand_professional_id', $brand->id)
        ->where('claimed_professional_id', $affiliate->id)
        ->first();
    expect($invite)->not->toBeNull();
    expect($invite->invite_type)->toBe('generic');
    expect($invite->status)->toBe('accepted');
    expect($invite->email)->toBeNull();
});

it('returns 404 when brand handle does not exist', function () {
    $affiliate = createAffiliate('lonelyaffiliate');

    $inviteService = Mockery::mock(BrandAffiliateInviteService::class);
    $brandPartnerLinks = Mockery::mock(BrandPartnerLinkService::class);
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/ghost', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'ghost', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(404);
});

it('returns 422 when brand account tries to claim', function () {
    $brand = createBrand('targetbrand');
    $claimingBrand = createBrand('claimingbrand');

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/targetbrand', 'POST');
    $request->attributes->set('professional', $claimingBrand);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'targetbrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Brand accounts cannot');
});

it('returns 422 when affiliate is already connected', function () {
    $brand = createBrand('connectedbrand');
    $affiliate = createAffiliate('connectedaffiliate');

    // Pre-create the connection
    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/connectedbrand', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'connectedbrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('already connected');
});

it('returns 422 when brand is systems_down', function () {
    createBrand('deactivatedbrand', 'systems_down');
    $affiliate = createAffiliate('eageraffiliate');

    $inviteService = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $brandPartnerLinks = new BrandPartnerLinkService;
    $accountDefaults = Mockery::mock(AccountTypeDefaultsService::class);

    $request = Request::create('/api/join/deactivatedbrand', 'POST');
    $request->attributes->set('professional', $affiliate);

    $controller = new OpenInviteController;
    $response = $controller->claim($request, 'deactivatedbrand', $inviteService, $brandPartnerLinks, $accountDefaults);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('temporarily unavailable');
});

// --- Service Unit Tests ---

it('claimOpenInvite creates invite and partner link in one transaction', function () {
    $brand = createBrand('servicebrand');
    $affiliate = createAffiliate('serviceaffiliate');

    $service = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $invite = $service->claimOpenInvite($brand, $affiliate);

    expect($invite->status)->toBe('accepted');
    expect($invite->invite_type)->toBe('generic');
    expect($invite->email)->toBeNull();
    expect($invite->brand_professional_id)->toBe($brand->id);
    expect($invite->claimed_professional_id)->toBe($affiliate->id);
    expect($invite->accepted_at)->not->toBeNull();
    expect($invite->expires_at)->toBeNull();

    // Token should be generated
    expect($invite->token)->toBeString();
    expect(strlen($invite->token))->toBe(48);

    // Partner link should exist
    expect(BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brand->id)
        ->exists())->toBeTrue();
});

// --- Single-brand cap (pilot/V1): each affiliate may connect to at most one brand. ---

it('rejects connect when affiliate already has a different brand partner', function () {
    $brandA = createBrand('capbranda');
    $brandB = createBrand('capbrandb');
    $affiliate = createAffiliate('capaffiliate');

    $links = new BrandPartnerLinkService;
    $links->connectBrandToAffiliate($affiliate->id, $brandA->id);

    expect(fn () => $links->connectBrandToAffiliate($affiliate->id, $brandB->id))
        ->toThrow(RuntimeException::class, 'Disconnect from your current brand partner');

    // Brand A connection is preserved
    expect(BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brandA->id)
        ->where('slot', 0)
        ->exists())->toBeTrue();

    // Brand B connection was never created
    expect(BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brandB->id)
        ->exists())->toBeFalse();
});

it('allows reconnecting to a new brand after disconnecting from the previous one', function () {
    $brandA = createBrand('switchbranda');
    $brandB = createBrand('switchbrandb');
    $affiliate = createAffiliate('switchaffiliate');

    $links = new BrandPartnerLinkService;
    $links->connectBrandToAffiliate($affiliate->id, $brandA->id);
    $links->disconnectBrandFromAffiliate($affiliate->id, $brandA->id);

    $newLink = $links->connectBrandToAffiliate($affiliate->id, $brandB->id);

    expect($newLink->slot)->toBe(0);
    expect(BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $brandB->id)
        ->where('slot', 0)
        ->exists())->toBeTrue();
});

it('keeps an email invite pending when the cap blocks acceptance', function () {
    $existingBrand = createBrand('alreadybrand');
    $invitingBrand = createBrand('invitingbrand');
    $affiliate = createAffiliate('boundaffiliate');

    // Affiliate is already connected to Brand A
    $links = new BrandPartnerLinkService;
    $links->connectBrandToAffiliate($affiliate->id, $existingBrand->id);

    // Brand B has issued an email invite to that affiliate
    $inviteId = (string) Str::uuid();
    $token = Str::random(48);
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert([
        'id' => $inviteId,
        'brand_professional_id' => $invitingBrand->id,
        'token' => $token,
        'status' => 'pending',
        'invite_type' => 'personalised',
        'email' => $affiliate->primary_email,
        'email_lc' => strtolower((string) $affiliate->primary_email),
        'expires_at' => now()->addDays(30)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $service = new BrandAffiliateInviteService(new BrandPartnerLinkService);
    $invite = BrandAffiliateInvite::find($inviteId);

    expect(fn () => $service->claimInvite($invite, $affiliate))
        ->toThrow(RuntimeException::class, 'Disconnect from your current brand partner');

    // Invite must remain pending so the affiliate can accept later after disconnecting
    $reloaded = BrandAffiliateInvite::find($inviteId);
    expect($reloaded->status)->toBe('pending');
    expect($reloaded->claimed_professional_id)->toBeNull();
    expect($reloaded->accepted_at)->toBeNull();

    // Existing Brand A connection survives untouched
    expect(BrandPartnerLink::where('affiliate_professional_id', $affiliate->id)
        ->where('brand_professional_id', $existingBrand->id)
        ->where('slot', 0)
        ->exists())->toBeTrue();
});

it('routes invite notifications through NotificationPublisher with category invites', function () {
    $brand = createBrand('publisherbrand', 'ready_for_affiliates');

    // Seed an existing professional that matches the invite email so
    // notifyExistingEmailRecipientsBatch has someone to notify.
    $recipientId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $recipientId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => 'publisherrecipient',
        'handle_lc' => 'publisherrecipient',
        'display_name' => 'Pub Recipient',
        'primary_email' => 'invitee@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $publisher = Mockery::mock(\App\Services\Notifications\NotificationPublisher::class);
    $publisher->shouldReceive('publishMany')
        ->once()
        ->with(Mockery::on(function (array $items) use ($recipientId): bool {
            if (count($items) !== 1) {
                return false;
            }
            $item = $items[0];

            return $item['professionalId'] === $recipientId
                && $item['category'] === 'invites'
                && $item['frontendType'] === 'Invitation'
                && $item['retentionConfigKey'] === 'invite'
                && str_starts_with((string) $item['dedupeKey'], 'invite:');
        }));

    $service = new BrandAffiliateInviteService(new BrandPartnerLinkService, $publisher);
    $service->createInvite($brand, [
        'email' => 'invitee@example.com',
        'expiration' => '30d',
    ]);
});

it('flags invites whose recipient is already partnered with another brand', function () {
    $brandA = createBrand('brandalready', 'ready_for_affiliates');
    $brandB = createBrand('brandinviting', 'ready_for_affiliates');

    // Recipient is already partnered with Brand A.
    $recipientId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $recipientId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => 'flagrecipient',
        'handle_lc' => 'flagrecipient',
        'display_name' => 'Flag Recipient',
        'primary_email' => 'flagged@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $recipientId,
        'brand_professional_id' => $brandA->id,
        'slot' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Brand B has issued a pending invite to the same email.
    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandB->id,
        'token' => Str::random(48),
        'status' => 'pending',
        'invite_type' => 'personalised',
        'email' => 'flagged@example.com',
        'email_lc' => 'flagged@example.com',
        'expires_at' => now()->addDays(30)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    // And an unrelated pending invite to someone with no existing connection.
    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandB->id,
        'token' => Str::random(48),
        'status' => 'pending',
        'invite_type' => 'personalised',
        'email' => 'fresh@example.com',
        'email_lc' => 'fresh@example.com',
        'expires_at' => now()->addDays(30)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $request = Request::create('/api/professional/brand-affiliate-invites', 'GET');
    $request->attributes->set('professional', $brandB);

    $controller = new \App\Http\Controllers\Api\Professional\Brand\BrandAffiliateInviteController;
    $response = $controller->index($request);
    $data = $response->getData(true);

    expect($response->status())->toBe(200);
    expect($data['invites'])->toHaveCount(2);

    $byEmail = collect($data['invites'])->keyBy('email');
    expect($byEmail['flagged@example.com']['recipient_partnered_elsewhere'])->toBeTrue();
    expect($byEmail['fresh@example.com']['recipient_partnered_elsewhere'])->toBeFalse();
});

it('claimOpenInvite throws when affiliate has no site', function () {
    $brand = createBrand('sitelessbrand');

    // Create affiliate without a site
    $affiliateId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $affiliateId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => 'nositeaffiliate',
        'handle_lc' => 'nositeaffiliate',
        'display_name' => 'No Site',
        'primary_email' => 'nosite@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $affiliate = Professional::find($affiliateId);

    $service = new BrandAffiliateInviteService(new BrandPartnerLinkService);

    expect(fn () => $service->claimOpenInvite($brand, $affiliate))
        ->toThrow(RuntimeException::class, 'Your site could not be found');
});

it('rejects deletion of an accepted invite to preserve the connection audit trail', function () {
    $brand = createBrand('auditbrand');
    $affiliate = createAffiliate('auditedaffiliate');

    $inviteId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert([
        'id' => $inviteId,
        'brand_professional_id' => $brand->id,
        'token' => Str::random(48),
        'status' => 'accepted',
        'invite_type' => 'personalised',
        'email' => $affiliate->primary_email,
        'email_lc' => strtolower((string) $affiliate->primary_email),
        'claimed_professional_id' => $affiliate->id,
        'accepted_at' => $now,
        'expires_at' => now()->addDays(30)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $request = Request::create('/api/test', 'DELETE');
    $request->attributes->set('professional', Professional::find($brand->id));

    $response = (new BrandAffiliateInviteController)->destroy($request, $inviteId);

    expect($response->status())->toBe(422);
    expect(BrandAffiliateInvite::find($inviteId))->not->toBeNull();
});

it('allows deletion of a pending invite', function () {
    $brand = createBrand('cleanupbrand');

    $inviteId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert([
        'id' => $inviteId,
        'brand_professional_id' => $brand->id,
        'token' => Str::random(48),
        'status' => 'pending',
        'invite_type' => 'personalised',
        'email' => 'pending@example.com',
        'email_lc' => 'pending@example.com',
        'expires_at' => now()->addDays(30)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $request = Request::create('/api/test', 'DELETE');
    $request->attributes->set('professional', Professional::find($brand->id));

    $response = (new BrandAffiliateInviteController)->destroy($request, $inviteId);

    expect($response->status())->toBe(200);
    expect(BrandAffiliateInvite::find($inviteId))->toBeNull();
});
