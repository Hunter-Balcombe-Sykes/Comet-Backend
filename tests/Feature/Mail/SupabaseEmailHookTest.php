<?php

use App\Http\Controllers\Api\Internal\SupabaseEmailHookController;
use App\Mail\Auth\EmailConfirmMail;
use App\Mail\Auth\InviteMail;
use App\Mail\Auth\MagicLinkMail;
use App\Mail\Auth\PasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    Config::set('app.frontend_url', 'https://app.partna.au');
});

/**
 * Build a request that Standard-Webhooks would produce. The secret is a
 * known constant so we can pre-compute the matching HMAC.
 *
 * @param  array<string, mixed>  $payload
 * @return array{headers: array<string, string>, body: string}
 */
function makeSupabaseHookRequest(array $payload, ?string $rawBody = null): array
{
    // Raw bytes the test uses as the signing key. `v1,whsec_<base64>` is what
    // Supabase actually emits; we decode it back to these bytes in the verifier.
    $secretBytes = 'partna-test-secret-bytes-32-chars!';
    $configuredSecret = 'v1,whsec_'.base64_encode($secretBytes);
    Config::set('services.supabase.email_hook_secret', $configuredSecret);

    $webhookId = 'msg_'.bin2hex(random_bytes(8));
    $webhookTimestamp = (string) time();
    $body = $rawBody ?? json_encode($payload, JSON_UNESCAPED_SLASHES);

    $signedPayload = $webhookId.'.'.$webhookTimestamp.'.'.$body;
    $signature = base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true));

    return [
        'headers' => [
            'webhook-id' => $webhookId,
            'webhook-timestamp' => $webhookTimestamp,
            'webhook-signature' => 'v1,'.$signature,
            'Content-Type' => 'application/json',
        ],
        'body' => $body,
    ];
}

it('dispatches PasswordResetMail for recovery action', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'tobias@partna.au', 'user_metadata' => ['full_name' => 'Tobias E.']],
        'email_data' => [
            'token_hash' => 'pkce_abc123',
            'redirect_to' => 'https://app.partna.au/auth/callback',
            'email_action_type' => 'recovery',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    $response->assertOk();
    expect($response->json('ok'))->toBeTrue();
    expect($response->json('handled'))->toBeTrue();

    Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $m): bool {
        return $m->recipientEmail === 'tobias@partna.au'
            && $m->displayName === 'Tobias'
            && str_starts_with($m->verifyUrl, 'https://app.partna.au/auth/confirm?')
            && str_contains($m->verifyUrl, 'token_hash=pkce_abc123')
            && str_contains($m->verifyUrl, 'type=recovery')
            && str_contains($m->verifyUrl, 'next=https%3A%2F%2Fapp.partna.au%2Fauth%2Fcallback');
    });
});

it('dispatches MagicLinkMail for magiclink action', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'login@partna.au', 'user_metadata' => []],
        'email_data' => [
            'token_hash' => 'tok_xyz',
            'email_action_type' => 'magiclink',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    $response->assertOk();
    Mail::assertQueued(MagicLinkMail::class);
});

it('dispatches EmailConfirmMail for signup action with the 6-digit code', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'newby@partna.au', 'user_metadata' => ['name' => 'Newby']],
        'email_data' => [
            'token_hash' => 'tok_signup_hash',
            'token' => '142857',
            'email_action_type' => 'signup',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    $response->assertOk();
    Mail::assertQueued(EmailConfirmMail::class, function (EmailConfirmMail $m): bool {
        return $m->recipientEmail === 'newby@partna.au'
            && $m->displayName === 'Newby'
            && $m->code === '142857';
    });
});

it('returns handled=false for signup when Supabase omits the 6-digit code', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'newby@partna.au'],
        'email_data' => [
            'token_hash' => 'tok_signup_hash',
            // 'token' deliberately missing — defensively handled
            'email_action_type' => 'signup',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    $response->assertOk();
    expect($response->json('handled'))->toBeFalse();
    Mail::assertNothingQueued();
});

it('dispatches InviteMail for invite action', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'invitee@partna.au'],
        'email_data' => [
            'token_hash' => 'tok_invite',
            'email_action_type' => 'invite',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    $response->assertOk();
    Mail::assertQueued(InviteMail::class);
});

it('returns 200 with handled=false for unknown action type (no retry)', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'x@partna.au'],
        'email_data' => [
            'token_hash' => 'tok_unknown',
            'email_action_type' => 'reauthentication',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    $response->assertOk();
    expect($response->json('handled'))->toBeFalse();
    Mail::assertNothingQueued();
});

it('rejects requests with an invalid signature (401)', function (): void {
    Config::set('services.supabase.email_hook_secret', 'v1,whsec_'.base64_encode('partna-test-secret-bytes-32-chars!'));

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => 'msg_xxx',
        'HTTP_webhook-timestamp' => (string) time(),
        'HTTP_webhook-signature' => 'v1,not-the-right-signature',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['user' => ['email' => 'x@partna.au'], 'email_data' => ['email_action_type' => 'recovery']]));

    expect($response->status())->toBe(401);
    Mail::assertNothingQueued();
});

it('returns 503 when the secret is not configured', function (): void {
    Config::set('services.supabase.email_hook_secret', '');

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => 'msg_xxx',
        'HTTP_webhook-timestamp' => (string) time(),
        'HTTP_webhook-signature' => 'v1,anything',
        'CONTENT_TYPE' => 'application/json',
    ], '{}');

    expect($response->status())->toBe(503);
});

