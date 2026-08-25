<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();

    // The staff.audit middleware writes here AFTER the response — without the
    // table the request 500s once the controller has already succeeded.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT NULL, action TEXT NULL, created_at TEXT NULL
    )');
});

// Local by design — cross-file Pest helpers break under --parallel.
function staffTokenBuild(): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);

    $build = PreAccountBuild::factory()->make([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'build_state' => PreAccountBuild::STATE_READY,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

function adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('mints a fresh token and invalidates the previous one', function () {
    $build = staffTokenBuild();
    $old = app(ClaimTokenIssuer::class)->issue($build);

    $response = actingAsStaff(adminStaff())
        ->postJson("/api/staff/builds/{$build->id}/claim-token")
        ->assertStatus(200);

    expect($response->json('claim_url'))->toContain('?t=')
        ->and(app(ClaimTokenIssuer::class)->matches($build->fresh(), $old))->toBeFalse();
});

it('refuses to re-issue for an already-claimed build', function () {
    $build = staffTokenBuild();
    $build->forceFill(['claimed_at' => now()])->save();

    actingAsStaff(adminStaff())
        ->postJson("/api/staff/builds/{$build->id}/claim-token")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ALREADY_CLAIMED');
});

it('refuses an unauthenticated caller', function () {
    $build = staffTokenBuild();

    $this->postJson("/api/staff/builds/{$build->id}/claim-token")->assertStatus(401);
});
