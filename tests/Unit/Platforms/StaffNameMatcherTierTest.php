<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\StaffNameMatcher;
use Tests\TestCase;

// tests/Unit is NOT bound to TestCase in Pest.php — this file needs the
// container and the SQLite users stand-in, so opt in per-file.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupIntegrationConnectionsTable();
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

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Ana Ruiz'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'exact']);
});

it('reports the both-tokens tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Mr Simon Doyle Jr'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'both-tokens']);
});

it('reports the last-only tier', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Rob Doyle'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'last-only']);
});

it('returns nulls when the best tier is ambiguous', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Simon Doyle'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('returns nulls for an empty team', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, []))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('returns nulls when the user has no usable name', function () {
    // first_name is NOT NULL in prod, so the blank case is '' not null.
    $user = User::factory()->create(['first_name' => '', 'last_name' => null]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('keeps match() behaviour identical', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    $matcher = app(StaffNameMatcher::class);
    $squad = team(['e1', 'Simon Doyle']);

    expect($matcher->match($user, $squad))->toBe('e1')
        ->and($matcher->match($user, []))->toBeNull();
});

it('matches a first-name-only display name when it is unique (owner, 2026-08-19)', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon'], ['e2', 'Ana Ruiz'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'first-exact']);
    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon D.'], ['e2', 'Ana'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'first-exact']);
    // A different surname after the first name is not this tier.
    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon Reed'], ['e2', 'Ana'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
    // Two Simons — nobody.
    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon'], ['e2', 'Simon'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
    // A user with only a first name still matches the bare first name.
    $mono = User::factory()->create(['first_name' => 'Simon', 'last_name' => null]);
    expect(app(StaffNameMatcher::class)->matchWithTier($mono, team(['e1', 'Simon'], ['e2', 'Ana'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'exact']);
});

// ── T3 (2026-08-27, D10): the vanity-name tier ──────────────────────────────
// The parsed first/last names are DERIVED from an Instagram vanity string and
// are routinely garbage ("Melbourne Barber | Thorton" → first "Melbourne",
// last "Barber" — the real name is after the pipe). Matching is therefore
// INVERTED: each employee's REAL name (Fresha's own data) is looked for
// inside the raw vanity display_name, token-wise, making selection immune to
// parse quality. Verified live 2026-08-27: barber_in_law's auto-selection
// came out null exactly because the matcher was fed the garbage parse.

it('matches an employee whose real name sits after the pipe in the vanity string (barber_in_law)', function () {
    $user = User::factory()->create([
        'display_name' => 'Melbourne Barber | Thorton',
        'first_name' => 'Melbourne',
        'last_name' => 'Barber',
    ]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Thorton'], ['e2', 'Jess'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'vanity-name']);
});

it('matches a multi-token employee name inside a descriptor-suffixed vanity (sammy.pdf shape)', function () {
    $user = User::factory()->create([
        'display_name' => 'Sam Akhurst Music',
        'first_name' => 'Sam',
        'last_name' => 'Music',
    ]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Sam Akhurst'], ['e2', 'Kim Lee'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'vanity-name']);
});

it('the ALL-CAPS pipe vanity still resolves through the parsed tiers unchanged (simondoylehair)', function () {
    $user = User::factory()->create([
        'display_name' => 'SIMON DOYLE | Barber & Educator',
        'first_name' => 'SIMON',
        'last_name' => 'DOYLE',
    ]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Simon Doyle'], ['e2', 'Ana Ruiz'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'exact']);
});

it('two employees both contained in the vanity string is ambiguous — matches neither', function () {
    $user = User::factory()->create([
        'display_name' => 'Thorton & Jess | Studio San',
        'first_name' => 'Thorton',
        'last_name' => 'Jess',
    ]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Thorton'], ['e2', 'Jess'])))
        ->toBe(['employeeId' => null, 'tier' => null]);
});

it('an employee name of only short tokens never rides the vanity tier', function () {
    $user = User::factory()->create([
        'display_name' => 'Al B | Barber',
        'first_name' => 'Al',
        'last_name' => 'B',
    ]);

    // "Al" (2 letters) is too weak a containment signal for the vanity tier —
    // the match still lands, but through the pre-existing first-exact tier
    // (owner 2026-08-19 ruling), proving the vanity tier declined it.
    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Al'], ['e2', 'Marco Rossi'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'first-exact']);
});

it('partial token overlap is not containment — Barber Jones does not match a Barber vanity', function () {
    $user = User::factory()->create([
        'display_name' => 'Melbourne Barber | Thorton',
        'first_name' => 'Melbourne',
        'last_name' => 'Barber',
    ]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user, team(['e1', 'Barber Jones'], ['e2', 'Thorton'])))
        ->toBe(['employeeId' => 'e2', 'tier' => 'vanity-name']);
});

it('reads the RAW instagram fullName when the cleaned display_name lost the person token (acceptance regression)', function () {
    // 2026-08-27 acceptance finding: bio-intelligence cleaned display_name to
    // "Melbourne Barber" — right for the site, but it stripped "Thorton", the
    // exact token the vanity tier needed. The verbatim string lives on the
    // instagram payload; the matcher reads it too.
    $user = User::factory()->create([
        'display_name' => 'Melbourne Barber',
        'first_name' => 'Melbourne',
        'last_name' => 'Barber',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'barber_in_law', 'fullName' => 'Melbourne Barber | Thorton'],
        'is_active' => false,
    ]);

    expect(app(StaffNameMatcher::class)->matchWithTier($user->fresh(), team(['e1', 'Thorton'], ['e2', 'Jess'])))
        ->toBe(['employeeId' => 'e1', 'tier' => 'vanity-name']);
});