it('rejects timestamps outside the tolerance window (401)', function (): void {
    $req = makeSupabaseHookRequest(
        ['user' => ['email' => 'x@partna.au'], 'email_data' => ['email_action_type' => 'recovery', 'token_hash' => 't', 'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co']],
    );
    // Override timestamp to 10 minutes ago — well outside the 5-minute window.
    $stale = (string) (time() - 600);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $stale,
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    expect($response->status())->toBe(401);
});

it('treats duplicate webhook-id as a no-op (Supabase retries are idempotent)', function (): void {
    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'retry@partna.au', 'user_metadata' => ['name' => 'Retry']],
        'email_data' => [
            'token_hash' => 'tok_dedup',
            'token' => '424242',
            'email_action_type' => 'signup',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $headers = [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ];

    $first = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], $headers, $req['body']);
    $first->assertOk();
    expect($first->json('handled'))->toBeTrue();
    Mail::assertQueued(EmailConfirmMail::class, 1);

    // Replay the exact same headers + body. Should be acknowledged but not re-dispatched.
    $second = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], $headers, $req['body']);
    $second->assertOk();
    expect($second->json('duplicate'))->toBeTrue();
    expect($second->json('handled'))->toBeFalse();

    // Still only one queued mail across both deliveries.
    Mail::assertQueued(EmailConfirmMail::class, 1);
});

it('forgets the dedup marker when Mail::queue throws so Supabase retries can re-attempt', function (): void {
    // Override the Mail::fake() set in beforeEach with a mock that throws on queue.
    Mail::shouldReceive('queue')->once()->andThrow(new RuntimeException('queue connection down'));

    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'retry@partna.au'],
        'email_data' => [
            'token_hash' => 'tok_fail',
            'email_action_type' => 'recovery',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    expect($response->status())->toBe(500);
    // The dedup key must be gone — otherwise the next Supabase retry sees a
    // stale "seen" marker, returns duplicate=true, and the email is permanently lost.
    expect(Cache::has('supabase:email_hook:seen:'.$req['headers']['webhook-id']))->toBeFalse();
});

it('returns 422 when the payload is missing required fields', function (): void {
    $req = makeSupabaseHookRequest(['user' => [], 'email_data' => []]);

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id' => $req['headers']['webhook-id'],
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $req['body']);

    expect($response->status())->toBe(422);
});

// WHK-5 — deterministic Message-ID from webhook-id -----------------------------------------

it('WHK-5: mailable headers() returns a Message-ID derived from the webhook-id', function (): void {
    Config::set('mail.from.address', 'hello@partna.au');
    $webhookId = 'msg_test_abc123';

    $mail = new PasswordResetMail('user@example.com', null, 'https://example.com/confirm', $webhookId);

    expect($mail->headers()->messageId)->toBe('msg_test_abc123@partna.au');
});

it('WHK-5: two builds with the same webhook-id produce identical Message-IDs (retry is idempotent)', function (): void {
    Config::set('mail.from.address', 'hello@partna.au');
    $webhookId = 'msg_stable_deadbeef';

    $first  = new EmailConfirmMail('user@example.com', 'User', '123456', $webhookId);
    $second = new EmailConfirmMail('user@example.com', 'User', '123456', $webhookId);

    // Both builds — same as what a Horizon retry produces after deserialization —
    // must emit the same Message-ID, not a randomly-generated one.
    expect($first->headers()->messageId)->toBe($second->headers()->messageId)
        ->and($first->headers()->messageId)->toBe('msg_stable_deadbeef@partna.au');
});

it('WHK-5: controller threads the webhook-id into the queued mailable', function (): void {
    Config::set('mail.from.address', 'hello@partna.au');

    $req = makeSupabaseHookRequest([
        'user' => ['email' => 'whk5@partna.au', 'user_metadata' => ['full_name' => 'WHK Five']],
        'email_data' => [
            'token_hash' => 'tok_whk5',
            'redirect_to' => 'https://app.partna.au/auth/callback',
            'email_action_type' => 'recovery',
            'site_url' => 'https://glncumufgaqcmqhzwrxm.supabase.co',
        ],
    ]);

    $webhookId = $req['headers']['webhook-id'];

    $response = $this->call('POST', '/api/internal/email-hooks/supabase', [], [], [], [
        'HTTP_webhook-id'        => $webhookId,
        'HTTP_webhook-timestamp' => $req['headers']['webhook-timestamp'],
        'HTTP_webhook-signature' => $req['headers']['webhook-signature'],
        'CONTENT_TYPE'           => 'application/json',
    ], $req['body']);

    $response->assertOk();

    // The queued mailable must carry the same webhook-id used to sign the request,
    // so a Horizon retry serializes/deserializes the same id and produces the
    // same Message-ID — no new random id is generated on retry.
    Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $m) use ($webhookId): bool {
        return $m->webhookId === $webhookId
            && $m->headers()->messageId === $webhookId.'@partna.au';
    });
});

it('WHK-3: fails closed (400) when the webhook-id header is absent', function (): void {
    // Exercises the controller guard directly — the signature middleware would
    // normally 401 an empty webhook-id, so this proves the controller is
    // independently hardened and never queues an email without an idempotency key.
    $controller = app(SupabaseEmailHookController::class);
    $request = Request::create(
        '/api/internal/email-hooks/supabase',
        'POST',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode([
            'user' => ['email' => 'user@example.com'],
            'email_data' => ['token_hash' => 'h', 'email_action_type' => 'recovery'],
        ]),
    );

    $response = $controller($request);

    expect($response->getStatusCode())->toBe(400);
    Mail::assertNothingQueued();
});
