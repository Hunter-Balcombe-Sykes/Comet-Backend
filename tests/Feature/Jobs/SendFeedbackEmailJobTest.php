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

it('logs and returns when the feedback row is missing', function () {
    config(['partna.feedback.notify_emails' => ['a@partna.test']]);
    Log::shouldReceive('warning')->once();

    (new SendFeedbackEmailJob((string) Str::uuid()))->handle();

    Mail::assertNothingSent();
});
