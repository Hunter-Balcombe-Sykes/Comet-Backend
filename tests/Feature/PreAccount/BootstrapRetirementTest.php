<?php

/**
 * Task 14 (Pre-Account Sites): retires the bootstrap create branch. A JWT
 * whose `sub` has no core.users row now 410s SIGNUP_MOVED instead of
 * creating an account — signup is site-first now (POST
 * /api/public/signup/build, Task 11 + POST /api/claim, Task 13). The
 * update/refresh path for an EXISTING user is untouched.
 *
 * Runs at the real HTTP route (not the controller directly) so the route
 * wiring + throttle:bootstrap group stay covered, and reuses the
 * claimJwtUser() helper (suite-global, tests/Pest.php) to model a JWT sub
 * with no backing core.users row.
 */

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('410s a JWT with no existing user row, pointing at the new flow', function () {
    actingAsUser(claimJwtUser('brand-new-uid', 'new@example.com'));

    $this->postJson('/api/bootstrap', ['display_name' => 'New Person', 'primary_email' => 'new@example.com'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'SIGNUP_MOVED');
});

it('still refreshes an existing user end-to-end (update path untouched)', function () {
    // Raw insert (not User::create) — auth_user_id is deliberately not
    // fillable, mirroring the pattern ClaimEndpointTest/BootstrapWaitlistGateTest use.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-0000000000f1',
        'auth_user_id' => 'existing-uid',
        'primary_email' => 'existing@example.com',
        'handle' => 'existinghandle',
        'handle_lc' => 'existinghandle',
        'display_name' => 'Existing User',
        'first_name' => 'Existinghandle',
        'status' => 'active',
        'account_type' => 'partna',
    ]);

    $professional = new User([
        'handle' => 'existinghandle',
        'display_name' => 'Existing User',
        'primary_email' => 'existing@example.com',
        'status' => 'active',
        'account_type' => 'partna',
    ]);
    $professional->id = '00000000-0000-0000-0000-0000000000f1';
    $professional->auth_user_id = 'existing-uid';

    // 'id' isn't fillable on Site (mass-assignment protected) — set directly.
    $site = new Site(['subdomain' => 'existinghandle']);
    $site->id = '00000000-0000-0000-0000-0000000000f2';

    // Stub the create-or-update transaction — this test proves the
    // CONTROLLER's update-path routing/response shape is byte-identical to
    // before Task 14, not UserBootstrapService's internals (covered by
    // BootstrapEmailRaceTest / SiteProvisioningSavepointTest etc.).
    $this->instance(UserBootstrapService::class, Mockery::mock(UserBootstrapService::class, function ($mock) use ($professional, $site) {
        $mock->shouldReceive('bootstrap')->once()->andReturn([
            'professional' => $professional,
            'site' => $site,
            'created' => false,
        ]);
    }));

    actingAsUser($professional);

    $this->postJson('/api/bootstrap', [
        'display_name' => 'Existing User',
        'primary_email' => 'existing@example.com',
        'handle' => 'existinghandle',
    ])
        ->assertOk()
        ->assertJsonStructure(['professional' => ['id', 'display_name', 'status'], 'site' => ['id', 'subdomain']]);
});

// ─── Dead-block reachability proof ─────────────────────────────────────────
// WAITLIST_ONLY and the individual-waitlist divert were each explicitly gated
// on `! hasExistingProfessional($uid)`, so they're provably dead now that such
// callers 410 first — no config toggle can revive them for a brand-new signup.
// The invite-token block ran UNCONDITIONALLY (no row-less gate); its removal
// is proven dead for new users by the 410 below, and — because it used to run
// for existing users too — the relaxation for existing users (stale invite
// param now refreshes normally instead of 422ing) is pinned separately below.

