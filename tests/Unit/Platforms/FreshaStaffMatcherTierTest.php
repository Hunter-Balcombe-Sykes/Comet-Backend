<?php

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaStaffMatcher;
use Tests\TestCase;

// tests/Unit is NOT bound to TestCase in Pest.php — this file needs the
// container and the SQLite users stand-in, so opt in per-file.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
});

function team(array ...$members): array
{
    return array_map(
        static fn (array $m): array => ['employeeId' => $m[0], 'displayName' => $m[1]],
        $members
    );
}

it('reports the exact tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Ana Ruiz'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'exact']);
});

it('reports the both-tokens tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Mr Simon Doyle Jr'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'both-tokens']);
});

it('reports the last-only tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Rob Doyle'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'last-only']);
});

it('returns nulls when the best tier is ambiguous', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Simon Doyle'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('returns nulls for an empty team', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, []))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('returns nulls when the user has no usable name', function () {
    // first_name is NOT NULL in prod, so the blank case is '' not null.
    $user = User::factory()->create(['first_name' => '', 'last_name' => null]);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('keeps match() behaviour identical', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    $matcher = app(FreshaStaffMatcher::class);
    $squad = team(['e1', 'Simon Doyle']);

    expect($matcher->match($user, $squad))->toBe('e1')
        ->and($matcher->match($user, []))->toBeNull();
});

it('matches a first-name-only display name when it is unique (owner, 2026-08-19)', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon'], ['e2', 'Ana Ruiz'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'first-exact']);
    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon D.'], ['e2', 'Ana'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'first-exact']);
    // A different surname after the first name is not this tier.
    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon Reed'], ['e2', 'Ana'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
    // Two Simons — nobody.
    expect(app(FreshaStaffMatcher::class)->matchWithTier($user, team(['e1', 'Simon'], ['e2', 'Simon'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
    // A user with only a first name still matches the bare first name.
    $mono = User::factory()->create(['first_name' => 'Simon', 'last_name' => null]);
    expect(app(FreshaStaffMatcher::class)->matchWithTier($mono, team(['e1', 'Simon'], ['e2', 'Ana'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'exact']);
});
