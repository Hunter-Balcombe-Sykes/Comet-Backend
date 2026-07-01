<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffWorkplaceController;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

/**
 * Creates a user + site pair. If $workplace is non-null, inserts the given fields
 * into site.workplaces (promoted from settings.workplace JSONB — FOUND-4).
 */
function makeStaffWorkplaceUser(?array $workplace = null): User
{
    $id = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'wp-'.substr($id, 0, 8),
        'handle_lc' => 'wp-'.substr($id, 0, 8),
        'display_name' => 'Workplace Pro',
        'primary_email' => 'wp-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $id,
        'subdomain' => 'wp-'.substr($id, 0, 8),
        'settings' => '{}',
        'is_published' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    if ($workplace !== null) {
        DB::connection('pgsql')->table('site.workplaces')->insert(array_merge(
            ['site_id' => $siteId, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
            $workplace,
        ));
    }

    return User::query()->find($id);
}

it('returns null profile when the site has no workplace key', function () {
    $pro = makeStaffWorkplaceUser(null);
    $controller = new StaffWorkplaceController;

    $response = $controller->show($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['workplace'])->toBeNull();
});

it('returns the normalised profile when stored', function () {
    $pro = makeStaffWorkplaceUser([
        'name' => 'My Shop',
        'address' => '1 Smith St',
        'latitude' => -33.86,
        'longitude' => 151.21,
        'phone' => '+61 2 0000',
        'website' => 'https://myshop.example',
    ]);
    $controller = new StaffWorkplaceController;

    $response = $controller->show($pro);
    $body = $response->getData(true);

    $profile = $body['workplace'];
    expect($profile['name'])->toBe('My Shop')
        ->and($profile['latitude'])->toBe(-33.86)
        ->and($profile['longitude'])->toBe(151.21)
        ->and($profile)->not()->toHaveKey('hours')
        ->and($profile)->not()->toHaveKey('place_id');
});

it('returns 404 when the professional has no site', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'sole-'.substr($id, 0, 8),
        'handle_lc' => 'sole-'.substr($id, 0, 8),
        'display_name' => 'Sole',
        'primary_email' => 'sole-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    $pro = User::query()->find($id);

    $controller = new StaffWorkplaceController;
    $response = $controller->show($pro);

    expect($response->getStatusCode())->toBe(404);
});