it('still 410s a new user even with waitlist mode enabled (WAITLIST_ONLY block is dead)', function () {
    config(['partna.waitlist.enabled' => true]);

    actingAsUser(claimJwtUser('waitlist-new-uid', 'waitlisted@example.com'));

    $this->postJson('/api/bootstrap', ['display_name' => 'New Person', 'primary_email' => 'waitlisted@example.com'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'SIGNUP_MOVED');
});

it('still 410s a new user even with a would-be invite token in the body (invite block is dead)', function () {
    actingAsUser(claimJwtUser('invite-new-uid', 'invitee@example.com'));

    $this->postJson('/api/bootstrap', [
        'display_name' => 'New Person',
        'primary_email' => 'invitee@example.com',
        'invite' => 'some-token',
    ])
        ->assertStatus(410)
        ->assertJsonPath('code', 'SIGNUP_MOVED');
});

// ─── Existing-user invite relaxation (intentional behavior change) ────────
// Unlike the two waitlist blocks above, the invite-token block was NOT
// gated on `! hasExistingProfessional($uid)` — it ran unconditionally, so
// under the OLD code an existing user posting a garbage `invite` param got
// 422 INVITE_INVALID. Bootstrap no longer consumes `invite` at all (contract
// item 4/F5), so that same request is now a normal 200 refresh — this pins
// the relaxation so it can't regress silently.
it('refreshes an existing user normally even with a garbage invite param (invite no longer consumed)', function () {
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-0000000000f4',
        'auth_user_id' => 'existing-invite-uid',
        'primary_email' => 'existinginvite@example.com',
        'handle' => 'existinginvitehandle',
        'handle_lc' => 'existinginvitehandle',
        'display_name' => 'Existing Invite User',
        'first_name' => 'Existinginvitehandle',
        'status' => 'active',
        'account_type' => 'partna',
    ]);

    $professional = (new User)->forceFill([ // B11 SEC-2: status no longer fillable
        'handle' => 'existinginvitehandle',
        'display_name' => 'Existing Invite User',
        'primary_email' => 'existinginvite@example.com',
        'status' => 'active',
        'account_type' => 'partna',
    ]);
    $professional->id = '00000000-0000-0000-0000-0000000000f4';
    $professional->auth_user_id = 'existing-invite-uid';

    $site = new Site(['subdomain' => 'existinginvitehandle']);
    $site->id = '00000000-0000-0000-0000-0000000000f5';

    $this->instance(UserBootstrapService::class, Mockery::mock(UserBootstrapService::class, function ($mock) use ($professional, $site) {
        $mock->shouldReceive('bootstrap')->once()->andReturn([
            'professional' => $professional,
            'site' => $site,
            'created' => false,
        ]);
    }));

    actingAsUser($professional);

    $this->postJson('/api/bootstrap', [
        'display_name' => 'Existing Invite User',
        'primary_email' => 'existinginvite@example.com',
        'handle' => 'existinginvitehandle',
        'invite' => 'garbage-invite-token',
    ])
        ->assertOk()
        ->assertJsonPath('professional.status', 'active');
});

it('still requires BootstrapRequest validation before the 410 (handle_lc uniqueness still enforced)', function () {
    // Reuses an existing handle_lc — proves the 410 short-circuit happens
    // AFTER FormRequest validation (Laravel resolves+validates the injected
    // BootstrapRequest before the controller body runs), not before it.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-0000000000f3',
        'auth_user_id' => 'someone-else-uid',
        'primary_email' => 'other@example.com',
        'handle' => 'takenhandle',
        'handle_lc' => 'takenhandle',
        'display_name' => 'Someone Else',
        'first_name' => 'Takenhandle',
        'status' => 'active',
        'account_type' => 'partna',
    ]);

    actingAsUser(claimJwtUser('validation-new-uid', 'validationtest@example.com'));

    $this->postJson('/api/bootstrap', [
        'display_name' => 'New Person',
        'primary_email' => 'validationtest@example.com',
        'handle' => 'takenhandle',
    ])->assertStatus(422);
});
