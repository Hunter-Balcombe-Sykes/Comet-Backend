<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;

// The identity mirror (2026-08-19 plan): for a BUSINESS the workplace IS the
// account — 8 workplace fields mirror onto the matching user columns; for a
// partna nothing mirrors. Gated on workplace_brand_is_site_identity.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
});

function mirrorSite(string $accountType): Site
{
    $user = User::factory()->create(['account_type' => $accountType]);

    return Site::factory()->for($user, 'user')->create();
}

it('mirrors every identity field onto the user for a business workplace save', function () {
    $site = mirrorSite('business');

    Workplace::create([
        'site_id' => (string) $site->id,
        'name' => 'Mirror Works',
        'phone' => '+61 3 9999 0000',
        'contact_email' => 'front@mirror.example',
        'description' => 'We mirror things.',
        'address_line1' => '5 Reflection Rd',
        'city' => 'Melbourne',
        'state' => 'VIC',
        'postcode' => '3000',
        'country' => 'Australia',
    ]);

    $user = $site->user->fresh();
    expect($user->public_contact_number)->toBe('+61 3 9999 0000');
    expect($user->public_contact_email)->toBe('front@mirror.example');
    expect($user->bio)->toBe('We mirror things.');
    expect($user->location_street_address)->toBe('5 Reflection Rd');
    expect($user->location_city)->toBe('Melbourne');
    expect($user->location_state)->toBe('VIC');
    expect($user->location_postcode)->toBe('3000');
    expect($user->location_country)->toBe('Australia');
});

it('mirrors NOTHING for a partna workplace save', function () {
    $site = mirrorSite('partna');
    $site->user->forceFill([
        'public_contact_email' => 'mine@own.example',
        'bio' => 'My own words.',
    ])->save();

    Workplace::create([
        'site_id' => (string) $site->id,
        'name' => 'Where I Work',
        'phone' => '+61 3 8888 0000',
        'contact_email' => 'work@place.example',
        'description' => 'The workplace blurb.',
        'city' => 'Sydney',
    ]);

    $user = $site->user->fresh();
    expect($user->public_contact_number)->toBeNull();
    expect($user->public_contact_email)->toBe('mine@own.example');
    expect($user->bio)->toBe('My own words.');
    expect($user->location_city)->toBeNull();
});

it('a workplace row minted with ONLY previous_website leaves the user untouched (create mirrors non-null only)', function () {
    // setPreviousWebsite does Workplace::updateOrCreate and the content scan
    // does firstOrNew — before the 2026-08-19 fix, that fresh row's null
    // fields were assigned unconditionally and WIPED the user's own values.
    $site = mirrorSite('business');
    $site->user->forceFill([
        'public_contact_number' => '+61 3 7777 0000',
        'public_contact_email' => 'keep@me.example',
        'bio' => 'Keep this.',
        'location_city' => 'Brisbane',
    ])->save();

    Workplace::updateOrCreate(
        ['site_id' => (string) $site->id],
        ['previous_website' => 'https://old-site.example'],
    );

    $user = $site->user->fresh();
    expect($user->public_contact_number)->toBe('+61 3 7777 0000');
    expect($user->public_contact_email)->toBe('keep@me.example');
    expect($user->bio)->toBe('Keep this.');
    expect($user->location_city)->toBe('Brisbane');
});

it('an UPDATE that clears a field clears the user column too (business)', function () {
    $site = mirrorSite('business');
    Workplace::create([
        'site_id' => (string) $site->id,
        'name' => 'Clearing Co',
        'description' => 'Soon gone.',
    ]);
    expect($site->user->fresh()->bio)->toBe('Soon gone.');

    // A fresh instance, as a real second request would load it.
    $wp = Workplace::where('site_id', (string) $site->id)->first();
    $wp->description = null;
    $wp->save();

    expect($site->user->fresh()->bio)->toBeNull();
});
