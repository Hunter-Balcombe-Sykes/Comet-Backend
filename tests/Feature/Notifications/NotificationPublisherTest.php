<?php

/** @phpstan-ignore-all */

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
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

    setupUsersTable();
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (id TEXT, user_id TEXT, category_key TEXT, mode TEXT)'
    );
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (id TEXT, user_id TEXT, category_key TEXT, enabled INTEGER)'
    );
    DB::table('core.users')->insert([
        'id' => 'pro-1',
        'handle' => 'pro-1',
        'handle_lc' => 'pro-1',
        'display_name' => 'Pro-1',
        'first_name' => 'Pro-1',
        'primary_email' => 'pro@example.com',
    ]);

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

// CACHE-2: publishMany() is currently uncalled anywhere in the app, but its email
// fan-out must not regress to one dispatch()/Redis-write per recipient once a caller
// lands. These assert the Bus::batch() + array_chunk() replacement chunks correctly
// and that batching drops nobody.
it('CACHE-2: publishMany batches email dispatch in chunks instead of one job per recipient', function () {
    Bus::fake();
    Config::set('partna.notifications.email_enabled', true);
    Config::set('partna.notifications.batch_chunk_size', 3);

    $publisher = new NotificationPublisher;

    $items = [];
    for ($i = 0; $i < 5; $i++) {
        $items[] = [
            'userId' => "pro-{$i}",
            'frontendType' => 'Warning',
            'category' => 'policy_update',
            'title' => 'T',
            'body' => 'B',
            'dedupeKey' => "cache2-batch:{$i}",
            'critical' => true,
        ];
    }

    $publisher->publishMany($items);

    // 5 recipients / chunk size 3 → two batches (3 + 2), never one dispatch per item.
    Bus::assertBatchCount(2);

    $batchSizes = collect(Bus::dispatchedBatches())
        ->map(fn (PendingBatch $b) => $b->jobs->count())
        ->sort()->values()->all();
    expect($batchSizes)->toBe([2, 3]);

    // Every recipient gets exactly one job — batching must drop nobody and duplicate nobody.
    $jobUserIds = collect(Bus::dispatchedBatches())
        ->flatMap(fn (PendingBatch $b) => $b->jobs->map(fn ($j) => $j->userId))
        ->sort()->values()->all();
    expect($jobUserIds)->toBe(['pro-0', 'pro-1', 'pro-2', 'pro-3', 'pro-4']);
});

it('publishMany only batches the critical items and skips non-critical ones (matches publish())', function () {
    Bus::fake();
    Config::set('partna.notifications.email_enabled', true);

    $publisher = new NotificationPublisher;

    $publisher->publishMany([
        ['userId' => 'pro-a', 'frontendType' => 'Warning', 'category' => 'policy_update', 'title' => 'T', 'body' => 'B', 'dedupeKey' => 'cache2-nc-a', 'critical' => false],
        ['userId' => 'pro-b', 'frontendType' => 'Warning', 'category' => 'policy_update', 'title' => 'T', 'body' => 'B', 'dedupeKey' => 'cache2-nc-b', 'critical' => true],
    ]);

    Bus::assertBatchCount(1);
    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 1 && $batch->jobs->first()->userId === 'pro-b');
});

it('publishMany dispatches no batch when email is disabled or nothing is critical', function () {
    Bus::fake();
    Config::set('partna.notifications.email_enabled', true);

    $publisher = new NotificationPublisher;

    // All non-critical — rows land, but nothing should hit the mail batch.
    $publisher->publishMany([
        ['userId' => 'pro-c', 'frontendType' => 'Warning', 'category' => 'policy_update', 'title' => 'T', 'body' => 'B', 'dedupeKey' => 'cache2-nc-c', 'critical' => false],
    ]);

    Bus::assertNothingBatched();
});

// CACHE-2 regression: the tests above all run under Bus::fake(), which swaps in
// PendingBatchFake — a subclass that overrides __construct() and never calls
// PendingBatch::ensureJobIsBatchable(). That means NONE of the faked tests above can
// detect a job missing the Batchable trait; they'd stay green even if the trait were
// dropped. The two tests below deliberately avoid Bus::fake() so they exercise the
// real Illuminate\Bus\PendingBatch constructor, where Laravel throws
// `RuntimeException('Attempted to batch job [...], but it does not use the Batchable
// trait.')` synchronously, before any queue/dispatch, on every driver.
it('CACHE-2 regression: SendTransactionalNotificationEmailJob uses the Batchable trait', function () {
    // Cheap, always-on proof: this is a static fact about the class, independent of
    // Bus faking or queue config. It would have caught the trait being dropped in the
    // copy from SendStaffBroadcastEmailToSubscriberJob (the sibling this pattern came
    // from) the moment this test ran, on any CI driver.
    expect(class_uses_recursive(SendTransactionalNotificationEmailJob::class))
        ->toContain(Batchable::class);
});

it('CACHE-2 regression: Bus::batch() accepts the job for real, without Bus::fake()', function () {
    // Behavioural proof, not just a structural one: constructs a REAL PendingBatch
    // (Bus::batch() is `new PendingBatch($container, $jobs)` — see
    // Illuminate\Bus\Dispatcher::batch()) and asserts construction doesn't throw.
    // Deliberately does NOT call ->dispatch() — that would need a real queue
    // connection and batch repository, which is unrelated to what this bug is about.
    // The Batchable check runs inside the constructor itself, so just building the
    // batch is enough to prove NotificationPublisher::publishMany()'s real Bus::batch()
    // call (no Bus::fake() in production) will not blow up on first real invocation.
    $job = new SendTransactionalNotificationEmailJob('n-batch-proof', 'policy_update', 'pro-batch-proof');

    expect(fn () => Bus::batch([$job]))->not->toThrow(RuntimeException::class);
});
