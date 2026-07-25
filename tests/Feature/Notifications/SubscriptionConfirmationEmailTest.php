<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Mail\SubscriptionConfirmationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEmailSubscriptionsTable();
});

// Returns [userId, siteId, subscriptionId]. Seeds an active subscribed row.
function seedConfirmableSubscription(array $subOverrides = [], ?array $newsletterBlockSettings = null): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $handle = 'pro-'.substr($userId, 0, 8);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Test Pro',
        'first_name' => 'Test Pro',
        'primary_email' => $handle.'@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $handle,
        'is_published' => 1,
    ]);

    if ($newsletterBlockSettings !== null) {
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'site_id' => $siteId,
            'block_group' => 'sections',
            'block_type' => 'newsletter',
            'is_active' => 1,
            'settings' => json_encode($newsletterBlockSettings),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert(array_merge([
        'id' => $subId,
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'sub@example.com',
        'email_lc' => 'sub@example.com',
        'full_name' => 'Sarah',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-'.substr($subId, 0, 12),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $subOverrides));

    return [$userId, $siteId, $subId];
}

it('sends a confirmation to the subscriber and stamps confirmation_sent_at', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription();

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertSent(SubscriptionConfirmationMail::class, fn ($m) => $m->hasTo('sub@example.com'));

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')->where('id', $subId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('is idempotent once confirmation_sent_at is set', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription(['confirmation_sent_at' => now()->toDateTimeString()]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});

it('does not send when the subscription is no longer subscribed', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription(['status' => 'unsubscribed', 'unsubscribed_at' => now()->toDateTimeString()]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});

it('respects the per-block send_visitor_confirmation = false toggle', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription([], ['send_visitor_confirmation' => false]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});

it('drops the send when the per-recipient rate limit is exceeded', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription();

    $key = 'visitor_confirmation:'.hash('sha256', 'sub@example.com');
    $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key, 3600);
    }

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});
