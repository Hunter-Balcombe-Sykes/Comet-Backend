<?php

use App\Models\Core\User\User;

/**
 * D2a — handle_lc must mirror handle on every model-layer write.
 *
 * handle_lc is the column every resolver reads (SiteResolver, the public
 * profile cache key, SyncSubdomainToKvJob). A row where the two disagree is
 * invisible to the entire public read path while looking perfectly healthy in
 * the dashboard — which is how it cost real debugging time during the
 * 2026-07-31 Redis drill: a fixture created with `handle` set and `handle_lc`
 * left at its factory value simply did not resolve.
 */
beforeEach(function () {
    setupUsersTable();
});

it('derives handle_lc from handle on assignment, before save', function () {
    $user = new User(['handle' => 'Drill-WK-20260731']);

    // In memory, not just in the database — a `saving` hook would leave this
    // wrong until save(), which is the exact shape of the original bug.
    expect($user->handle_lc)->toBe('drill-wk-20260731');
});

it('derives handle_lc when only handle is mass-assigned', function () {
    $user = User::create([
        'id' => (string) Str::uuid(),
        'handle' => 'SoloPro01',
        'display_name' => 'Solo Pro',
        'first_name' => 'Solo',
        'last_name' => 'Pro',
    ]);

    expect($user->fresh()->handle_lc)->toBe('solopro01');
});

it('derives handle_lc when a factory override sets only handle', function () {
    // The regression that actually bit. The factory used to seed handle_lc
    // itself, and array_merge preserves the definition's key ORDER — so the
    // factory's stale handle_lc landed AFTER the override's handle and
    // clobbered the mutator's output. The mutator alone does not fix this;
    // the factory must leave handle_lc to be derived.
    $user = User::factory()->create(['handle' => 'drill-wk-20260731']);

    expect($user->handle_lc)->toBe('drill-wk-20260731')
        ->and($user->fresh()->handle_lc)->toBe('drill-wk-20260731');
});

it('still honours an explicit handle_lc override', function () {
    // Deliberate desync is what the fail-closed tests in this suite rely on;
    // the mutator must not make it impossible to construct.
    $user = User::factory()->create([
        'handle' => 'MixedCase',
        'handle_lc' => 'deliberately-different',
    ]);

    expect($user->fresh()->handle_lc)->toBe('deliberately-different');
});

it('leaves handle_lc null when handle is null', function () {
    $user = new User;
    $user->handle = null;

    expect($user->handle_lc)->toBeNull();
});

it('trims BOTH columns so the DB CHECK can never reject what the model built', function () {
    // D2b. `users_handle_lc_matches_handle` is the plain `handle_lc =
    // lower(handle)` — no btrim in the predicate, deliberately. If the model
    // trimmed only handle_lc, ' Bob ' would yield handle_lc 'bob' against
    // lower(handle) ' bob ' and Postgres would reject a row the model happily
    // produced, as a 23514 on a write path. Trimming both makes the constraint
    // true by construction.
    //
    // Unreachable through the API today — every write path validates
    // regex:/^[a-z0-9_-]+$/i — so this pins the invariant, not a live bug.
    $user = new User(['handle' => '  Spaced-Handle  ']);

    expect($user->handle)->toBe('Spaced-Handle')
        ->and($user->handle_lc)->toBe('spaced-handle')
        ->and($user->handle_lc)->toBe(mb_strtolower($user->handle));
});
