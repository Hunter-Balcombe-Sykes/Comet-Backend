<?php

/** @phpstan-ignore-all */

// The bell notice raised when a user connects an integration. Guards live in the
// notifier (not at its call sites) so any emit point added later inherits them —
// these tests exercise them directly for that reason.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupIntegrationConnectionsTable();
    setupNotificationsTable();

    // setupNotificationsTable() does NOT create the dedupe index, and
    // NotificationPublisher dedupes via insertOrIgnore ON CONFLICT — without
    // this, duplicate publishes would silently insert twice and the dedupe
    // assertions below would pass vacuously.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function icnUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function icnConnection(User $user, array $overrides = []): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['handle' => 'someone'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        ...$overrides,
    ]);
}

function icnRows(User $user)
{
    return DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'integration_connected')
        ->get();
}

it('publishes a non-critical in-app notification naming the platform', function () {
    $user = icnUser('icn1');
    $connection = icnConnection($user);

    app(IntegrationNotifier::class)->connected($connection);

    $rows = icnRows($user);
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->title)->toBe('Instagram connected');
    expect($row->type)->toBe('Success');
    expect((int) $row->critical)->toBe(0);
    expect($row->cta_url)->toBe('/account/integrations');
    expect($row->dedupe_key)->toBe("integration_connected:{$connection->id}");
});

it('never queues a transactional email', function () {
    Queue::fake();
    $user = icnUser('icn2');

    app(IntegrationNotifier::class)->connected(icnConnection($user));

    Queue::assertNothingPushed();
});

it('stays silent for a row that is not yet ok', function () {
    $user = icnUser('icn3');

    app(IntegrationNotifier::class)->connected(icnConnection($user, ['last_refresh_status' => 'pending']));
    app(IntegrationNotifier::class)->connected(icnConnection($user, [
        'resource_id' => 'acct-two',
        'last_refresh_status' => 'error',
    ]));

    expect(icnRows($user))->toHaveCount(0);
});

it('stays silent for per-link and per-event rows', function () {
    $user = icnUser('icn4');

    app(IntegrationNotifier::class)->connected(icnConnection($user, [
        'platform' => 'custom',
        'resource_id' => 'link-abc',
        'resource_kind' => 'link',
    ]));
    app(IntegrationNotifier::class)->connected(icnConnection($user, [
        'platform' => 'eventbrite',
        'resource_id' => 'event-abc',
        'resource_kind' => 'event',
    ]));

    expect(icnRows($user))->toHaveCount(0);
});

it('dedupes repeat calls for the same connection row', function () {
    $user = icnUser('icn5');
    $connection = icnConnection($user);

    app(IntegrationNotifier::class)->connected($connection);
    app(IntegrationNotifier::class)->connected($connection);

    expect(icnRows($user))->toHaveCount(1);
});

it('notifies again for a different connection row on the same platform', function () {
    // A disconnect soft-deletes and a reconnect mints a NEW row (the unique index
    // is partial on deleted_at IS NULL), so the new id is a fresh dedupe key.
    $user = icnUser('icn6');

    $first = icnConnection($user);
    app(IntegrationNotifier::class)->connected($first);
    $first->delete();

    $second = icnConnection($user);
    app(IntegrationNotifier::class)->connected($second);

    expect(icnRows($user))->toHaveCount(2);
});
