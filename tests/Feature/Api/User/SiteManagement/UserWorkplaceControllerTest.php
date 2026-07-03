<?php

// Contract guard for API-1: UserWorkplaceController's show/upsert now shape
// their response through WorkplaceResource instead of the removed
// normalizeProfile() hand-built array. This locks the exact key set —
// especially that previous_website_analysis (the system-written
// WebsiteStyleAnalyzer output, see the Workplace model) never crosses the
// wire, matching the old method's deliberate omission.

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // upsert() calls SectionVisibilityService::reevaluateEnabled(), which
    // queries site.blocks before its try/catch — the table must exist even
    // though no block row is seeded (query returns null, early-return).
    setupBlocksTable();
});

function uwcUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function uwcSite(User $user): string
{
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $siteId;
}

function uwcWorkplaceKeys(): array
{
    return [
        'name', 'address', 'address_line1', 'city', 'state', 'postcode', 'country',
        'latitude', 'longitude', 'phone', 'website', 'previous_website', 'category', 'description',
    ];
}

it('show returns the exact workplace-card shape and never leaks previous_website_analysis', function () {
    $user = uwcUser('cardshow');
    $siteId = uwcSite($user);

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => "Ollie's Diner",
        'address' => '1 Main St',
        'previous_website' => 'https://old.example.com',
        // System-written analysis blob — must never reach the API response.
        'previous_website_analysis' => json_encode(['v' => 1, 'accent' => '#fff']),
        'category' => 'Restaurant',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $response = actingAsUser($user)->getJson('/api/site/workplace')->assertOk();

    expect(array_keys($response->json('workplace')))->toEqualCanonicalizing(uwcWorkplaceKeys());
    expect($response->json('workplace'))->not->toHaveKey('previous_website_analysis');
    expect($response->json('workplace.name'))->toBe("Ollie's Diner");
    expect($response->json('workplace.previous_website'))->toBe('https://old.example.com');
    expect($response->json('workplace.category'))->toBe('Restaurant');
});

it('upsert returns the exact workplace-card shape and never leaks previous_website_analysis', function () {
    $user = uwcUser('cardupsert');
    uwcSite($user);

    $response = actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'New Name',
        'address' => '2 Other St',
    ])->assertOk();

    expect(array_keys($response->json('workplace')))->toEqualCanonicalizing(uwcWorkplaceKeys());
    expect($response->json('workplace'))->not->toHaveKey('previous_website_analysis');
    expect($response->json('workplace.name'))->toBe('New Name');
    expect($response->json('workplace.address'))->toBe('2 Other St');
});

it('returns a null workplace when the stored name is blank after trimming', function () {
    $user = uwcUser('noname');
    $siteId = uwcSite($user);

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => '   ',
        'address' => 'some address',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->getJson('/api/site/workplace')
        ->assertOk()
        ->assertJsonPath('workplace', null);
});

it('returns null when no workplace row exists yet', function () {
    $user = uwcUser('freshsite');
    uwcSite($user);

    actingAsUser($user)->getJson('/api/site/workplace')
        ->assertOk()
        ->assertJsonPath('workplace', null);
});
