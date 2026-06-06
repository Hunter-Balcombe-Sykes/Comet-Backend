<?php

use App\Jobs\Account\SendAccountDeletionRequestMailJob;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function makeDeletionTestUser(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'test-'.substr($id, 0, 8),
        'handle_lc' => 'test-'.substr($id, 0, 8),
        'display_name' => 'Test Pro',
        'primary_email' => 'test-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

it('stores hashed token, sets requested_at, and sends confirmation mail', function () {
    $pro = makeDeletionTestUser();

    $service = new AccountDeletionService;
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->deletion_token_hash)->not->toBeNull()
        ->and(strlen($pro->deletion_token_hash))->toBe(64) // sha256 hex
        ->and($pro->deletion_requested_at)->not->toBeNull()
        ->and($pro->status)->toBe('active'); // status does NOT change on request

    Mail::assertSent(AccountDeletionRequestedMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('writes a requested audit entry on successful request', function () {
    $pro = makeDeletionTestUser();
    $service = new AccountDeletionService;
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_USER_AGENT' => 'TestAgent']);

    $service->request($pro, $request);

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('requested')
        ->and($audit->actor_type)->toBe(UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL)
        ->and($audit->professional_handle_snapshot)->toBe($pro->handle)
        ->and($audit->professional_email_snapshot)->toBe($pro->primary_email)
        ->and($audit->ip_address)->toBe('1.2.3.4')
        ->and($audit->user_agent)->toBe('TestAgent');
});

// ── P2-38 regression: re-request recovers a wedged deletion_mail_sent_at ──────

it('re-request resets deletion_mail_sent_at so a subsequent handle() re-delivers the mail', function () {
    config(['app.frontend_url' => 'https://app.example.test']);

    // Seed a user whose deletion_mail_sent_at is already stamped — the "wedged" state
    // caused by: handle() stamps sent_at → Mail::send() throws → retry returns early
    // (sees sent_at != null) → job marked succeeded → user can never re-trigger the email.
    $pro = makeDeletionTestUser(['deletion_mail_sent_at' => now()->toIso8601String()]);

    $service = new AccountDeletionService;
    $request = Request::create('/', 'POST');

    // Fake the queue so the sync driver does not execute SendAccountDeletionRequestMailJob
    // immediately inside request()'s transaction (which would re-stamp sent_at before we
    // can assert it was reset). The job's own behaviour is covered by its dedicated test file.
    Queue::fake();

    // Re-request: should reset sent_at to null inside the transaction.
    $result = $service->request($pro, $request);

    expect($result['success'])->toBeTrue();

    $pro->refresh();
    expect($pro->deletion_mail_sent_at)->toBeNull(); // wedge lifted — re-delivery is now possible

    // Verify recovery: manually run handle() with a fresh matching token to prove the
    // idempotency guard now allows the mail through.
    $rawToken = 'recovery-token-'.Str::random(48);
    DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)
        ->update(['deletion_token_hash' => hash('sha256', $rawToken)]);

    Mail::fake(); // resume mail interception for the handle() call below
    $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
        .'/account/deletion/confirm?token='.$rawToken;
    $job = new SendAccountDeletionRequestMailJob($pro->id, $confirmationUrl, hash('sha256', $rawToken));
    $job->handle();

    Mail::assertSent(AccountDeletionRequestedMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('rolls back token storage if mail send throws', function () {
    $pro = makeDeletionTestUser();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    $service = new AccountDeletionService;
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503);

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});
