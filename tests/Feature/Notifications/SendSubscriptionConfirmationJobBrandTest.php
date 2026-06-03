<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Mail\SubscriptionConfirmationMail;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Requires the same schema as sibling brand tests: users, sites, email_subscriptions,
// design_kits (ProEmailBrandResolver reads site.design_kits), plus blocks for the
// send_visitor_confirmation toggle check that runs before brand resolution.
beforeEach(function () {
    Cache::flush();
    setupUsersTable();
    setupSitesTable();
    setupEmailSubscriptionsTable();
    setupDesignKitsTable();
    setupMediaTables();
    setupBlocksTable();
    setupSubdomainAliasesTable();
});

// Create a user+site with a seeded design_kits row and a confirmable, subscribed
// email_subscriptions row; returns the subscription id.
function makeConfirmableSubscription(array $overrides = []): string
{
    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    // Production has trg_create_empty_design_kit; SQLite tests don't, so seed manually.
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert(array_merge([
        'id' => $subId,
        'user_id' => $user->id,
        'list_key' => 'marketing',
        'email' => 'sam@example.com',
        'email_lc' => 'sam@example.com',
        'full_name' => 'Sam',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-'.Str::random(12),
        'confirmation_sent_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $subId;
}

it('sends a brand-resolved subscription confirmation', function () {
    Mail::fake();

    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    // Production has trg_create_empty_design_kit; SQLite tests don't, so seed manually.
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $subId,
        'user_id' => $user->id,
        'list_key' => 'marketing',
        'email' => 'sam@example.com',
        'email_lc' => 'sam@example.com',
        'full_name' => 'Sam',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-'.Str::random(12),
        'confirmation_sent_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertSent(SubscriptionConfirmationMail::class, function (SubscriptionConfirmationMail $m) {
        return $m->brand->proName === 'Jane Doe'
            && $m->visitorName === 'Sam'
            && str_contains($m->unsubscribeUrl, 'unsubscribe');
    });

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')->where('id', $subId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('stamps confirmation_sent_at under the lock before the send, so a failed send is not retried into a double-send', function () {
    $subId = makeConfirmableSubscription();

    // See TXN-1 sibling test: the flag is claimed atomically under the lock, so a
    // mail-provider failure leaves it committed and a Horizon retry bails instead
    // of delivering a duplicate "you're subscribed" confirmation. (TXN-2)
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(fn () => (new SendSubscriptionConfirmationJob($subId))->handle())
        ->toThrow(RuntimeException::class);

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')->where('id', $subId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});
