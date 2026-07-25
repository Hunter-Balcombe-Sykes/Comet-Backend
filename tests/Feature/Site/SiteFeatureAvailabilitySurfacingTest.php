<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable(); // GET /api/site calls SiteResource->withRationale() -> DesignRationaleService
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
});

function seedOwnerWithSite(string $subdomain = 'ownerpro'): User
{
    $userId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId, 'handle' => $subdomain, 'handle_lc' => $subdomain,
        'display_name' => 'Owner Pro', 'first_name' => 'Owner Pro',
        'primary_email' => $subdomain.'@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'subdomain' => $subdomain, 'is_published' => 1,
    ]);

    return User::query()->findOrFail($userId);
}

it('surfaces feature_availability on the owner GET /api/site, reflecting a disabled rule', function () {
    $owner = seedOwnerWithSite();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.feature_availability.enquiries', false)
        ->assertJsonPath('site.feature_availability.email_signup', true)
        ->assertJsonPath('site.feature_availability.customer_leads', true);
});

it('reports all features available when no rule exists', function () {
    $owner = seedOwnerWithSite('cleanpro');

    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.feature_availability.enquiries', true)
        ->assertJsonPath('site.feature_availability.email_signup', true)
        ->assertJsonPath('site.feature_availability.customer_leads', true);
});
