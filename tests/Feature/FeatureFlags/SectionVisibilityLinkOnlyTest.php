<?php

use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\SectionVisibilityTestCase;

beforeEach(function () {
    SectionVisibilityTestCase::boot();
});

function seedProAndSite(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'test-pro',
        'display_name' => 'Test Pro',
        'primary_email' => 'test@example.com',
        'status' => 'active',
    ]);

    // FFLAG-6 fix: insert a real site.sites row so $siteId is backed by a DB row.
    // Visibility checks that gate on the site (e.g. checkWorkplaceRequirements)
    // would otherwise find nothing and silently return false with no error.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'test-pro',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return [$proId, $siteId];
}

function seedActiveService(string $proId): void
{
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'title' => 'Test Service',
        'price_cents' => 5000,
        'is_active' => 1,
    ]);
}

function seedSquareIntegration(string $proId): void
{
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'provider' => 'square',
        'access_token' => 'tok',
        'external_account_id' => 'merchant-1',
    ]);
}

it('rejects booking section via Square integration when smart_booking flag is off', function () {
    config()->set('partna.features.smart_booking', false);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);
    seedSquareIntegration($proId);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('booking link');
});

it('still rejects booking section via Square integration when smart_booking flag is on (feature dropped)', function () {
    // Smart-booking (Square/Fresha integration) was dropped in 2d9d3a25 — the config
    // flag is no longer read. professionalHasBookingIntegration() hard-returns false,
    // so a Square integration alone never satisfies the booking-visibility requirement
    // regardless of the flag value. Only the manual booking_url path or a booking-link
    // block makes the section visible (covered by the two tests below).
    config()->set('partna.features.smart_booking', true);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);
    seedSquareIntegration($proId);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('booking link');
});

it('allows booking section via booking link block', function () {
    config()->set('partna.features.smart_booking', false);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);

    // Link blocks with category='booking' are the current path — stored in the
    // links block_group, not on the booking section block itself.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'links',
        'block_type' => 'link',
        'settings' => json_encode(['category' => 'booking', 'url' => 'https://example.com/book']),
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeTrue();
});

it('allows booking section via manual booking_url when smart_booking flag is off', function () {
    config()->set('partna.features.smart_booking', false);

    [$proId, $siteId] = seedProAndSite();
    seedActiveService($proId);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'booking',
        'settings' => json_encode(['booking_url' => 'https://example.com/book']),
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'booking');

    expect($canBeVisible)->toBeTrue();
});

// ── FOUND-4: workplace visibility ────────────────────────────────────────────

it('workplace section is visible when name is present (FOUND-4)', function () {
    [$proId, $siteId] = seedProAndSite();

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Fade Lab',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'workplace');

    expect($canBeVisible)->toBeTrue()
        ->and($reason)->toBeNull();
});

it('workplace section is visible when address is present but name is absent (FOUND-4)', function () {
    // The visibility gate is name OR address — an address-only entry is a valid
    // manual-location record and must go live.
    [$proId, $siteId] = seedProAndSite();

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => null,
        'address' => '10 Crown St, Surry Hills',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'workplace');

    expect($canBeVisible)->toBeTrue();
});

it('workplace section is draft when no workplace row exists (FOUND-4)', function () {
    [$proId, $siteId] = seedProAndSite();

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'workplace');

    expect($canBeVisible)->toBeFalse()
        ->and($reason)->toContain('name or address');
});

// ── FOUND-5: credentials/experience visibility ────────────────────────────────

it('credentials section is visible when at least one credential with a title exists (FOUND-5)', function () {
    [$proId, $siteId] = seedProAndSite();

    DB::connection('pgsql')->table('core.user_credentials')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'title' => 'BA Design',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'credentials');

    expect($canBeVisible)->toBeTrue();
});

it('credentials section is draft when no credential rows exist (FOUND-5)', function () {
    [$proId, $siteId] = seedProAndSite();

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'credentials');

    expect($canBeVisible)->toBeFalse()
        ->and($reason)->toContain('credential');
});

it('experience section is visible when at least one experience with a role exists (FOUND-5)', function () {
    [$proId, $siteId] = seedProAndSite();

    DB::connection('pgsql')->table('core.user_experience')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'role' => 'Senior Stylist',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'experience');

    expect($canBeVisible)->toBeTrue();
});

it('experience section is draft when no experience rows exist (FOUND-5)', function () {
    [$proId, $siteId] = seedProAndSite();

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'experience');

    expect($canBeVisible)->toBeFalse()
        ->and($reason)->toContain('role');
});
