<?php

/** @phpstan-ignore-all */

// OV-H: the weekly summary command publishes a non-critical Info notification
// (+ real-numbers digest email, when enabled and the analytics_weekly
// preference allows it) to active users who have a site AND had activity
// last week — once per user per week (dedupe) — and nothing on --dry-run.

use App\Mail\Account\WeeklyDigestMail;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContactInboxSchema();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    setupNotificationEmailPoliciesTable();
    setupNotificationEmailPreferencesTable();
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
        'display_name' => 'Weekly', 'first_name' => 'Weekly', 'account_type' => 'partna',
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

// Stamps last week's window with one visit — the minimum activity that keeps
// a user out of the "quiet week, no digest" skip.
function giveLastWeekVisit(User $user): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'visitor_id' => (string) Str::uuid(),
        'occurred_at' => now()->subWeek()->startOfWeek()->addDay()->toDateTimeString(),
        'created_at' => now()->subWeek()->startOfWeek()->addDay()->toDateTimeString(),
    ]);
}

it('notifies active users who have a site and had activity last week', function () {
    $withSite = weeklyUser();
    giveSite($withSite);
    giveLastWeekVisit($withSite);

    $noSite = weeklyUser();                 // active, but no site
    $inactive = weeklyUser('suspended');    // has a site, but not active
    giveSite($inactive);
    giveLastWeekVisit($inactive);

    Artisan::call('partna:notify-weekly-summary');

    $rows = DB::table('notifications.notifications')->where('category', 'analytics_weekly')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->user_id)->toBe($withSite->id);
    expect($rows->first()->type)->toBe('Info');
    expect((int) $rows->first()->critical)->toBe(0);        // in-app only
    expect($rows->first()->ends_at)->not->toBeNull();       // auto-expires
});

it('skips a user whose site had a quiet week (no visits, no taps)', function () {
    $quiet = weeklyUser();
    giveSite($quiet);
    // No visit/click rows — a quiet week.

    Artisan::call('partna:notify-weekly-summary');

    expect(DB::table('notifications.notifications')->where('user_id', $quiet->id)->where('category', 'analytics_weekly')->exists())->toBeFalse();
});

it('is idempotent within the same week (dedupe per week)', function () {
    $user = weeklyUser();
    giveSite($user);
    giveLastWeekVisit($user);

    Artisan::call('partna:notify-weekly-summary');
    Artisan::call('partna:notify-weekly-summary');

    expect(DB::table('notifications.notifications')->where('category', 'analytics_weekly')->count())->toBe(1);
});

it('publishes nothing on --dry-run', function () {
    $user = weeklyUser();
    giveSite($user);
    giveLastWeekVisit($user);

    Artisan::call('partna:notify-weekly-summary', ['--dry-run' => true]);

    expect(DB::table('notifications.notifications')->where('category', 'analytics_weekly')->count())->toBe(0);
});

it('queues the digest email with real numbers when the feature flag is on', function () {
    Mail::fake();
    config(['partna.notifications.email_enabled' => true]);

    $user = weeklyUser();
    giveSite($user);
    $lastWeek = now()->subWeek()->startOfWeek()->addDay();

    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'visitor_id' => (string) Str::uuid(), 'occurred_at' => $lastWeek->toDateTimeString(), 'created_at' => $lastWeek->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'visitor_id' => (string) Str::uuid(), 'occurred_at' => $lastWeek->copy()->addHour()->toDateTimeString(), 'created_at' => $lastWeek->toDateTimeString()],
    ]);
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'visitor_id' => (string) Str::uuid(),
        'url' => 'https://instagram.com/wk', 'platform' => 'Instagram',
        'occurred_at' => $lastWeek->toDateTimeString(), 'created_at' => $lastWeek->toDateTimeString(),
    ]);

    Artisan::call('partna:notify-weekly-summary');

    Mail::assertQueued(WeeklyDigestMail::class, function ($mail) use ($user) {
        return $mail->recipientEmail === $user->primary_email
            && $mail->visits === 2
            && $mail->taps === 1
            && $mail->topLinkLabel === 'Instagram';
    });
});

it('does not queue an email when the feature flag is off', function () {
    Mail::fake();
    config(['partna.notifications.email_enabled' => false]);

    $user = weeklyUser();
    giveSite($user);
    giveLastWeekVisit($user);

    Artisan::call('partna:notify-weekly-summary');

    Mail::assertNothingQueued();
});

it('never queues a second email for the same week (in-app row already existed)', function () {
    Mail::fake();
    config(['partna.notifications.email_enabled' => true]);

    $user = weeklyUser();
    giveSite($user);
    giveLastWeekVisit($user);

    Artisan::call('partna:notify-weekly-summary');
    Artisan::call('partna:notify-weekly-summary');

    Mail::assertQueuedCount(1);
});
