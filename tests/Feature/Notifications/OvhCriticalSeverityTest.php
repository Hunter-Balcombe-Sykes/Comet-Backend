<?php

/** @phpstan-ignore-all */

// OV-H: the severity/delivery model on NotificationPublisher —
//   critical=true  → in-app row + email job queued + NO expiry (persists)
//   critical=false → in-app row only, no email, gets an ends_at (auto-cleaned)
// plus the email path: critical mail goes through CriticalNotificationMail (the shared
// OTP layout family), including the fallback for a critical notification whose category
// has no category-specific mailable.

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Mail\Notifications\CriticalNotificationMail;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
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
    } catch (Throwable $e) {
        // already attached
    }
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (Throwable $e) {
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
        critical INTEGER NOT NULL DEFAULT 0,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        email_sent_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $conn->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
    setupUsersTable();
    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (id TEXT, user_id TEXT, category_key TEXT, mode TEXT)');
    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (id TEXT, user_id TEXT, category_key TEXT, enabled INTEGER)');

    Config::set('partna.notifications.email_enabled', true);
});

it('critical: stores an in-app row, queues the email job, and never expires', function () {
    Queue::fake();

    app(NotificationPublisher::class)->publish(
        userId: 'pro-1',
        frontendType: 'Warning',
        category: 'platform_connection',
        title: 'Reconnect your Instagram',
        body: 'We could not refresh it.',
        dedupeKey: 'platform_connection_failed:conn-1',
        critical: true,
    );

    $row = DB::table('notifications.notifications')->where('user_id', 'pro-1')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(1);
    expect($row->ends_at)->toBeNull();               // critical persists — no auto-expiry

    Queue::assertPushed(SendTransactionalNotificationEmailJob::class, 1);
});

it('non-critical: stores an in-app row, queues NO email, and gets an expiry', function () {
    Queue::fake();

    app(NotificationPublisher::class)->publish(
        userId: 'pro-2',
        frontendType: 'Success',
        category: 'achievement',
        title: 'Your first enquiry',
        body: 'Nice work.',
        dedupeKey: 'achievement:first_enquiry:pro-2',
        retentionConfigKey: 'achievement',
        critical: false,
    );

    $row = DB::table('notifications.notifications')->where('user_id', 'pro-2')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(0);
    expect($row->ends_at)->not->toBeNull();          // non-critical auto-cleans

    Queue::assertNotPushed(SendTransactionalNotificationEmailJob::class);
});

it('critical email is sent via CriticalNotificationMail for a mapped critical category', function () {
    Mail::fake();

    DB::table('core.users')->insert([
        'id' => 'pro-1',
        'handle' => 'pro-1',
        'handle_lc' => 'pro-1',
        'display_name' => 'Pro-1',
        'first_name' => 'Pro-1',
        'primary_email' => 'pro@example.com',
    ]);
    DB::table('notifications.notifications')->insert([
        'id' => 'n-crit', 'user_id' => 'pro-1', 'type' => 'Warning', 'category' => 'platform_connection',
        'title' => 'Reconnect your Instagram', 'body' => 'x', 'severity' => 'warning', 'critical' => 1,
        'starts_at' => now(), 'ends_at' => null, 'dedupe_key' => 'k1', 'created_at' => now(), 'updated_at' => now(),
    ]);

    (new SendTransactionalNotificationEmailJob('n-crit', 'platform_connection', 'pro-1'))->handle();

    Mail::assertSent(CriticalNotificationMail::class);
});

it('critical email falls back to CriticalNotificationMail when the category has no mailable', function () {
    Mail::fake();

    DB::table('core.users')->insert([
        'id' => 'pro-1',
        'handle' => 'pro-1',
        'handle_lc' => 'pro-1',
        'display_name' => 'Pro-1',
        'first_name' => 'Pro-1',
        'primary_email' => 'pro@example.com',
    ]);
    DB::table('notifications.notifications')->insert([
        'id' => 'n-crit2', 'user_id' => 'pro-1', 'type' => 'Critical', 'category' => 'some_unmapped_category',
        'title' => 'Important', 'body' => 'x', 'severity' => 'critical', 'critical' => 1,
        'starts_at' => now(), 'ends_at' => null, 'dedupe_key' => 'k2', 'created_at' => now(), 'updated_at' => now(),
    ]);

    (new SendTransactionalNotificationEmailJob('n-crit2', 'some_unmapped_category', 'pro-1'))->handle();

    Mail::assertSent(CriticalNotificationMail::class);
});

it('non-critical, unmapped category sends nothing (in-app only)', function () {
    Mail::fake();

    DB::table('core.users')->insert([
        'id' => 'pro-1',
        'handle' => 'pro-1',
        'handle_lc' => 'pro-1',
        'display_name' => 'Pro-1',
        'first_name' => 'Pro-1',
        'primary_email' => 'pro@example.com',
    ]);
    DB::table('notifications.notifications')->insert([
        'id' => 'n-info', 'user_id' => 'pro-1', 'type' => 'Info', 'category' => 'achievement',
        'title' => 'Milestone', 'body' => 'x', 'severity' => 'info', 'critical' => 0,
        'starts_at' => now(), 'ends_at' => now()->addDays(30), 'dedupe_key' => 'k3', 'created_at' => now(), 'updated_at' => now(),
    ]);

    (new SendTransactionalNotificationEmailJob('n-info', 'achievement', 'pro-1'))->handle();

    Mail::assertNothingSent();
});
