<?php

// LIFE-107 for the Instagram writer. IdentitySync takes a locked re-read before
// deciding whether to write; InstagramIdentitySync did not, so a stale instance
// could clobber a value Google had just committed.

use App\Models\Core\User\User;
use App\Services\Platforms\InstagramIdentitySync;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupSectionsTables();
});

it('does not clobber a value committed after the caller loaded the user', function () {
    $user = User::factory()->create([
        'account_type' => 'partna', 'sector' => null, 'sector_source' => null,
    ]);

    // Simulate Google's fold committing while $user is still in memory as blank.
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'sector' => 'cafe', 'sector_source' => 'google-business',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'username' => 'janes_salon',
    ]);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $user->id)->value('sector'))
        ->toBe('cafe');
});

it('takes a lock, not just a re-read', function () {
    // lockForUpdate() is a no-op on SQLite, so the behavioural test above passes
    // equally against a bare refresh() with no transaction. Mirrors the
    // structural pin in IdentitySyncConcurrencyTest.
    $source = file_get_contents(app_path('Services/Platforms/InstagramIdentitySync.php'));

    expect(substr_count($source, 'lockForUpdate'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($source, '->transaction('))->toBeGreaterThanOrEqual(1);
});
