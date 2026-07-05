<?php

// Profile sector endpoints: the curated picker options + the manual set (with
// slug validation + sector_source='manual' stamping).

use App\Models\Core\User\User;
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

it('accepts a null sector to clear the field', function () {
    $user = sectorUser('sectclear');
    $user->forceFill(['sector' => 'barber', 'sector_source' => 'google-business'])->save();

    actingAsUser($user)->putJson('/api/profile/sector', ['sector' => null])
        ->assertOk()
        ->assertJsonPath('sector', null);

    $user->refresh();
    expect($user->sector)->toBeNull();
    expect($user->sector_source)->toBe('manual');
});
