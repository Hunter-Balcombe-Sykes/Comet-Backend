<?php

/**
 * #P1-01 — StaffUserController index/show: skeleton-system fix.
 *
 * Confirms that:
 *   - index() no longer crashes on the removed site.theme eager-load
 *   - show()  no longer crashes on the removed site.theme / unused services/blocks loads
 *   - Neither response ships architecture_id or theme in the site payload
 *
 * Route-level companion: StaffUserShowPiiTest section (c) exercises this same
 * show()/index() PII gate over real HTTP; this file's direct-controller calls
 * stay to pin the #P1-01 crash regression they were written for.
 */

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Build a Request carrying a partna_staff attribute of the given role, as the staff middleware would. */
function requestAsStaff(string $role = PartnaStaff::ROLE_SUPPORT): Request
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;

    $request = Request::create('/', 'GET');
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 18: show() now also eager-loads preAccountBuild.
    setupPreAccountBuildsTable();
});

/** Insert a professional + linked site row; returns the loaded User model. */
function seedProfessionalWithSite(string $architectureId = 'staple'): User
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    $handle = 'pro-'.Str::random(6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Test Pro',
        'first_name' => 'Test',
        'primary_email' => 'pro-'.Str::random(6).'@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'pro-'.Str::random(4),
        'architecture_id' => $architectureId,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return User::query()->findOrFail($userId);
}

// ---------------------------------------------------------------------------
// index() — GET /api/staff/professionals
// ---------------------------------------------------------------------------

it('index returns 200 and does not crash without a theme relationship', function () {
    seedProfessionalWithSite();

    $controller = app(StaffUserController::class);
    $request = Request::create('/', 'GET');

    $response = $controller->index($request);

    expect($response->getStatusCode())->toBe(200);
});

it('index omits architecture_id and theme from the site payload', function () {
    seedProfessionalWithSite('staple');

    $controller = app(StaffUserController::class);
    $request = Request::create('/', 'GET');

    $response = $controller->index($request);
    $body = json_decode($response->getContent(), true);

    // At least one professional with a site should be in the results.
    $withSite = collect($body['professionals'])->firstWhere(fn ($p) => $p['site'] !== null);

    // architecture_id left the staff wire 2026-08-20; theme has been gone since
    // site.themes was dropped.
    expect($withSite)->not->toBeNull()
        ->and($withSite['site'])->not->toHaveKey('architecture_id')
        ->and($withSite['site'])->not->toHaveKey('theme');
});

it('index site payload is null when the professional has no site', function () {
    // Insert a user with no site row.
    $userId = (string) Str::uuid();
    $handle = 'no-site-'.Str::random(4);
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'No Site Pro',
        'first_name' => 'No',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $controller = app(StaffUserController::class);
    $request = Request::create('/', 'GET');

    $response = $controller->index($request);
    $body = json_decode($response->getContent(), true);

    $noSitePro = collect($body['professionals'])->firstWhere('id', $userId);

    expect($noSitePro)->not->toBeNull()
        ->and($noSitePro['site'])->toBeNull();
});

// ---------------------------------------------------------------------------
// show() — GET /api/staff/professionals/{professional}
// ---------------------------------------------------------------------------

it('show returns 200 and does not crash without a theme relationship', function () {
    $pro = seedProfessionalWithSite();

    $controller = app(StaffUserController::class);

    $response = $controller->show(requestAsStaff(), $pro);

    expect($response->getStatusCode())->toBe(200);
});

it('show omits architecture_id and theme from the site payload', function () {
    $pro = seedProfessionalWithSite('staple');

    $controller = app(StaffUserController::class);
    $response = $controller->show(requestAsStaff(), $pro);
    $body = json_decode($response->getContent(), true);

    expect($body['site'])->not->toBeNull()
        ->and($body['site'])->not->toHaveKey('architecture_id')
        ->and($body['site'])->not->toHaveKey('theme');
});

it('show site payload is null when the professional has no site', function () {
    $userId = (string) Str::uuid();
    $handle = 'no-site-show-'.Str::random(4);
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'No Site Show Pro',
        'first_name' => 'No',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    $pro = User::query()->findOrFail($userId);

    $controller = app(StaffUserController::class);
    $response = $controller->show(requestAsStaff(), $pro);
    $body = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['site'])->toBeNull();
});
