<?php

// TEST-5: Coverage for StaffSiteController (show + showByProfessional).
//
// Both actions read site.all_site_data — a Postgres view in production. SQLite
// has no views, so we create a plain stand-in table under the attached `site`
// schema and seed rows; the AllSiteData model + StaffSiteResource then run
// unchanged against it. We invoke the controller directly (mirroring
// StaffWorkplaceControllerTest) to skip the staff auth/middleware stack — the
// behaviour under test is the lookup + resource shaping, not the route guard.

use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupAllSiteDataTable();
});

/** Stand-in for the site.all_site_data view — only the columns StaffSiteResource reads. */
function setupAllSiteDataTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.all_site_data (
        site_id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        skeleton_id TEXT NULL,
        site_settings TEXT NULL,
        is_published INTEGER NULL,
        handle TEXT NULL,
        display_name TEXT NULL,
        account_type TEXT NULL,
        location_street_address TEXT NULL,
        location_city TEXT NULL,
        location_state TEXT NULL,
        location_postcode TEXT NULL,
        location_country TEXT NULL,
        blocks TEXT NULL
    )');
}

/** Seed one all_site_data row; returns [siteId, userId]. */
function seedAllSiteData(array $overrides = []): array
{
    $siteId = $overrides['site_id'] ?? (string) Str::uuid();
    $userId = $overrides['user_id'] ?? (string) Str::uuid();

    DB::connection('pgsql')->table('site.all_site_data')->insert(array_merge([
        'site_id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'alpha',
        'skeleton_id' => 'one',
        'site_settings' => json_encode(['booking_mode' => 'manual']),
        'is_published' => 1,
        'handle' => 'alpha',
        'display_name' => 'Alpha Pro',
        'account_type' => 'individual',
        'blocks' => json_encode([['block_type' => 'link', 'title' => 'My link']]),
    ], $overrides));

    return [$siteId, $userId];
}

it('returns site data for a given subdomain', function () {
    [$siteId] = seedAllSiteData(['subdomain' => 'alpha']);

    $response = (new StaffSiteController)->show('alpha');
    $body = $response->getData(true);

    // API-3: responses are wrapped in ['site' => StaffSiteResource] to match the
    // professional surface shape. The full StaffSiteResource shape is at $body['site'].
    expect($response->getStatusCode())->toBe(200)
        ->and($body['site']['is_published'])->toBeTrue()
        ->and($body['site']['site']['id'])->toBe($siteId)
        ->and($body['site']['site']['subdomain'])->toBe('alpha')
        ->and($body['site']['site']['skeleton_id'])->toBe('one')
        ->and($body['site']['site']['settings'])->toBe(['booking_mode' => 'manual'])
        ->and($body['site']['professional']['handle'])->toBe('alpha')
        ->and($body['site']['professional']['display_name'])->toBe('Alpha Pro')
        ->and($body['site']['blocks'])->toBe([['block_type' => 'link', 'title' => 'My link']]);
});

it('matches the subdomain case-insensitively', function () {
    seedAllSiteData(['subdomain' => 'alpha']);

    $response = (new StaffSiteController)->show('ALPHA');

    expect($response->getStatusCode())->toBe(200);
});

it('returns 404 when the subdomain is not found', function () {
    seedAllSiteData(['subdomain' => 'alpha']);

    $response = (new StaffSiteController)->show('ghost');

    expect($response->getStatusCode())->toBe(404);
});

it('returns site data for a given professional', function () {
    $pro = makeStaffSiteProfessional();
    seedAllSiteData(['user_id' => $pro->id, 'subdomain' => 'by-pro']);

    $response = (new StaffSiteController)->showByProfessional($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['site']['professional']['id'])->toBe($pro->id)
        ->and($body['site']['site']['subdomain'])->toBe('by-pro');
});

it('returns 404 for a professional with no site row', function () {
    $pro = makeStaffSiteProfessional();
    // No all_site_data row for this professional.

    $response = (new StaffSiteController)->showByProfessional($pro);

    expect($response->getStatusCode())->toBe(404);
});

/** Insert a bare professional and return the User model. */
function makeStaffSiteProfessional(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'pro-'.substr($id, 0, 8),
        'handle_lc' => 'pro-'.substr($id, 0, 8),
        'display_name' => 'Staff-Viewed Pro',
        'primary_email' => 'pro-'.substr($id, 0, 8).'@example.test',
        'account_type' => 'individual',
        'status' => 'active',
    ]);

    return User::query()->findOrFail($id);
}
