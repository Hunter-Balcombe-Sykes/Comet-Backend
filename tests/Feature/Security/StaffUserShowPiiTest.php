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
 *
 * COV-PII: sections (a)/(b) above call the resource/controller directly and
 * never traverse HTTP, so they can't prove a real request reaches the gate.
 * Section (c) adds that over real HTTP via actingAsStaff() (four-leg control:
 * key-exists / value-redacted / non-PII-survives / admin-positive-control).
 * actingAsStaff() itself stubs out EnsurePartnaStaff (see tests/Pest.php),
 * so section (d) repeats the positive/negative pair through the REAL
 * middleware via actingAsUser() + a seeded core.partna_staff row. Section (e)
 * covers the sibling index() tier split (StaffUserListResource).
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
    // Task 18: show() now also eager-loads preAccountBuild.
    setupPreAccountBuildsTable();

    // admin_notes isn't part of the shared core.users stub in tests/Pest.php —
    // add it defensively, mirroring the sector/sector_source ALTER pattern there.
    try {
        DB::connection('pgsql')->statement('ALTER TABLE core.users ADD COLUMN admin_notes TEXT NULL');
    } catch (Throwable) {
        // already exists — ignore
    }

    // staff.audit middleware writes here on every request through the real
    // HTTP route (sections (c)/(d)/(e) below). Insert failures are
    // double-swallowed, so this is just to keep logs clean.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
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
        'handle' => $handle = 'pro-'.Str::random(6),
        'handle_lc' => strtolower($handle),
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
        'account_type' => 'partna',
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

// ---------------------------------------------------------------------------
// (c) Route-level — real HTTP through actingAsStaff() (COV-PII).
//
// (a)/(b) above never traverse HTTP, and the audit's literal prescription
// ("assert primary_email absent") is vacuous: UserStaffResource emits gated
// PII as present-and-null, and assertJsonPath(..., null) resolves via
// data_get(), which also returns null for a MISSING key — so that assertion
// alone would pass against an empty body or a renamed key. This four-leg
// control is the fix: A) the key exists at all, B) its value is null for
// support, C) non-PII fields are untouched (proving the gate discriminates
// rather than nulling everything), D) the same fields carry the real seeded
// values for admin (proving B's null is a gate result, not an accident of a
// field that can never be populated on this route).
// ---------------------------------------------------------------------------

/** The 10 fields UserStaffResource gates behind $showPii (UserStaffResource.php:33-53). */
function staffPiiTest_gatedFields(): array
{
    return [
        'auth_user_id', 'phone', 'primary_email', 'public_contact_number',
        'location_street_address', 'location_city', 'location_state',
        'location_postcode', 'location_country', 'admin_notes',
    ];
}

it('show() route-level: four-leg PII control over real HTTP (support redacted, admin populated, secret never leaks to support)', function () {
    $pro = staffPiiTest_seedProfessional();
    $piiFields = staffPiiTest_gatedFields();

    // --- Support tier ---
    $supportResponse = actingAsStaff(staffPiiTest_makeStaff(PartnaStaff::ROLE_SUPPORT))
        ->getJson("/api/staff/professionals/{$pro->id}");

    $supportResponse->assertOk();

    // A. Key exists — assertJsonStructure fails on a MISSING key but passes on
    // a null value, so this alone rules out an empty body / renamed key.
    $supportResponse->assertJsonStructure(['professional' => $piiFields]);

    // B. Value redacted for every gated field.
    foreach ($piiFields as $field) {
        $supportResponse->assertJsonPath("professional.{$field}", null);
    }

    // C. Non-PII survives untouched. public_contact_email is the near-twin of
    // gated primary_email — proves the gate discriminates, not "null everything".
    $supportResponse->assertJsonPath('professional.display_name', 'Ada L')
        ->assertJsonPath('professional.first_name', 'Ada')
        ->assertJsonPath('professional.last_name', 'Lovelace')
        ->assertJsonPath('professional.public_contact_email', 'ada-public@example.test');

    // Fifth leg: the seeded private email must not appear ANYWHERE in the
    // support response body, not just at the expected JSON path.
    $this->assertStringNotContainsString($pro->primary_email, $supportResponse->getContent());

    // --- Admin tier (D. positive control) ---
    $adminResponse = actingAsStaff(staffPiiTest_makeStaff(PartnaStaff::ROLE_ADMIN))
        ->getJson("/api/staff/professionals/{$pro->id}");

    $adminResponse->assertOk();

    foreach ($piiFields as $field) {
        $adminResponse->assertJsonPath("professional.{$field}", $pro->{$field});
    }

    $this->assertStringContainsString($pro->primary_email, $adminResponse->getContent());
});

