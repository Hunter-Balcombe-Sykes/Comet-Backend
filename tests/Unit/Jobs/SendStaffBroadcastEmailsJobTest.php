<?php

use App\Jobs\Notifications\SendStaffBroadcastEmailsJob;
use App\Jobs\Notifications\SendStaffBroadcastEmailToSubscriberJob;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupNotificationsTable();
    setupEmailSubscriptionsTable();
});

/**
 * Insert a matching email subscription (professional_id=null, list_key='sidest_updates', status='subscribed').
 */
function insertBroadcastSubscriber(string $email): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $id,
        'professional_id' => null,
        'list_key' => 'sidest_updates',
        'email' => $email,
        'email_lc' => strtolower($email),
        'status' => 'subscribed',
        'unsubscribe_token' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

/**
 * Insert a notifications.notifications row and return its ID.
 */
function insertNotification(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $id,
        'type' => 'Info',
        'category' => 'broadcast',
        'title' => 'Test Broadcast',
        'body' => 'Hello subscribers.',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('does nothing when notification is not found', function () {
    Bus::fake();

    $job = new SendStaffBroadcastEmailsJob((string) Str::uuid());
    $job->handle();

    Bus::assertNothingBatched();
});

it('dispatches one batch when subscriber count is under chunk size', function () {
    Bus::fake();

    $notificationId = insertNotification();

    insertBroadcastSubscriber('sub-a@example.test');
    insertBroadcastSubscriber('sub-b@example.test');
    insertBroadcastSubscriber('sub-c@example.test');

    $job = new SendStaffBroadcastEmailsJob($notificationId);
    $job->handle();

    Bus::assertBatchCount(1);

    Bus::assertBatched(function (PendingBatch $batch) use ($notificationId) {
        return $batch->queue() === 'mail'
            && $batch->jobs->count() === 3
            && $batch->jobs->every(fn ($j) => $j instanceof SendStaffBroadcastEmailToSubscriberJob)
            && str_contains($batch->name, $notificationId);
    });
});

it('only batches subscribed marketing-list rows (filters by status, list_key, professional_id)', function () {
    Bus::fake();

    $notificationId = insertNotification();

    // 1 matching row
    insertBroadcastSubscriber('matching@example.test');

    // Should be excluded: unsubscribed
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => null,
        'list_key' => 'sidest_updates',
        'email' => 'unsub@example.test',
        'email_lc' => 'unsub@example.test',
        'status' => 'unsubscribed',
        'unsubscribe_token' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Should be excluded: professional_id is not null
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => (string) Str::uuid(),
        'list_key' => 'sidest_updates',
        'email' => 'pro@example.test',
        'email_lc' => 'pro@example.test',
        'status' => 'subscribed',
        'unsubscribe_token' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Should be excluded: different list_key
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => null,
        'list_key' => 'other',
        'email' => 'other-list@example.test',
        'email_lc' => 'other-list@example.test',
        'status' => 'subscribed',
        'unsubscribe_token' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new SendStaffBroadcastEmailsJob($notificationId);
    $job->handle();

    Bus::assertBatchCount(1);

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 1);
});

it('splits subscribers into batches of at most 200', function () {
    Bus::fake();

    $notificationId = insertNotification();

    // Insert 350 matching subscribers — expect two batches: 200 + 150.
    $rows = [];
    for ($i = 0; $i < 350; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'professional_id' => null,
            'list_key' => 'sidest_updates',
            'email' => "chunk-subscriber-{$i}@example.test",
            'email_lc' => "chunk-subscriber-{$i}@example.test",
            'status' => 'subscribed',
            'unsubscribe_token' => (string) Str::uuid(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert($rows);

    $job = new SendStaffBroadcastEmailsJob($notificationId);
    $job->handle();

    Bus::assertBatchCount(2);

    // Verify the two batch sizes are 200 and 150 (order may vary).
    $sizes = collect(Bus::dispatchedBatches())
        ->map(fn (PendingBatch $b) => $b->jobs->count())
        ->sort()
        ->values()
        ->all();

    expect($sizes)->toBe([150, 200]);
});

it('sets a bounded uniqueFor so a crashed worker cannot leave a permanent lock', function () {
    // QUEUE-3: ShouldBeUnique without $uniqueFor never expires; a SIGKILL'd
    // worker would block every future broadcast for this notification.
    $job = new SendStaffBroadcastEmailsJob((string) Str::uuid());

    expect($job->uniqueFor)->toBe(600);
});
