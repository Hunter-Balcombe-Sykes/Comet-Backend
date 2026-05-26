<?php

use App\Jobs\Account\SendAccountDeletionRequestMailJob;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

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

    $job = new SendAccountDeletionRequestMailJob($pro->id, $rawToken);
    $job->handle();

    Mail::assertSent(AccountDeletionRequestedMail::class, function ($mail) use ($pro, $rawToken) {
        return $mail->hasTo($pro->primary_email)
            && str_contains($mail->confirmationUrl, $rawToken)
            && str_contains($mail->confirmationUrl, 'app.example.test');
    });
});

it('handle() is a no-op when the professional row no longer exists', function () {
    $missingId = (string) Str::uuid();

    $job = new SendAccountDeletionRequestMailJob($missingId, 'irrelevant-token');
    $job->handle();

    Mail::assertNothingSent();
});

it('failed() clears the deletion token when the row still holds this jobs token hash', function () {
    $rawToken = 'token-'.Str::random(58);
    $pro = seedUserWithToken($rawToken);

    $job = new SendAccountDeletionRequestMailJob($pro->id, $rawToken);
    $job->failed(new \RuntimeException('SMTP permanently down'));

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
    $job = new SendAccountDeletionRequestMailJob($pro->id, $oldRawToken);
    $job->failed(new \RuntimeException('SMTP permanently down'));

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBe(hash('sha256', $newRawToken))
        ->and($pro->deletion_requested_at)->not->toBeNull();
});

it('failed() is safe when the professional row no longer exists', function () {
    $missingId = (string) Str::uuid();

    $job = new SendAccountDeletionRequestMailJob($missingId, 'irrelevant-token');

    expect(fn () => $job->failed(new \RuntimeException('SMTP down')))
        ->not->toThrow(\Throwable::class);
});
