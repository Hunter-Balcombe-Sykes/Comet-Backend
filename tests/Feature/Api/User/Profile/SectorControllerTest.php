<?php

// Profile sector endpoints: the curated picker options + the manual set (with
// slug validation + sector_source='manual' stamping).

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function sectorUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'active',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

// ── (e) GET /profile/sector-options ──────────────────────────────────────────

it('returns sector options grouped into sections', function () {
    $user = sectorUser('sectlist');

    $response = actingAsUser($user)->getJson('/api/profile/sector-options')->assertOk();

    $groups = $response->json('groups');
    expect($groups)->toBeArray()->not->toBeEmpty();

    // Each group has a name + a non-empty options list of {slug,label}.
    $first = $groups[0];
    expect($first)->toHaveKeys(['group', 'options']);
    expect($first['options'][0])->toHaveKeys(['slug', 'label']);

    // A known slug is present somewhere in the flattened option set.
    $slugs = collect($groups)->flatMap(fn ($g) => array_column($g['options'], 'slug'));
    expect($slugs)->toContain('barber');
    expect($slugs)->toContain('photographer');
});

// ── (f) PUT /profile/sector ──────────────────────────────────────────────────

it('rejects an invalid sector slug', function () {
    $user = sectorUser('sectbad');

    actingAsUser($user)->putJson('/api/profile/sector', ['sector' => 'not-a-real-sector'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sector');

    $user->refresh();
    expect($user->sector)->toBeNull();
});

it('accepts a valid sector slug and stamps sector_source=manual', function () {
    $user = sectorUser('sectgood');

    actingAsUser($user)->putJson('/api/profile/sector', ['sector' => 'photographer'])
        ->assertOk()
        ->assertJsonPath('sector', 'photographer');

    $user->refresh();
    expect($user->sector)->toBe('photographer');
    expect($user->sector_source)->toBe('manual');
});

it('accepts a null sector to clear the field — clearing also clears provenance so Google may fill again', function () {
    $user = sectorUser('sectclear');
    $user->forceFill(['sector' => 'barber', 'sector_source' => 'google-business'])->save();

    actingAsUser($user)->putJson('/api/profile/sector', ['sector' => null])
        ->assertOk()
        ->assertJsonPath('sector', null);

    $user->refresh();
    expect($user->sector)->toBeNull();
    // A cleared pick carries NO provenance — (null, 'manual') would permanently
    // block IdentitySync's Google fill and freeze sector-derived capabilities.
    expect($user->sector_source)->toBeNull();
});

// ── SEC-105: ownership gate (defence-in-depth) ────────────────────────────
// update() now authorizeForUser's the caller against themselves. A non-owner
// can't reach this endpoint at all (currentUser() always resolves the JWT's
// own row), and a pending-deletion caller is already blocked at the HTTP
// edge by EnforcePendingDeletionReadOnly (423) before the controller runs —
// so the deny path can only be exercised at the policy layer directly.

it('denies a pending-deletion user at the policy layer with 423 (HTTP edge already blocks this via middleware)', function () {
    $user = sectorUser('sectpending');
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update(['status' => 'pending_deletion']);
    $user->refresh();

    $response = Gate::forUser($user)->inspect('update', $user);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
