<?php

use App\Jobs\Account\SendAccountDeletionRequestMailJob;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\User\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

// Mirror AccountDeletionService::request(): build the confirmation URL (carries
// the raw token) and the sha256 hash, then construct the job exactly as prod does.
function makeDeletionJob(string $userId, string $rawToken): SendAccountDeletionRequestMailJob
{
    $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
        .'/account/deletion/confirm?token='.$rawToken;

    return new SendAccountDeletionRequestMailJob(
        $userId,
        $confirmationUrl,
        hash('sha256', $rawToken),
    );
}

function seedUserWithToken(string $rawToken): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Test Pro',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
        'deletion_token_hash' => hash('sha256', $rawToken),
        'deletion_requested_at' => now()->toIso8601String(),
    ]);

    return User::query()->where('id', $id)->first();
}

it('handle() sends AccountDeletionRequestedMail to the professional with a confirmation URL containing the raw token', function () {
    config(['app.frontend_url' => 'https://app.example.test']);

    $rawToken = 'token-'.Str::random(58);
    $pro = seedUserWithToken($rawToken);

    $job = makeDeletionJob($pro->id, $rawToken);
    $job->handle();

    Mail::assertSent(AccountDeletionRequestedMail::class, function ($mail) use ($pro, $rawToken) {
        return $mail->hasTo($pro->primary_email)
            && str_contains($mail->confirmationUrl, $rawToken)
            && str_contains($mail->confirmationUrl, 'app.example.test');
    });
});

it('handle() is a no-op when the professional row no longer exists', function () {
    $missingId = (string) Str::uuid();

    $job = makeDeletionJob($missingId, 'irrelevant-token');
    $job->handle();

    Mail::assertNothingSent();
});

it('failed() clears the deletion token when the row still holds this jobs token hash', function () {
    $rawToken = 'token-'.Str::random(58);
    $pro = seedUserWithToken($rawToken);

    $job = makeDeletionJob($pro->id, $rawToken);
    $job->failed(new RuntimeException('SMTP permanently down'));

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('failed() leaves the row alone when the token has been rotated by a fresh request()', function () {
    $oldRawToken = 'old-token-'.Str::random(54);
    $newRawToken = 'new-token-'.Str::random(54);

    // Row currently holds the NEW token (user re-requested after the old job failed)
    $pro = seedUserWithToken($newRawToken);

    // The OLD job's failed() fires later — must not trample the new token.
    $job = makeDeletionJob($pro->id, $oldRawToken);
    $job->failed(new RuntimeException('SMTP permanently down'));

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBe(hash('sha256', $newRawToken))
        ->and($pro->deletion_requested_at)->not->toBeNull();
});

it('failed() is safe when the professional row no longer exists', function () {
    $missingId = (string) Str::uuid();

    $job = makeDeletionJob($missingId, 'irrelevant-token');

    expect(fn () => $job->failed(new RuntimeException('SMTP down')))
        ->not->toThrow(Throwable::class);
});

// ── Idempotency guard tests (P2-38) ────────────────────────────────────────

it('handle() sends the mail only once when dispatched twice (retry simulation)', function () {
    config(['app.frontend_url' => 'https://app.example.test']);

    $rawToken = 'token-'.Str::random(58);
    $pro = seedUserWithToken($rawToken);

    $job = makeDeletionJob($pro->id, $rawToken);

    // First execution: mail is sent, deletion_mail_sent_at is stamped.
    $job->handle();
    Mail::assertSentTimes(AccountDeletionRequestedMail::class, 1);

    $pro->refresh();
    expect($pro->deletion_mail_sent_at)->not->toBeNull();

    // Second execution (simulated retry): guard sees deletion_mail_sent_at IS NOT NULL
    // and returns early — mail should still have been sent exactly once total.
    $job->handle();
    Mail::assertSentTimes(AccountDeletionRequestedMail::class, 1);
});

it('handle() does not send when the deletion token hash no longer matches', function () {
    config(['app.frontend_url' => 'https://app.example.test']);

    $originalToken = 'original-'.Str::random(54);
    $rotatedToken = 'rotated-'.Str::random(55);
    $pro = seedUserWithToken($rotatedToken); // row holds the NEW token

    // Job was dispatched with the OLD token — should not send.
    $job = makeDeletionJob($pro->id, $originalToken);
    $job->handle();

    Mail::assertNothingSent();

    $pro->refresh();
    expect($pro->deletion_mail_sent_at)->toBeNull();
});

// ── Bearer-credential payload/log hygiene (#P2-22) ─────────────────────────
// The confirmation URL embeds the raw deletion token (the consume side
// hash-matches it), so the URL is a bearer secret. These tests are the sentinel
// for the security invariant: the raw token must never sit in the plaintext
// Redis payload, and never reach the logs.

it('marks the job ShouldBeEncrypted so the raw-token-bearing payload is ciphered at rest', function () {
    $job = makeDeletionJob((string) Str::uuid(), 'token-'.Str::random(58));

    // The contract is what flips Laravel's Queue::createObjectPayload() to encrypt
    // serialize($job) before it touches Redis (Queue.php jobShouldBeEncrypted()).
    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('keeps the raw token out of the plaintext serialized queue payload', function () {
    $rawToken = 'token-'.Str::random(58);
    $job = makeDeletionJob((string) Str::uuid(), $rawToken);

    // Build the exact JSON payload the framework would push to Redis. createPayload()
    // is protected, so reach it via reflection — this is the real production path:
    // it honours ShouldBeEncrypted (Encrypter is bound via APP_KEY in phpunit.xml),
    // so the 'command' field is ciphertext, not serialize($job).
    $connection = app('queue')->connection();
    $createPayload = (new ReflectionClass(Illuminate\Queue\Queue::class))->getMethod('createPayload');
    $payload = $createPayload->invoke($connection, $job, 'notifications');

    expect($payload)->not->toContain($rawToken);

    // Sanity: prove the assertion has teeth — the raw token IS present in the plain
    // serialization, so the only reason it's absent above is the encryption layer.
    expect(serialize($job))->toContain($rawToken);

    // Decrypting the payload recovers the URL (and thus the token) — confirming the
    // credential is genuinely carried, just protected at rest rather than stripped.
    $decrypted = app('encrypter')->decrypt(json_decode($payload, true)['data']['command']);
    expect($decrypted)->toContain($rawToken);
});

it('failed() never logs the raw token or the confirmation URL — only the hash-derived signal', function () {
    $rawToken = 'token-'.Str::random(58);
    $pro = seedUserWithToken($rawToken);

    $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
        .'/account/deletion/confirm?token='.$rawToken;

    $captured = [];
    Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$captured) {
        $captured[] = ['message' => $message, 'context' => $context];
    });

    $job = makeDeletionJob($pro->id, $rawToken);
    $job->failed(new RuntimeException('SMTP permanently down'));

    // Nothing logged anywhere (report()'s handler entry included) may carry the
    // bearer credential or the URL that embeds it.
    $flattened = json_encode($captured);
    expect($flattened)
        ->not->toContain($rawToken)
        ->and($flattened)->not->toContain($confirmationUrl);

    // failed()'s own entry references only non-sensitive identifiers: the user id
    // and a boolean derived from the token-hash WHERE clause.
    $entry = collect($captured)->firstWhere('message', 'Account deletion request mail failed');
    expect($entry)->not->toBeNull()
        ->and($entry['context'])
        ->toHaveKey('user_id', $pro->id)
        ->toHaveKey('token_cleared');
});
