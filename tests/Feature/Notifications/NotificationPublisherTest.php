<?php

/** @phpstan-ignore-all */

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Same sqlite-attached-schemas pattern as StaffNotificationRetentionTest.
    // Notification models extend BaseModel which pins the pgsql connection,
    // so point pgsql at sqlite in-memory and ATTACH the notifications schema.
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS notifications");
    } catch (\Throwable $e) {
        // already attached
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        email_sent_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    // SQLite requires schema prefix on the index name, not the table in ON clause.
    $conn->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key)
         WHERE dedupe_key IS NOT NULL'
    );

    Config::set('partna.notifications.email_enabled', false);
});

it('stores dedupe_key in its own column, not smuggled into the CTA URL', function () {
    $publisher = new NotificationPublisher;

    $publisher->publish(
        userId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: 'Test',
        body: 'Body',
        dedupeKey: 'invite:abc',
        ctaUrl: '/account/invites',
    );

    $row = DB::table('notifications.notifications')->first();

    expect($row->dedupe_key)->toBe('invite:abc');
    expect($row->cta_url)->toBe('/account/invites');       // clean, no ?notif=
    expect($row->cta_url)->not->toContain('notif=');
});

it('deduplicates via ON CONFLICT on (user_id, dedupe_key)', function () {
    $publisher = new NotificationPublisher;

    $publish = fn () => $publisher->publish(
        userId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: 'Test',
        body: 'Body',
        dedupeKey: 'invite:abc',
        ctaUrl: '/x',
    );

    $publish();
    $publish();
    $publish();

    expect(DB::table('notifications.notifications')->count())->toBe(1);
});

it('allows the same dedupe_key for different professionals', function () {
    $publisher = new NotificationPublisher;

    foreach (['pro-1', 'pro-2'] as $proId) {
        $publisher->publish(
            userId: $proId,
            frontendType: 'Info',
            category: 'invites',
            title: 'T',
            body: 'B',
            dedupeKey: 'invite:abc',
            ctaUrl: '/x',
        );
    }

    expect(DB::table('notifications.notifications')->count())->toBe(2);
});

it('rejects empty user_id or empty title/body silently', function () {
    $publisher = new NotificationPublisher;

    $publisher->publish(
        userId: '',
        frontendType: 'Info',
        category: 'invites',
        title: 'T',
        body: 'B',
        dedupeKey: 'k',
    );

    $publisher->publish(
        userId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: '   ',
        body: 'B',
        dedupeKey: 'k2',
    );

    $publisher->publish(
        userId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: 'T',
        body: 'B',
        dedupeKey: '   ', // whitespace-only → trimmed to empty → no-op
    );

    expect(DB::table('notifications.notifications')->count())->toBe(0);
});

it('dispatches the mailable class resolved from config for the category', function () {
    Mail::fake();
    Config::set('partna.notifications.email_enabled', true);

    // Seed a notification row the job can load.
    DB::table('notifications.notifications')->insert([
        'id' => 'notif-1',
        'user_id' => 'pro-1',
        'type' => 'Info',
        'category' => 'policy_update',
        'title' => 'Welcome',
        'body' => 'You are invited',
        'cta_url' => '/x',
        'primary_action_label' => 'View',
        'secondary_action_label' => 'Dismiss',
        'secondary_action_url' => null,
        'severity' => 'info',
        'starts_at' => now(),
        'ends_at' => now()->addDays(30),
        'dedupe_key' => 'k',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('pgsql')->statement("ATTACH DATABASE ':memory:' AS core");
    DB::connection('pgsql')->statement(
        "CREATE TABLE IF NOT EXISTS core.users (id TEXT PRIMARY KEY, primary_email TEXT, status TEXT NOT NULL DEFAULT 'active', deleted_at TEXT NULL)"
    );
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (id TEXT, user_id TEXT, category_key TEXT, mode TEXT)'
    );
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (id TEXT, user_id TEXT, category_key TEXT, enabled INTEGER)'
    );
    DB::table('core.users')->insert(['id' => 'pro-1', 'primary_email' => 'pro@example.com']);

    (new SendTransactionalNotificationEmailJob('notif-1', 'policy_update', 'pro-1'))->handle();

    Mail::assertSent(PolicyUpdateMail::class);
});

it('skips email dispatch when category maps to null (in-app only)', function () {
    Mail::fake();
    Config::set('partna.notifications.email_enabled', true);
    Config::set('partna.notifications.mailables.in_app_only_demo', null);

    // Minimal seed — job should bail on null mapping before DB reads matter.
    (new SendTransactionalNotificationEmailJob('n-x', 'in_app_only_demo', 'pro-1'))->handle();

    Mail::assertNothingSent();
});
