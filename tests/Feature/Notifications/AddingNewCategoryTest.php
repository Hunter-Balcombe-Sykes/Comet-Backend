<?php

/** @phpstan-ignore-all */

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// Ad-hoc Mailable stand-in — represents "the Mailable a new category would ship with".
class FakeNewCategoryMail extends Mailable
{
    public function __construct(public Notification $notification) {}

    public function build(): self
    {
        return $this->subject('Fake')->html('<p>hi</p>');
    }
}

beforeEach(function () {
    // Same sqlite-attached-schemas bootstrap as NotificationPublisherTest.
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
    } catch (Throwable) {
    }
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY, user_id TEXT NULL, type TEXT, category TEXT, title TEXT, body TEXT,
        cta_url TEXT, primary_action_label TEXT, secondary_action_label TEXT, secondary_action_url TEXT,
        severity TEXT, starts_at TEXT, ends_at TEXT, dedupe_key TEXT, email_sent_at TEXT,
        created_at TEXT, updated_at TEXT
    )');
    // SQLite requires schema prefix on the index name, not the table in ON clause.
    $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
        ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL');
    $conn->statement('CREATE TABLE IF NOT EXISTS core.users (id TEXT PRIMARY KEY, primary_email TEXT, status TEXT NOT NULL DEFAULT \'active\', deleted_at TEXT NULL)');
    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (id TEXT, user_id TEXT, category_key TEXT, mode TEXT)');
    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (id TEXT, user_id TEXT, category_key TEXT, enabled INTEGER)');

    DB::table('core.users')->insert(['id' => 'pro-1', 'primary_email' => 'pro@example.com']);

    Config::set('partna.notifications.email_enabled', true);
});

it('accepts a new category as a single config-map edit — no publisher/job changes', function () {
    Mail::fake();

    // EDIT #1 (simulated): register the new category + its mailable in config.
    Config::set('partna.notifications.mailables.brand_new_thing', FakeNewCategoryMail::class);

    // categories() should now include it without touching the publisher.
    expect(NotificationPublisher::categories())->toContain('brand_new_thing');

    // EDIT #2 (simulated): emit site calls $publisher->publish(category: 'brand_new_thing', ...).
    $publisher = new NotificationPublisher;
    $publisher->publish(
        userId: 'pro-1',
        frontendType: 'Info',
        category: 'brand_new_thing',
        title: 'Hello',
        body: 'World',
        dedupeKey: 'brand_new_thing:1',
        ctaUrl: '/x',
    );

    // Notification row created.
    expect(DB::table('notifications.notifications')->where('category', 'brand_new_thing')->count())->toBe(1);

    // Email dispatch job — when it runs, it resolves the Mailable from config.
    $notificationId = DB::table('notifications.notifications')->where('category', 'brand_new_thing')->value('id');
    (new SendTransactionalNotificationEmailJob($notificationId, 'brand_new_thing', 'pro-1'))->handle();

    Mail::assertSent(FakeNewCategoryMail::class);
});
