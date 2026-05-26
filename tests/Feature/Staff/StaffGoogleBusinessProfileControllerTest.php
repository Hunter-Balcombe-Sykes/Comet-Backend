<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffGoogleBusinessProfileController;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function makeStaffGbpUser(?array $gbp = null): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'gbp-'.substr($id, 0, 8),
        'handle_lc' => 'gbp-'.substr($id, 0, 8),
        'display_name' => 'GBP Pro',
        'primary_email' => 'gbp-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    $settings = $gbp !== null ? ['google_business_profile' => $gbp] : [];

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $id,
        'subdomain' => 'gbp-'.substr($id, 0, 8),
        'settings' => json_encode($settings),
        'is_published' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::query()->find($id);
}

it('returns null profile when the site has no google_business_profile key', function () {
    $pro = makeStaffGbpUser(null);
    $controller = new StaffGoogleBusinessProfileController;

    $response = $controller->show($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['google_business_profile'])->toBeNull();
});

it('returns the normalised profile when stored', function () {
    $pro = makeStaffGbpUser([
        'place_id' => 'ChIJabc',
        'name' => 'My Shop',
        'address' => '1 Smith St',
        'latitude' => '-33.86',
        'longitude' => '151.21',
        'phone' => '+61 2 0000',
        'website' => 'https://myshop.example',
        'hours' => ['Mon 9-5', '', 'Tue 9-5'],
    ]);
    $controller = new StaffGoogleBusinessProfileController;

    $response = $controller->show($pro);
    $body = $response->getData(true);

    $profile = $body['google_business_profile'];
    expect($profile['place_id'])->toBe('ChIJabc')
        ->and($profile['name'])->toBe('My Shop')
        ->and($profile['latitude'])->toBe(-33.86)
        ->and($profile['longitude'])->toBe(151.21)
        ->and($profile['hours'])->toBe(['Mon 9-5', 'Tue 9-5']); // empties filtered
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

    $controller = new StaffGoogleBusinessProfileController;
    $response = $controller->show($pro);

    expect($response->getStatusCode())->toBe(404);
});
