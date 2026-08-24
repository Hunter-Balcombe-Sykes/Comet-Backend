<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dark Until Claimed (2026-08-24): the second ungated public read path
// (IndividualProfileControllerTest covers the first). An unclaimed build must
// be vetted — staff-built, or a staff-approved early-access lead — to be
// resolvable at all.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

function seedUnclaimedUser(string $handle): User
{
    // status is not fillable — forceFill (mirrors PreAccountBuildService's own
    // provisional-user creation), or User::create() silently drops it and the
    // row defaults to whatever the schema default is, not 'unclaimed'.
    $user = new User;
    $user->forceFill([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'unclaimed',
    ]);
    $user->save();

    return $user;
}

function seedBuildFor(string $userId, ?string $builtByStaffId = null, ?string $builtVia = null, bool $expires = false): void
{
    DB::connection('pgsql')->table('core.pre_account_builds')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'source_type' => 'instagram',
        'source_ref' => 'seed',
        'source_ref_lc' => 'seed',
        'built_via' => $builtVia,
        'built_by_staff_id' => $builtByStaffId,
        'expires_at' => $expires ? now()->addDays(30)->toDateTimeString() : null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('404s an unclaimed self-serve user with no vetted build', function () {
    $user = seedUnclaimedUser('darkintegrations1');
    seedBuildFor($user->id, builtVia: 'signup');

    $this->getJson('/api/public/profiles/darkintegrations1/integrations')->assertNotFound();
});

it('serves an unclaimed, staff-built user', function () {
    $user = seedUnclaimedUser('darkintegrations2');
    seedBuildFor($user->id, builtByStaffId: (string) Str::uuid());

    $this->getJson('/api/public/profiles/darkintegrations2/integrations')->assertOk();
});

it('404s an unclaimed user with no pre-account build row at all', function () {
    seedUnclaimedUser('darkintegrations3');

    $this->getJson('/api/public/profiles/darkintegrations3/integrations')->assertNotFound();
});

it('serves a claimed (active) user regardless of build state', function () {
    $user = new User;
    $user->forceFill([
        'handle' => 'darkintegrations4',
        'handle_lc' => 'darkintegrations4',
        'display_name' => 'Active',
        'first_name' => 'Active',
        'account_type' => 'partna',
        'status' => 'active',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'darkintegrations4@example.com',
    ]);
    $user->save();

    $this->getJson('/api/public/profiles/darkintegrations4/integrations')->assertOk();
});
