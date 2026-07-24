<?php

/** @phpstan-ignore-all */

// OV-H: the weekly summary command publishes a non-critical Info notification to active
// users who have a site — once per user per week (dedupe) — and nothing on --dry-run.

use App\Models\Core\User\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContactInboxSchema();
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function weeklyUser(string $status = 'active'): User
{
    $id = (string) Str::uuid();

    return User::forceCreate([ // B11 SEC-2: status no longer fillable — forceCreate to persist a non-active status
        'id' => $id,
        'handle' => 'wk-'.substr($id, 0, 8), 'handle_lc' => 'wk-'.substr($id, 0, 8),
        'display_name' => 'Weekly', 'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => substr($id, 0, 8).'@example.com',
        'status' => $status,
    ]);
}

function giveSite(User $user): void
{
    $siteId = (string) Str::uuid();

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'wk-'.substr($siteId, 0, 8),
        'architecture_id' => 'staple',
    ]);
}

it('notifies active users who have a site', function () {
    $withSite = weeklyUser();
    giveSite($withSite);

    $noSite = weeklyUser();                 // active, but no site
    $inactive = weeklyUser('suspended');    // has a site, but not active
    giveSite($inactive);

    Artisan::call('partna:notify-weekly-summary');

    $rows = DB::table('notifications.notifications')->where('category', 'analytics_weekly')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->user_id)->toBe($withSite->id);
    expect($rows->first()->type)->toBe('Info');
    expect((int) $rows->first()->critical)->toBe(0);        // in-app only
    expect($rows->first()->ends_at)->not->toBeNull();       // auto-expires
});

it('is idempotent within the same week (dedupe per week)', function () {
    $user = weeklyUser();
    giveSite($user);

    Artisan::call('partna:notify-weekly-summary');
    Artisan::call('partna:notify-weekly-summary');

    expect(DB::table('notifications.notifications')->where('category', 'analytics_weekly')->count())->toBe(1);
});

it('publishes nothing on --dry-run', function () {
    $user = weeklyUser();
    giveSite($user);

    Artisan::call('partna:notify-weekly-summary', ['--dry-run' => true]);

    expect(DB::table('notifications.notifications')->where('category', 'analytics_weekly')->count())->toBe(0);
});
