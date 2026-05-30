<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEmailSubscriptionsTable();
    setupCustomersTable();
});

function seedPublishedSubscribeSite(string $subdomain = 'subpro'): string
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => 'Sub Pro',
        'primary_email' => 'subpro@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $subdomain,
        'is_published' => 1,
    ]);

    return $userId;
}

it('dispatches the confirmation on a brand-new subscribe', function () {
    seedPublishedSubscribeSite();
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'new@example.com'], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertDispatched(SendSubscriptionConfirmationJob::class);
});

it('does NOT dispatch on a redundant re-submit of an already-subscribed address', function () {
    $userId = seedPublishedSubscribeSite();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'already@example.com',
        'email_lc' => 'already@example.com',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-already',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'already@example.com'], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertNotDispatched(SendSubscriptionConfirmationJob::class);
});

it('dispatches again when a previously-unsubscribed address re-subscribes', function () {
    $userId = seedPublishedSubscribeSite();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'back@example.com',
        'email_lc' => 'back@example.com',
        'status' => 'unsubscribed',
        'unsubscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-back',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'back@example.com'], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertDispatched(SendSubscriptionConfirmationJob::class);
});
