<?php

// GET /api/onboarding/suggestions — the server-computed setup-steps payload
// (signup-v2 E1). Flags per account type + the ≤2 capability-and-connection
// filtered sector suggestions + unmatched-link prefills.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Onboarding\OnboardingSuggestions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    setupWorkplacesTable();
});

function onboardingUser(string $handle, string $type = 'partna', ?string $sector = null): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => $type,
        'status' => 'active',
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // Raw insert (createTenant's pattern) — Site::create() silently drops
    // non-fillable keys like user_id, leaving the site() relation empty.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $handle,
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $user;
}

it('asks a sectorless partna account for sector + store, no workplace, no instagram', function () {
    $user = onboardingUser('obpartna1');

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['askSector'])->toBeTrue();
    expect($json['askStore'])->toBeTrue();
    expect($json['askWorkplace'])->toBeFalse();
    expect($json['askInstagram'])->toBeFalse();
    expect($json['suggestions'])->toBe([]);
});

it('asks a workplace-sector partna account for a workplace and suggests booking', function () {
    $user = onboardingUser('obhair', 'partna', 'hair-salon');

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['askSector'])->toBeTrue();
    expect($json['askWorkplace'])->toBeTrue();
    expect(array_column($json['suggestions'], 'key'))->toBe(['booking']);
});

it('never asks for a workplace when one already exists', function () {
    $user = onboardingUser('obhair2', 'partna', 'hair-salon');
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $user->site->id, // 1:1 — site_id IS the primary key
        'name' => 'Salon X',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['askWorkplace'])->toBeFalse();
});

it('filters already-connected platforms out of the suggestions (booking family)', function () {
    $user = onboardingUser('obbooked', 'partna', 'personal-trainer');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://fresha.com/a/x'], 'is_active' => true,
    ]);

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    // booking is taken (fresha counts) → strava is the surviving suggestion.
    expect(array_column($json['suggestions'], 'key'))->toBe(['strava']);
});

it('serves a food BUSINESS reservations + online-ordering (never booking) and asks instagram when missing', function () {
    $user = onboardingUser('obrest', 'business', 'restaurant');

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['askSector'])->toBeTrue();
    expect($json['askStore'])->toBeFalse();
    expect($json['askWorkplace'])->toBeFalse();
    expect($json['askInstagram'])->toBeTrue();
    expect(array_column($json['suggestions'], 'key'))->toBe(['reservations', 'online-ordering']);
});

it('serves a non-food BUSINESS booking (its capability) and skips instagram when connected', function () {
    $user = onboardingUser('obsalon', 'business', 'hair-salon');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['username' => 'salon'], 'is_active' => true,
    ]);

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['askInstagram'])->toBeFalse();
    expect(array_column($json['suggestions'], 'key'))->toBe(['booking']);
});

it('caps suggestions at two for a musician (spotify + soundcloud, no prefill — unclassifiable hosts)', function () {
    $user = onboardingUser('obmusic', 'partna', 'musician');

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    $keys = array_column($json['suggestions'], 'key');
    expect($keys)->toBe(['spotify', 'soundcloud']);
    expect($json['suggestions'][0]['prefillUrl'])->toBeNull();
});

it('prefills a suggestion from a classifiable instagram unmatched link (linkedin)', function () {
    $user = onboardingUser('obacct', 'partna', 'accountant');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['unmatched' => [
            ['url' => 'https://www.linkedin.com/in/some-accountant', 'label' => 'LinkedIn'],
        ]], 'is_active' => true,
    ]);

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    $keys = array_column($json['suggestions'], 'key');
    expect($keys)->toBe(['linkedin', 'booking']);
    expect($json['suggestions'][0]['prefillUrl'])->toBe('https://www.linkedin.com/in/some-accountant');
    expect($json['suggestions'][1]['prefillUrl'])->toBeNull();
});

it('returns no suggestions for the other sector', function () {
    $user = onboardingUser('obother', 'partna', 'other');

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['suggestions'])->toBe([]);
    // 'other' is deliberately not a workplace sector either.
    expect($json['askWorkplace'])->toBeFalse();
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/onboarding/suggestions')->assertUnauthorized();
});

it('leaves no sector without something to suggest', function () {
    // Retiring a platform silently emptied six retail sectors once
    // (Pinterest, 2026-07-28) — the step then skipped itself with no signal.
    // A sector mapping to nothing is a gap worth failing the build over.
    $reflection = new ReflectionClass(OnboardingSuggestions::class);
    $bySector = $reflection->getConstant('SECTOR_SUGGESTIONS');

    // 'other' is the deliberate exception: a catch-all sector has nothing
    // specific to suggest, and inventing something for it would be worse.
    $empty = array_values(array_diff(
        array_keys(array_filter($bySector, fn (array $keys) => $keys === [])),
        ['other'],
    ));

    expect($empty)->toBe([], 'Sectors with no suggestion: '.implode(', ', $empty));
});

it('keeps asking for the sector until a human picks one', function (string $accountType, ?string $source, bool $expected) {
    $user = onboardingUser("asksector{$accountType}".($source ?? 'null'), $accountType, 'hair-salon');
    $user->forceFill(['sector_source' => $source])->save();

    expect(app(OnboardingSuggestions::class)->for($user)['askSector'])->toBe($expected);
})->with([
    'partna, instagram guess' => ['partna', 'instagram', true],
    'business, instagram guess' => ['business', 'instagram', true],
    'partna, google sync' => ['partna', 'google-business', true],
    'partna, manual pick' => ['partna', 'manual', false],
    'business, manual pick' => ['business', 'manual', false],
]);

it('ignores hidden pre-scrape connections — an offer is not a connection', function () {
    // A.3 hidden rows ingest ahead of acceptance; counting one as connected
    // would suppress the very ask/suggestion the setup dialog surfaces it
    // through (F.1 fresh-eyes review, 2026-09-03).
    $user = onboardingUser('obhidden', 'business', 'hair-salon');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['username' => 'salon'], 'is_active' => true,
        'visibility' => IntegrationConnection::VISIBILITY_HIDDEN,
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://fresha.com/a/x'], 'is_active' => true,
        'visibility' => IntegrationConnection::VISIBILITY_HIDDEN,
    ]);

    $json = actingAsUser($user)->getJson('/api/onboarding/suggestions')->assertOk()->json();

    expect($json['askInstagram'])->toBeTrue();
    expect(array_column($json['suggestions'], 'key'))->toBe(['booking']);
});