// ---------------------------------------------------------------------------
// (d) Real EnsurePartnaStaff — actingAsStaff() stubs EnsurePartnaStaff out
// entirely (tests/Pest.php docblock), so section (c) never exercises the
// actual core.partna_staff lookup by auth_user_id. actingAsUser() only stubs
// VerifySupabaseJwt + LoadCurrentUser, leaving EnsurePartnaStaff real —
// mirrors the negative case in tests/Schema/StaffUserSearchFiltersTest.php.
// Both roles are checked here so a stub/real divergence in role resolution
// would surface as a red test rather than silently passing.
// ---------------------------------------------------------------------------

/** Insert a core.partna_staff row with a known auth_user_id + role; returns the auth_user_id. */
function staffPiiTest_seedRealPartnaStaff(string $role): string
{
    $authUserId = 'real-staff-auth-'.Str::random(12);

    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $authUserId,
        'role' => $role,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $authUserId;
}

it('resolves support role via the REAL EnsurePartnaStaff middleware (not the actingAsStaff stub) and still redacts', function () {
    $pro = staffPiiTest_seedProfessional();
    $authUserId = staffPiiTest_seedRealPartnaStaff(PartnaStaff::ROLE_SUPPORT);

    $actor = new User(['primary_email' => null]);
    $actor->auth_user_id = $authUserId;

    $response = actingAsUser($actor, aal2ClaimsWithFreshTotp())
        ->getJson("/api/staff/professionals/{$pro->id}");

    $response->assertOk()
        ->assertJsonPath('professional.primary_email', null)
        ->assertJsonPath('professional.admin_notes', null)
        ->assertJsonPath('professional.display_name', 'Ada L');
});

it('resolves admin role via the REAL EnsurePartnaStaff middleware and exposes PII', function () {
    $pro = staffPiiTest_seedProfessional();
    $authUserId = staffPiiTest_seedRealPartnaStaff(PartnaStaff::ROLE_ADMIN);

    $actor = new User(['primary_email' => null]);
    $actor->auth_user_id = $authUserId;

    $response = actingAsUser($actor, aal2ClaimsWithFreshTotp())
        ->getJson("/api/staff/professionals/{$pro->id}");

    $response->assertOk()
        ->assertJsonPath('professional.primary_email', $pro->primary_email)
        ->assertJsonPath('professional.admin_notes', $pro->admin_notes);
});

// ---------------------------------------------------------------------------
// (e) index() tier split — StaffUserListResource gates the same fields, but
// index() has NO authorizeForUser call at all (StaffUserController.php:36-39
// docblock): $showPii is the only enforcement point on this endpoint.
// ---------------------------------------------------------------------------

it('index() route-level: redacts primary_email + phone for support-tier staff, exposes them for admin', function () {
    $pro = staffPiiTest_seedProfessional();

    $supportResponse = actingAsStaff(staffPiiTest_makeStaff(PartnaStaff::ROLE_SUPPORT))
        ->getJson('/api/staff/professionals');
    $supportResponse->assertOk();
    $supportRow = collect($supportResponse->json('professionals'))->firstWhere('id', $pro->id);

    expect($supportRow)->not->toBeNull()
        ->and($supportRow['primary_email'])->toBeNull()
        ->and($supportRow['phone'])->toBeNull()
        ->and($supportRow['display_name'])->toBe('Ada L');

    $adminResponse = actingAsStaff(staffPiiTest_makeStaff(PartnaStaff::ROLE_ADMIN))
        ->getJson('/api/staff/professionals');
    $adminResponse->assertOk();
    $adminRow = collect($adminResponse->json('professionals'))->firstWhere('id', $pro->id);

    expect($adminRow)->not->toBeNull()
        ->and($adminRow['primary_email'])->toBe($pro->primary_email)
        ->and($adminRow['phone'])->toBe($pro->phone);
});
