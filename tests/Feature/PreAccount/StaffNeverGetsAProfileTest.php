<?php

/**
 * No lane mints a professional profile for a staff account.
 *
 * The boot hole is closed (LoadCurrentUser::handleMissingProfile), but that
 * closing is a frontend-visible policy and the frontend is not where the rule
 * belongs. StaffProvisioningGuard is the same rule at the write, and this pins
 * it on the two lanes that bind an auth user to a core.users row:
 *
 *   - POST /api/claim        — the LIVE lane. Bootstrap's create branch is
 *                              HTTP-dead behind 410 SIGNUP_MOVED, so this is the
 *                              only way over HTTP.
 *   - UserBootstrapService   — reachable internally; asserted at the service.
 *
 * A staff-only auth user must come away with no user row and no site — that IS
 * the assertion, not the status code.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Services\User\StaffProvisioningGuard;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable();
    setupPartnaStaffTable();
    DB::connection('pgsql')->statement('DELETE FROM core.partna_staff');
    Queue::fake();
});

function seedStaffRow(string $authUserId, string $email = 'staff@example.test'): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->forceFill([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $authUserId,
        'role' => 'admin',
        'primary_email' => $email,
        'name' => 'Staff Owner',
    ]);
    $staff->save();

    return $staff;
}

it('refuses to let a staff account claim a site, and creates nothing', function () {
    makeReadyBuild(); // subdomain 'janedoe', provisional user with auth_user_id NULL
    seedStaffRow('staff-auth-uid', 'staff@example.test');

    $usersBefore = DB::connection('pgsql')->table('core.users')->count();

    actingAsUser(claimJwtUser('staff-auth-uid', 'staff@example.test'));

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'STAFF_ACCOUNT_NO_PROFILE');

    // The provisional row is untouched and unbound — no new row, nothing claimed.
    expect(DB::connection('pgsql')->table('core.users')->count())->toBe($usersBefore);
    expect(DB::connection('pgsql')->table('core.users')->where('auth_user_id', 'staff-auth-uid')->count())->toBe(0);

    // And the staff row itself survives the refusal untouched.
    expect(DB::connection('pgsql')->table('core.partna_staff')->where('auth_user_id', 'staff-auth-uid')->count())->toBe(1);
});

it('lets a non-staff auth user claim the same build', function () {
    makeReadyBuild();
    seedStaffRow('some-other-staff-uid');

    actingAsUser(claimJwtUser('civilian-uid', 'jane@example.com'));

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])->assertOk();

    expect(DB::connection('pgsql')->table('core.users')->where('auth_user_id', 'civilian-uid')->count())->toBe(1);
});

it('refuses the bootstrap create branch for a staff account', function () {
    seedStaffRow('staff-bootstrap-uid', 'boss@example.test');

    $service = app(UserBootstrapService::class);

    expect(fn () => $service->bootstrap('staff-bootstrap-uid', [
        'handle' => 'boss',
        'handle_lc' => 'boss',
        'display_name' => 'Boss',
        'primary_email' => 'boss@example.test',
        'first_name' => 'Boss',
    ]))->toThrow(RuntimeException::class, StaffProvisioningGuard::REJECTION);

    expect(DB::connection('pgsql')->table('core.users')->where('auth_user_id', 'staff-bootstrap-uid')->count())->toBe(0);
    expect(DB::connection('pgsql')->table('site.sites')->where('subdomain', 'boss')->count())->toBe(0);
});
