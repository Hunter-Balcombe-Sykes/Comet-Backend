<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// DINT-1 / PRIV-7 Gap 2: hard-delete unsubscribed email_subscription rows once the
// retention window has elapsed. email and email_lc are both NOT NULL in Postgres and
// email_lc is itself PII, so there is no skeleton to keep — the whole row is removed.

beforeEach(function () {
    // Point pgsql at in-memory SQLite, then build the table via the standard helper.
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'partna.notifications.unsubscribed_retention_days' => 365,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    // setupEmailSubscriptionsTable() (tests/Pest.php) mirrors prod: email + email_lc NOT NULL.
    setupEmailSubscriptionsTable();
});

/**
 * Insert a subscription row and return its id. Mirrors the prod NOT NULL columns
 * (email, email_lc, unsubscribe_token) so the seed reflects real data shape.
 */
function seedPruneSubscriptionRow(array $attrs): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert(array_merge([
        'id' => $id,
        'list_key' => 'marketing',
        'email' => 'test@example.com',
        'email_lc' => 'test@example.com',
        'full_name' => 'Test User',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribed_at' => null,
        'unsubscribe_token' => Str::random(48),
        'consent_ip_hash' => 'abc123',
        'consent_user_agent' => 'Mozilla/5.0',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $attrs));

    return $id;
}

function subscriptionExists(string $id): bool
{
    return DB::connection('pgsql')->table('notifications.email_subscriptions')
        ->where('id', $id)->exists();
}

it('hard-deletes an unsubscribed row past the retention window', function () {
    $id = seedPruneSubscriptionRow([
        'status' => 'unsubscribed',
        'unsubscribed_at' => now()->subDays(400)->toDateTimeString(),
        'email' => 'old@example.com',
        'email_lc' => 'old@example.com',
    ]);

    $this->artisan('notifications:prune-unsubscribed-subscriptions')->assertExitCode(0);

    // The whole row — PII and all — must be gone.
    expect(subscriptionExists($id))->toBeFalse();
});

it('does not touch a subscribed row even if it is old', function () {
    $id = seedPruneSubscriptionRow([
        'status' => 'subscribed',
        'unsubscribed_at' => null,
        'email' => 'active@example.com',
    ]);

    $this->artisan('notifications:prune-unsubscribed-subscriptions')->assertExitCode(0);

    // Subscribed rows have a live consent basis — must survive.
    expect(subscriptionExists($id))->toBeTrue();
});

it('does not touch a recently-unsubscribed row within the retention window', function () {
    $id = seedPruneSubscriptionRow([
        'status' => 'unsubscribed',
        'unsubscribed_at' => now()->subDays(30)->toDateTimeString(), // well within the 365-day window
        'email' => 'recent@example.com',
    ]);

    $this->artisan('notifications:prune-unsubscribed-subscriptions')->assertExitCode(0);

    // Row inside the window must survive.
    expect(subscriptionExists($id))->toBeTrue();
});

it('dry-run deletes nothing', function () {
    $id = seedPruneSubscriptionRow([
        'status' => 'unsubscribed',
        'unsubscribed_at' => now()->subDays(400)->toDateTimeString(),
        'email' => 'dry@example.com',
    ]);

    $this->artisan('notifications:prune-unsubscribed-subscriptions', ['--dry-run' => true])
        ->assertExitCode(0);

    // --dry-run must leave the row in place.
    expect(subscriptionExists($id))->toBeTrue();
});
