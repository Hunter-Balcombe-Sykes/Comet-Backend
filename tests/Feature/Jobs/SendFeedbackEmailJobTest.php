<?php

use App\Jobs\Notifications\SendFeedbackEmailJob;
use App\Mail\FeedbackSubmittedMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupFeedbackTable();
    Mail::fake();
});

function seedFeedbackRow(array $overrides = []): string
{
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'p-'.Str::random(6),
        'handle_lc' => 'p-'.Str::random(6),
        'display_name' => 'P',
        'primary_email' => 'p@example.test',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feedback')->insert(array_merge([
        'id' => $id,
        'user_id' => $proId,
        'kind' => 'idea',
        'message' => 'hello',
        'status' => 'new',
        'internal_notes' => '[]',
        'tags' => '[]',
        'source' => 'dashboard',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

it('sends FeedbackSubmittedMail to every configured recipient (privately, one mail per address)', function () {
    config(['partna.feedback.notify_emails' => ['a@partna.test', 'b@partna.test']]);
    $id = seedFeedbackRow();

    (new SendFeedbackEmailJob($id))->handle();

    // One Mailable per recipient; no recipient leakage via shared To: header.
    Mail::assertSent(FeedbackSubmittedMail::class, 2);
    Mail::assertSent(FeedbackSubmittedMail::class, fn ($mail) => $mail->hasTo('a@partna.test'));
    Mail::assertSent(FeedbackSubmittedMail::class, fn ($mail) => $mail->hasTo('b@partna.test'));
});

it('does not throw when notify_emails is empty (logs and returns)', function () {
    config(['partna.feedback.notify_emails' => []]);
    Log::shouldReceive('warning')->once();
    $id = seedFeedbackRow();

    (new SendFeedbackEmailJob($id))->handle();

    Mail::assertNothingSent();
});

it('throws when the feedback row is missing so the queue records a failed_jobs entry (JOB-10)', function () {
    config(['partna.feedback.notify_emails' => ['a@partna.test']]);
    // JOB-10: a missing row must throw — a silent return marks the dropped notification
    // as "succeeded" with no failed_jobs entry, no retry, and no Horizon alert.
    Log::shouldReceive('warning')->once();

    expect(fn () => (new SendFeedbackEmailJob((string) Str::uuid()))->handle())
        ->toThrow(RuntimeException::class, 'feedback row not found');

    Mail::assertNothingSent();
});

it('does not re-send to an already-emailed recipient when the job runs twice (per-recipient cache idempotency)', function () {
    // TEST-3: the per-recipient Cache::add key prevents duplicate delivery on retry.
    // Cache driver is 'array' (phpunit.xml CACHE_STORE=array) — not flushed between
    // the two handle() calls to simulate a Horizon retry with the cache still warm.
    config(['partna.feedback.notify_emails' => ['a@partna.test', 'b@partna.test']]);
    $id = seedFeedbackRow();

    (new SendFeedbackEmailJob($id))->handle();
    (new SendFeedbackEmailJob($id))->handle(); // second run — both recipients already cached

    // Exactly 2 mails total (one per recipient on the FIRST run). The second run
    // hits the Cache::add guard for both and skips them, so the count stays at 2,
    // not 4 — proving per-recipient idempotency across job retries.
    Mail::assertSent(FeedbackSubmittedMail::class, 2);
});
