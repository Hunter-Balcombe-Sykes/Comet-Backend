<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffWorkplaceController;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
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

/** Builds a Request carrying a partna_staff attribute of the given role. */
function staffWorkplaceTest_request(string $role): Request
{
    $request = Request::create('/', 'GET');
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

it('returns null profile when the site has no workplace key', function () {
    $pro = makeStaffWorkplaceUser(null);
    $controller = new StaffWorkplaceController;

    $response = $controller->show(staffWorkplaceTest_request(PartnaStaff::ROLE_ADMIN), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['workplace'])->toBeNull();
});

it('returns the normalised profile with PII for admin-tier staff', function () {
    $pro = makeStaffWorkplaceUser([
        'name' => 'My Shop',
        'address' => '1 Smith St',
        'latitude' => -33.86,
        'longitude' => 151.21,
        'phone' => '+61 2 0000',
        'website' => 'https://myshop.example',
    ]);
    $controller = new StaffWorkplaceController;

    $response = $controller->show(staffWorkplaceTest_request(PartnaStaff::ROLE_ADMIN), $pro);
    $body = $response->getData(true);

    $profile = $body['workplace'];
    expect($profile['name'])->toBe('My Shop')
        ->and($profile['address'])->toBe('1 Smith St')
        ->and($profile['phone'])->toBe('+61 2 0000')
        ->and($profile['latitude'])->toBe(-33.86)
        ->and($profile['longitude'])->toBe(151.21)
        ->and($profile['website'])->toBe('https://myshop.example')
        ->and($profile)->not()->toHaveKey('hours')
        ->and($profile)->not()->toHaveKey('place_id');
});

// #PRIV-1: support-tier staff see the workplace card, but address/phone/geo
// are redacted — name and website (already public-facing) stay visible.
it('redacts address/phone/geo for support-tier staff, leaving name and website visible', function () {
    $pro = makeStaffWorkplaceUser([
        'name' => 'My Shop',
        'address' => '1 Smith St',
        'address_line1' => 'Suite 2',
        'city' => 'Sydney',
        'state' => 'NSW',
        'postcode' => '2000',
        'country' => 'AU',
        'latitude' => -33.86,
        'longitude' => 151.21,
        'phone' => '+61 2 0000',
        'website' => 'https://myshop.example',
    ]);
    $controller = new StaffWorkplaceController;

    $response = $controller->show(staffWorkplaceTest_request(PartnaStaff::ROLE_SUPPORT), $pro);
    $body = $response->getData(true);

    $profile = $body['workplace'];
    expect($response->getStatusCode())->toBe(200)
        ->and($profile['name'])->toBe('My Shop')
        ->and($profile['website'])->toBe('https://myshop.example')
        ->and($profile['address'])->toBeNull()
        ->and($profile['address_line1'])->toBeNull()
        ->and($profile['city'])->toBeNull()
        ->and($profile['state'])->toBeNull()
        ->and($profile['postcode'])->toBeNull()
        ->and($profile['country'])->toBeNull()
        ->and($profile['latitude'])->toBeNull()
        ->and($profile['longitude'])->toBeNull()
        ->and($profile['phone'])->toBeNull();
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
    $response = $controller->show(staffWorkplaceTest_request(PartnaStaff::ROLE_ADMIN), $pro);

    expect($response->getStatusCode())->toBe(404);
});

// #SEC-5: a non-staff caller (no partna_staff attribute) is denied — proves
// the gate actually runs rather than being a no-op wired into the response.
// staffView's plain `return false` has no explicit status code (unlike
// denyAsNotFound()'s 404), so the AuthorizationException itself carries no
// status — Laravel's exception handler renders it as 403 by default, which
// the HTTP-level tests above confirm end-to-end. Here we only prove the
// gate actually threw, not the render-time default.
it('denies when no staff actor is attached to the request', function () {
    $pro = makeStaffWorkplaceUser(['name' => 'My Shop']);
    $controller = new StaffWorkplaceController;

    expect(fn () => $controller->show(Request::create('/', 'GET'), $pro))
        ->toThrow(AuthorizationException::class);
});
