<?php

// Contract guard for API-1: UserWorkplaceController's show/upsert now shape
// their response through WorkplaceResource instead of the removed
// normalizeProfile() hand-built array. This locks the exact key set.

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
        'first_name' => ucfirst($h),
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
        'name', 'address_line1', 'city', 'state', 'postcode', 'country',
        'latitude', 'longitude', 'phone', 'website', 'previous_website', 'category', 'description',
        // Central-identity additions (API-1 follow-up): the workplace card now
        // also carries the contact email, structured hours, and per-field
        // provenance the Brand Info page + Google sync own.
        'contact_email', 'opening_hours', 'field_sources',
    ];
}

it('show returns the exact workplace-card shape', function () {
    $user = uwcUser('cardshow');
    $siteId = uwcSite($user);

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => "Ollie's Diner",
        'address_line1' => '1 Main St',
        'previous_website' => 'https://old.example.com',
        'category' => 'Restaurant',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $response = actingAsUser($user)->getJson('/api/site/workplace')->assertOk();

    expect(array_keys($response->json('workplace')))->toEqualCanonicalizing(uwcWorkplaceKeys());
    expect($response->json('workplace.name'))->toBe("Ollie's Diner");
    expect($response->json('workplace.previous_website'))->toBe('https://old.example.com');
    expect($response->json('workplace.category'))->toBe('Restaurant');
});

it('upsert returns the exact workplace-card shape', function () {
    $user = uwcUser('cardupsert');
    uwcSite($user);

    $response = actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'New Name',
        'address_line1' => '2 Other St',
    ])->assertOk();

    expect(array_keys($response->json('workplace')))->toEqualCanonicalizing(uwcWorkplaceKeys());
    expect($response->json('workplace.name'))->toBe('New Name');
    expect($response->json('workplace.address_line1'))->toBe('2 Other St');
});

it('rejects a workplace upsert whose name is over 15 characters', function () {
    $user = uwcUser('longname');
    uwcSite($user);

    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => str_repeat('a', 16),
    ])->assertStatus(422);
});

it('accepts a workplace upsert whose name is exactly 15 characters', function () {
    $user = uwcUser('capname');
    uwcSite($user);

    $name = str_repeat('a', 15);

    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => $name,
    ])
        ->assertOk()
        ->assertJsonPath('workplace.name', $name);
});

it('returns a null workplace when the stored name is blank after trimming', function () {
    $user = uwcUser('noname');
    $siteId = uwcSite($user);

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => '   ',
        'address_line1' => 'some address',
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

it('partial upsert leaves fields absent from the request untouched', function () {
    // The Brand Info page saves per-card slices (contact / address /
    // description / hours) — a slice must never null what another card owns.
    $user = uwcUser('partialupsert');
    uwcSite($user);

    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Partial Cafe',
        'address_line1' => '9 Slice St',
        'description' => 'Original description.',
        'phone' => '+61 400 111 222',
    ])->assertOk();

    // Address-only save: description + phone survive.
    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Partial Cafe',
        'address_line1' => '10 Slice St',
        'city' => 'Carlton',
    ])->assertOk();

    $workplace = actingAsUser($user)->getJson('/api/site/workplace')->json('workplace');
    expect($workplace['address_line1'])->toBe('10 Slice St')
        ->and($workplace['city'])->toBe('Carlton')
        ->and($workplace['description'])->toBe('Original description.')
        ->and($workplace['phone'])->toBe('+61 400 111 222');

    // An explicitly-sent null (or empty string) still clears its field.
    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Partial Cafe',
        'description' => '',
    ])->assertOk();

    $workplace = actingAsUser($user)->getJson('/api/site/workplace')->json('workplace');
    expect($workplace['description'])->toBeNull()
        ->and($workplace['address_line1'])->toBe('10 Slice St');
});
