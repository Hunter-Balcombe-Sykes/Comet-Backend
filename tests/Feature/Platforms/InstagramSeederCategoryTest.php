<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * F4 (2026-08-10): a degraded actor run returned businessCategoryName "None",
 * which was stored and published — businessCategory is on
 * PublicIntegrationConnectionResource::ALLOWLIST['instagram'].
 *
 * These assert the PERSISTED payload, not the private helper: a future edit
 * that drops the categoryOrNull() wrapper and reads the raw profile key again
 * must fail here. Reflecting on the helper would stay green through exactly
 * that regression.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igCategoryUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function igSeedCategory(string $handle, array $profile): IntegrationConnection
{
    Storage::fake('media');

    $user = igCategoryUser($handle);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    // This test is only about the stored category — the mirror pipeline is
    // covered by InstagramAsyncConnectTest.php.
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    app(InstagramConnectionSeeder::class)->seed($connection, $handle, (string) $user->id, $profile);

    return $connection->refresh();
}

it('never stores a placeholder business category in the payload', function (string $raw) {
    $connection = igSeedCategory('igcat'.Str::random(6), ['businessCategoryName' => $raw]);

    expect($connection->payload['businessCategory'])->toBeNull();
})->with(['None', 'none', ' NONE ', 'null', 'N/A', '-', '   ']);

it('stores a real business category verbatim', function () {
    $connection = igSeedCategory('igcatreal', ['businessCategoryName' => '  Tattoo & Piercing Shop  ']);

    expect($connection->payload['businessCategory'])->toBe('Tattoo & Piercing Shop');
});

it('stores a real business category from the raw actor snake_case key too', function () {
    $connection = igSeedCategory('igcatsnake', ['business_category_name' => 'Hair Stylist']);

    expect($connection->payload['businessCategory'])->toBe('Hair Stylist');
});

// ── category_name, the figue actor's key (F5, 2026-08-11) ────────────────────
//
// The figue actor NULLs businessCategoryName AND business_category_name and
// puts the value in category_name. PARTNA_INSTAGRAM_ACTOR is a no-deploy env
// rollback, so without this the STORED payload blanks the moment someone rolls
// the actor back — the user-row half of this landed in 91b16f269.

it('falls back to category_name when both business-category keys are null', function () {
    $connection = igSeedCategory('igcatthird', [
        'businessCategoryName' => null,
        'business_category_name' => null,
        'category_name' => 'Hair Stylist',
    ]);

    expect($connection->payload['businessCategory'])->toBe('Hair Stylist');
});

/**
 * The placeholder must not SHADOW a usable sibling. A plain `??` chain takes
 * the literal "None" (it is non-null), and the filter then blanks it — losing
 * category_name entirely. This is the stored-payload twin of the "first that
 * maps, not first non-null" rule in InstagramIdentitySync::applySector.
 */
it('skips a placeholder primary key and takes the real sibling instead', function () {
    $connection = igSeedCategory('igcatshadow', [
        'business_category_name' => 'None',
        'category_name' => 'Tattoo & Piercing Shop',
    ]);

    expect($connection->payload['businessCategory'])->toBe('Tattoo & Piercing Shop');
});

it('prefers businessCategoryName over category_name when both are real', function () {
    $connection = igSeedCategory('igcatpref', [
        'businessCategoryName' => 'Hair Stylist',
        'category_name' => 'Tattoo',
    ]);

    expect($connection->payload['businessCategory'])->toBe('Hair Stylist');
});
