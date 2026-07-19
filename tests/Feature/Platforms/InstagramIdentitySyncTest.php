<?php

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
