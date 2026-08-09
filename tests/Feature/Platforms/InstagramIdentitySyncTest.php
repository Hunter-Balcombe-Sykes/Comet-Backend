<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramIdentitySync;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
});

it('fills sector, display_name, and handle only when blank, from instagram identity fields', function () {
    $user = User::factory()->create([
        'account_type' => 'partna',
        'sector' => null,
        'sector_source' => null,
        'display_name' => '',
        'handle' => 'existing-handle',
        'handle_lc' => 'existing-handle',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'fullName' => "Jane's Salon",
        'username' => 'janes_salon',
    ]);

    $user->refresh();
    expect($user->sector)->toBe('hair-salon');
    expect($user->sector_source)->toBe('instagram');
    expect($user->display_name)->toBe("Jane's Salon");
    expect($user->handle)->toBe('existing-handle'); // untouched, was already set
});

// ── raw-actor snake_case tolerance (SIGNUP-2, 2026-08-06) ────────────────
//
// InstagramConnectionSeeder passes the RAW Apify profile node into
// applyIdentity(), not the normalised $selection array. The figue actor
// (live since 7969ba981, 2026-07-19) returns raw Instagram GraphQL
// snake_case for these fields, not the legacy camelCase these fixtures used
// exclusively before this test was added — which is why the suite stayed
// green through the three-week production regression where every
// Instagram-built site came out nameless.

it('fills sector and display_name from snake_case identity fields (figue actor shape)', function () {
    $user = User::factory()->create([
        'sector' => null,
        'sector_source' => null,
        'display_name' => '',
        'handle' => 'existing-handle',
        'handle_lc' => 'existing-handle',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'business_category_name' => 'Hair Salon',
        'full_name' => "Jane's Salon",
    ]);

    $user->refresh();
    expect($user->sector)->toBe('hair-salon');
    expect($user->sector_source)->toBe('instagram');
    expect($user->display_name)->toBe("Jane's Salon");
});

it('prefers legacy camelCase over snake_case when both spellings are present', function () {
    $user = User::factory()->create([
        'sector' => null,
        'sector_source' => null,
        'display_name' => '',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'business_category_name' => 'Restaurant',
        'fullName' => 'Camel Case Wins',
        'full_name' => 'Snake Case Loses',
    ]);

    $user->refresh();
    expect($user->sector)->toBe('hair-salon');
    expect($user->display_name)->toBe('Camel Case Wins');
});

it('fills workplace contact fields from snake_case business_email / business_phone_number', function () {
    $user = User::factory()->create(['account_type' => 'business']);
    $site = Site::factory()->for($user, 'user')->create();

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'business_email' => 'hello@venue.example',
        'business_phone_number' => '+61 2 9999 0000',
    ]);

    $wp = Workplace::where('site_id', (string) $site->id)->first();
    expect($wp->contact_email)->toBe('hello@venue.example');
    expect($wp->phone)->toBe('+61 2 9999 0000');
    expect($wp->field_sources['contact_email']['source'])->toBe('instagram');
});

it('prefers legacy camelCase over snake_case for contact fields when both are present', function () {
    $user = User::factory()->create(['account_type' => 'business']);
    $site = Site::factory()->for($user, 'user')->create();

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessEmail' => 'camel@venue.example',
        'business_email' => 'snake@venue.example',
    ]);

    $wp = Workplace::where('site_id', (string) $site->id)->first();
    expect($wp->contact_email)->toBe('camel@venue.example');
});

it('does not overwrite a manually-picked sector', function () {
    $user = User::factory()->create(['sector' => 'restaurant', 'sector_source' => 'manual']);
    app(InstagramIdentitySync::class)->applyIdentity($user, ['businessCategoryName' => 'Hair Salon']);
    expect($user->fresh()->sector)->toBe('restaurant');
});

it('does not overwrite a sector already synced from elsewhere, even non-manual', function () {
    $user = User::factory()->create(['sector' => 'restaurant', 'sector_source' => 'google-business']);
    app(InstagramIdentitySync::class)->applyIdentity($user, ['businessCategoryName' => 'Hair Salon']);
    expect($user->fresh()->sector)->toBe('restaurant');
});

it('auto-generates a collision-safe handle from username when handle is blank', function () {
    // Str::slug() turns underscores into hyphens, so 'janes_salon' -> 'janes-salon'.
    User::factory()->create(['handle' => 'janes-salon', 'handle_lc' => 'janes-salon']); // taken
    $user = User::factory()->create(['handle' => '', 'handle_lc' => '']);
    app(InstagramIdentitySync::class)->applyIdentity($user, ['username' => 'janes_salon']);
    expect($user->fresh()->handle_lc)->not->toBe('janes-salon');
    expect($user->fresh()->handle_lc)->toStartWith('janes-salon');
});

it('does nothing when the payload has none of the identity fields', function () {
    $user = User::factory()->create(['sector' => null, 'sector_source' => null, 'display_name' => '', 'handle' => '', 'handle_lc' => '']);
    app(InstagramIdentitySync::class)->applyIdentity($user, ['followersCount' => 10]);

    $fresh = $user->fresh();
    expect($fresh->sector)->toBeNull();
    expect($fresh->display_name)->toBe('');
    expect($fresh->handle)->toBe('');
});

// ── contact fields (email, phone) on Workplace ────────────────────────────

it('fills workplace contact_email and phone only when blank, with field_sources provenance', function () {
    $user = User::factory()->create(['account_type' => 'business']);
    $site = Site::factory()->for($user, 'user')->create();
    Workplace::create(['site_id' => (string) $site->id, 'phone' => '+61 2 already set']);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessEmail' => 'hello@venue.example',
        'businessPhoneNumber' => '+61 2 9999 0000',
    ]);

    $wp = Workplace::where('site_id', (string) $site->id)->first();
    expect($wp->contact_email)->toBe('hello@venue.example');
    expect($wp->phone)->toBe('+61 2 already set'); // untouched
    expect($wp->field_sources['contact_email']['source'])->toBe('instagram');
    expect($wp->field_sources)->not->toHaveKey('phone'); // untouched field is not re-stamped
});

it('creates the workplace row if none exists yet, when contact fields are present', function () {
    $user = User::factory()->create(['account_type' => 'partna']);
    $site = Site::factory()->for($user, 'user')->create();

    app(InstagramIdentitySync::class)->applyIdentity($user, ['businessEmail' => 'hello@venue.example']);

    $wp = Workplace::where('site_id', (string) $site->id)->first();
    expect($wp)->not->toBeNull();
    expect($wp->contact_email)->toBe('hello@venue.example');
});

it('does nothing to workplace contact fields when the user has no site', function () {
    $user = User::factory()->create();
    // No Site exists for this user — applyIdentity must not throw.
    app(InstagramIdentitySync::class)->applyIdentity($user, ['businessEmail' => 'hello@venue.example']);
    expect(Workplace::count())->toBe(0);
});

it('does nothing to workplace when neither contact field is present in the payload', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    app(InstagramIdentitySync::class)->applyIdentity($user, ['fullName' => 'Jane']);
    expect(Workplace::where('site_id', (string) $site->id)->exists())->toBeFalse();
});
