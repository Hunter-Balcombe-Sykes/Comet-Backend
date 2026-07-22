<?php

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();
    config(['app.frontend_url' => 'https://app.partna.au']);

    // Queue::fake(): a published Site::factory() row fires SiteObserver's
    // WarmPublicSiteCacheJob, and QUEUE_CONNECTION=sync in phpunit.xml runs it
    // inline against a table this test schema doesn't set up. Same guard as
    // ClaimSiteServiceTest.php / UnclaimedGatingTest.php for the same reason.
    Queue::fake();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
});

function inviteStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function readyBuild(?string $email = 'lead@example.com'): PreAccountBuild
{
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => true]);
    $build = PreAccountBuild::factory()->make(['contact_email' => $email, 'auto_invite' => false]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();

    return $build;
}

it('sends the invite and stamps invited_at', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $build = readyBuild();

    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(200)
        ->assertJsonPath('auto_invite', false)          // staff resource confirms outreach state
        ->assertJson(fn ($json) => $json->where('invited_at', fn ($v) => $v !== null)->etc());

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
    expect($build->fresh()->invited_at)->not->toBeNull();
});

it('rejects a second invite with ALREADY_INVITED', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $build = readyBuild();

    $this->postJson("/api/staff/builds/{$build->id}/invite")->assertStatus(200);
    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ALREADY_INVITED');

    Mail::assertQueued(ClaimInviteMail::class, 1);
});

it('rejects a build with no contact_email', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $build = readyBuild(email: null);

    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(422)
        ->assertJsonPath('code', 'NO_CONTACT_EMAIL');
    Mail::assertNothingQueued();
});

it('rejects a build that is not ready/published', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'pend', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['contact_email' => 'x@example.com']);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(409)
        ->assertJsonPath('code', 'BUILD_NOT_READY');
});

it('rejects non-staff callers', function () {
    $plain = User::factory()->create();
    $build = readyBuild();

    actingAsUser($plain)
        ->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(403);
});
