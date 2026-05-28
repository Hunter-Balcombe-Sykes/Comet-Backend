<?php

use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\SectionVisibilityTestCase;

beforeEach(function () {
    SectionVisibilityTestCase::boot();
});

function seedContactProAndSite(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'contact-pro',
        'display_name' => 'Contact Pro',
        'primary_email' => 'contact@example.com',
        'status' => 'active',
    ]);

    return [$proId, $siteId];
}

// Local helper — different signature from EnquiryInboxTestHelpers::seedContactBlock
// (no return value, adds $isActive param). Renamed to avoid global-scope conflict.
function seedContactBlockForVisibility(string $proId, string $siteId, array $settings = [], bool $isActive = false): void
{
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'settings' => json_encode($settings),
        'is_enabled' => 1,
        'is_active' => $isActive ? 1 : 0,
    ]);
}

it('rejects publishing a contact block with no stored block', function () {
    [$proId, $siteId] = seedContactProAndSite();

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('notification email');
});

it('rejects publishing a contact block with empty settings', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlockForVisibility($proId, $siteId, []);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('notification email');
});

it('rejects publishing a contact block with a blank notification_email', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlockForVisibility($proId, $siteId, ['notification_email' => '   ']);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
});

it('rejects publishing a contact block with an invalid notification_email', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlockForVisibility($proId, $siteId, ['notification_email' => 'not-an-email']);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeFalse();
});

it('allows publishing a contact block with a valid notification_email', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlockForVisibility($proId, $siteId, ['notification_email' => 'hello@mybrand.com']);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'contact');

    expect($canBeVisible)->toBeTrue();
    expect($reason)->toBeNull();
});

it('honours pendingSettings on the first-publish path', function () {
    [$proId, $siteId] = seedContactProAndSite();
    seedContactBlockForVisibility($proId, $siteId, []);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements(
            $proId,
            $siteId,
            'contact',
            ['notification_email' => 'hello@mybrand.com'],
        );

    expect($canBeVisible)->toBeTrue();
});
