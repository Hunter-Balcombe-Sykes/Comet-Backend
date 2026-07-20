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

// ── WHK-102: double-submit idempotency guard ──────────────────────────────
//
// lockForUpdate is a no-op under SQLite (no row-level locking), so these tests
// prove the GUARD LOGIC — the second call sees what the first call already
// wrote — not lock-based concurrency safety. True concurrent-request locking
// can only be verified against real Postgres.

it('double-submit with no Idempotency-Key header mints exactly one token and audit row', function () {
    Queue::fake();
    $pro = makeDeletionTestUser();

    $first = actingAsUser($pro)->postJson('/api/me/deletion/request');
    $second = actingAsUser($pro)->postJson('/api/me/deletion/request');

    // A double-tap must not surface an error to the user — both calls succeed.
    $first->assertStatus(200);
    $second->assertStatus(200);

    Queue::assertPushed(SendAccountDeletionRequestMailJob::class, 1);

    $count = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'requested')
        ->count();
    expect($count)->toBe(1);
});

it('double-submit with a shared Idempotency-Key does not double-count on top of the middleware fast-path', function () {
    Queue::fake();
    $pro = makeDeletionTestUser();
    $idempotencyKey = '11111111-2222-4333-8444-555555555555';

    // Same key on both calls: the `idempotent` middleware's own cache fast-path
    // replays the first response for the second call without invoking the
    // controller at all. Assertions must hold either way — this proves the
    // middleware layer and the new domain-level guard don't conflict.
    $first = actingAsUser($pro)->postJson('/api/me/deletion/request', [], [
        'Idempotency-Key' => $idempotencyKey,
    ]);
    $second = actingAsUser($pro)->postJson('/api/me/deletion/request', [], [
        'Idempotency-Key' => $idempotencyKey,
    ]);

    $first->assertStatus(200);
    $second->assertStatus(200);

    Queue::assertPushed(SendAccountDeletionRequestMailJob::class, 1);

    $count = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'requested')
        ->count();
    expect($count)->toBe(1);
});

it('re-request after the 24h token window has expired mints a second token and audit row', function () {
    // This is the test that catches a missing (or mis-windowed) expiry check:
    // without it, the guard would treat the first, now-stale token as still
    // "active" forever and silently block every re-request.
    $pro = makeDeletionTestUser();
    $service = new AccountDeletionService;

    $firstResult = $service->request($pro, Request::create('/', 'POST'));
    expect($firstResult['success'])->toBeTrue();

    test()->travel(25)->hours();

    // Reload from DB before the second call — a real second HTTP request always
    // loads a fresh model (LoadCurrentUser middleware), so it sees the mail job's
    // out-of-band write to deletion_mail_sent_at. Reusing the same in-memory $pro
    // instance here would make Eloquent's dirty-check treat the reset-to-null
    // write as a no-op (it was already null in memory), which is a test-harness
    // artifact, not real behaviour.
    $pro->refresh();

    $secondResult = $service->request($pro, Request::create('/', 'POST'));
    expect($secondResult['success'])->toBeTrue();

    $count = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'requested')
        ->count();
    expect($count)->toBe(2);

    Mail::assertSent(AccountDeletionRequestedMail::class, 2);
});

it('treats a token with no requested_at as stale and mints a fresh one (guard must not lock the user out)', function () {
    Queue::fake();

    // Reachable via a crash/rollback between the two update() columns writing, or a
    // hand-edited row: if the guard ever drops the `deletion_requested_at !== null`
    // check (or reorders it after the Carbon::parse call), this state either throws
    // on Carbon::parse(null) or is read as "still active" — permanently locking the
    // user out of self-service deletion with no recovery path (GDPR-relevant).
    $pro = makeDeletionTestUser([
        'deletion_token_hash' => hash('sha256', 'stale-half-written-token'),
        'deletion_requested_at' => null,
    ]);

    $response = actingAsUser($pro)->postJson('/api/me/deletion/request');

    $response->assertStatus(200);
    Queue::assertPushed(SendAccountDeletionRequestMailJob::class, 1);

    $count = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'requested')
        ->count();
    expect($count)->toBe(1);

    $pro->refresh();
    expect($pro->deletion_requested_at)->not->toBeNull();
});
