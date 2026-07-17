<?php

/**
 * #SEC-101 — StaffUserController::show() PII gate.
 *
 * Previously UserStaffResource returned full PII (auth_user_id, phone,
 * primary_email, public_contact_number, location_*, admin_notes) to ANY
 * staff role on the detail view (GET /professionals/{id}), while index()
 * already gated the same fields to admin staff via $showPii. This proves
 * the fix: the resource redacts by default, and the controller only flips
 * $showPii on for admin-role staff — mirroring the index() pattern exactly.
 */

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use App\Http\Resources\UserStaffResource;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();

    // admin_notes isn't part of the shared core.users stub in tests/Pest.php —
    // add it defensively, mirroring the sector/sector_source ALTER pattern there.
    try {
        DB::connection('pgsql')->statement('ALTER TABLE core.users ADD COLUMN admin_notes TEXT NULL');
    } catch (Throwable) {
        // already exists — ignore
    }
});

/** Build an unsaved User model with every PII field populated (resource-unit tests). */
function staffPiiTest_buildUser(): User
{
    $professional = new User;
    $professional->id = (string) Str::uuid();
    $professional->auth_user_id = 'auth-uid-123';
    $professional->display_name = 'Ada L';
    $professional->first_name = 'Ada';
    $professional->last_name = 'Lovelace';
    $professional->phone = '+61000000000';
    $professional->primary_email = 'ada-private@example.test';
    $professional->public_contact_number = '+61111111111';
    $professional->public_contact_email = 'ada-public@example.test';
    $professional->location_street_address = '1 Test St';
    $professional->location_city = 'Sydney';
    $professional->location_state = 'NSW';
    $professional->location_postcode = '2000';
    $professional->location_country = 'AU';
    $professional->admin_notes = 'VIP — handle with care';

    return $professional;
}

/** Insert a professional row with every PII field populated (controller-integration tests). */
function staffPiiTest_seedProfessional(): User
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => 'auth-uid-'.Str::random(8),
        'handle' => 'pro-'.Str::random(6),
        'display_name' => 'Ada L',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'phone' => '+61000000000',
        'primary_email' => 'ada-private-'.Str::random(6).'@example.test',
        'public_contact_number' => '+61111111111',
        'public_contact_email' => 'ada-public@example.test',
        'location_street_address' => '1 Test St',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_postcode' => '2000',
        'location_country' => 'AU',
        'admin_notes' => 'VIP — handle with care',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return User::query()->findOrFail($id);
}

/** Build an unsaved PartnaStaff of the given role. */
function staffPiiTest_makeStaff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;

    return $staff;
}

/** Build a Request carrying the given PartnaStaff attribute, as the staff middleware would. */
function staffPiiTest_requestAsStaff(PartnaStaff $staff): Request
{
    $request = Request::create('/', 'GET');
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

// ---------------------------------------------------------------------------
// (a) Resource-unit tests — UserStaffResource redaction
// ---------------------------------------------------------------------------

it('redacts PII fields when showPii is false', function () {
    $shape = (new UserStaffResource(staffPiiTest_buildUser(), false))->toArray(request());

    expect($shape['auth_user_id'])->toBeNull()
        ->and($shape['phone'])->toBeNull()
        ->and($shape['primary_email'])->toBeNull()
        ->and($shape['public_contact_number'])->toBeNull()
        ->and($shape['location_street_address'])->toBeNull()
        ->and($shape['location_city'])->toBeNull()
        ->and($shape['location_state'])->toBeNull()
        ->and($shape['location_postcode'])->toBeNull()
        ->and($shape['location_country'])->toBeNull()
        ->and($shape['admin_notes'])->toBeNull()
        // Non-PII fields stay visible regardless of the gate.
        ->and($shape['first_name'])->toBe('Ada')
        ->and($shape['last_name'])->toBe('Lovelace')
        ->and($shape['display_name'])->toBe('Ada L')
        ->and($shape['public_contact_email'])->toBe('ada-public@example.test');
});

it('defaults showPii to false when constructed without the second argument', function () {
    $shape = (new UserStaffResource(staffPiiTest_buildUser()))->toArray(request());

    expect($shape['primary_email'])->toBeNull()
        ->and($shape['admin_notes'])->toBeNull();
});

it('exposes PII fields when showPii is true', function () {
    $shape = (new UserStaffResource(staffPiiTest_buildUser(), true))->toArray(request());

    expect($shape['auth_user_id'])->toBe('auth-uid-123')
        ->and($shape['phone'])->toBe('+61000000000')
        ->and($shape['primary_email'])->toBe('ada-private@example.test')
        ->and($shape['public_contact_number'])->toBe('+61111111111')
        ->and($shape['location_street_address'])->toBe('1 Test St')
        ->and($shape['location_city'])->toBe('Sydney')
        ->and($shape['location_state'])->toBe('NSW')
        ->and($shape['location_postcode'])->toBe('2000')
        ->and($shape['location_country'])->toBe('AU')
        ->and($shape['admin_notes'])->toBe('VIP — handle with care');
});

// ---------------------------------------------------------------------------
// (b) Controller-integration tests — StaffUserController::show()
// ---------------------------------------------------------------------------

it('show() returns PII fields as null for support-tier staff', function () {
    $pro = staffPiiTest_seedProfessional();
    $request = staffPiiTest_requestAsStaff(staffPiiTest_makeStaff(PartnaStaff::ROLE_SUPPORT));

    $controller = app(StaffUserController::class);
    $body = json_decode($controller->show($request, $pro)->getContent(), true);

    expect($body['professional']['primary_email'])->toBeNull()
        ->and($body['professional']['phone'])->toBeNull()
        ->and($body['professional']['admin_notes'])->toBeNull()
        ->and($body['professional']['auth_user_id'])->toBeNull()
        ->and($body['professional']['location_street_address'])->toBeNull()
        // Non-PII fields are unaffected.
        ->and($body['professional']['display_name'])->toBe('Ada L');
});

it('show() returns PII fields populated for admin-tier staff', function () {
    $pro = staffPiiTest_seedProfessional();
    $request = staffPiiTest_requestAsStaff(staffPiiTest_makeStaff(PartnaStaff::ROLE_ADMIN));

    $controller = app(StaffUserController::class);
    $body = json_decode($controller->show($request, $pro)->getContent(), true);

    expect($body['professional']['primary_email'])->not->toBeNull()
        ->and($body['professional']['phone'])->toBe('+61000000000')
        ->and($body['professional']['admin_notes'])->toBe('VIP — handle with care')
        ->and($body['professional']['auth_user_id'])->not->toBeNull()
        ->and($body['professional']['location_street_address'])->toBe('1 Test St');
});
