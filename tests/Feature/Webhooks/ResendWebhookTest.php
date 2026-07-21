<?php

use App\Models\Core\EmailSuppression;
use App\Support\EmailHasher;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    setupEmailSuppressionsTable();
});

/**
 * Build a request shaped exactly like a Resend/Svix webhook delivery: svix-*
 * headers, a bare `whsec_<base64>` secret, and a `v1,<sig>` signature over
 * `{id}.{timestamp}.{raw-body}`.
 *
 * @param  array<string, mixed>  $payload
 * @return array{server: array<string, string>, body: string}
 */
function makeResendWebhookRequest(array $payload, ?string $rawBody = null): array
{
    $keyBytes = random_bytes(24);
    Config::set('services.resend.webhook_secret', 'whsec_'.base64_encode($keyBytes));

    $id = 'msg_'.bin2hex(random_bytes(8));
    $ts = (string) time();
    $body = $rawBody ?? json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $keyBytes, true));

    return [
        'server' => [
            'HTTP_svix-id' => $id,
            'HTTP_svix-timestamp' => $ts,
            'HTTP_svix-signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ],
        'body' => $body,
    ];
}

/** @param array<string,mixed> $overrides */
function bouncePayload(string $to, string $bounceType, string $subType = 'General'): array
{
    return [
        'type' => 'email.bounced',
        'created_at' => '2026-11-22T23:41:12.126Z',
        'data' => [
            'email_id' => '56761188-7520-42d8-8898-ff6fc54ce618',
            'from' => 'Partna <hello@partna.au>',
            'to' => [$to],
            'subject' => 'Your verification code',
            'bounce' => [
                'message' => 'The recipient does not exist.',
                'subType' => $subType,
                'type' => $bounceType,
            ],
        ],
    ];
}

function postResend(array $req): TestResponse
{
    return test()->call('POST', '/api/internal/webhooks/resend', [], [], [], $req['server'], $req['body']);
}

it('suppresses a permanent (hard) bounce', function () {
    $req = makeResendWebhookRequest(bouncePayload('dead@example.com', 'Permanent', 'Suppressed'));

    $res = postResend($req);

    expect($res->status())->toBe(200);
    $row = EmailSuppression::where('email_hash', EmailHasher::hash('dead@example.com'))->first();
    expect($row)->not->toBeNull()
        ->and($row->reason)->toBe('hard_bounce')
        ->and($row->source)->toBe('resend')
        ->and($row->detail)->toBe('Suppressed');
});

it('does NOT suppress a transient (soft) bounce', function () {
    $req = makeResendWebhookRequest(bouncePayload('temp@example.com', 'Transient', 'MailboxFull'));

    $res = postResend($req);

    // Acknowledged (2xx so Resend does not retry) but no suppression written.
    expect($res->status())->toBe(200)
        ->and(EmailSuppression::count())->toBe(0);
});

it('suppresses a spam complaint', function () {
    $req = makeResendWebhookRequest([
        'type' => 'email.complained',
        'created_at' => '2026-02-22T23:41:12.126Z',
        'data' => [
            'email_id' => '56761188-7520-42d8-8898-ff6fc54ce618',
            'from' => 'Partna <hello@partna.au>',
            'to' => ['angry@example.com'],
            'subject' => 'Your verification code',
        ],
    ]);

    $res = postResend($req);

    expect($res->status())->toBe(200);
    $row = EmailSuppression::where('email_hash', EmailHasher::hash('angry@example.com'))->first();
    expect($row)->not->toBeNull()
        ->and($row->reason)->toBe('complaint');
});

it('ignores unhandled event types without suppressing (2xx, no retry)', function () {
    $req = makeResendWebhookRequest([
        'type' => 'email.delivered',
        'created_at' => '2026-02-22T23:41:12.126Z',
        'data' => ['to' => ['fine@example.com']],
    ]);

    $res = postResend($req);

    expect($res->status())->toBe(200)
        ->and(EmailSuppression::count())->toBe(0);
});

it('rejects an invalid signature with 401', function () {
    $req = makeResendWebhookRequest(bouncePayload('x@example.com', 'Permanent'));
    $req['server']['HTTP_svix-signature'] = 'v1,not-a-valid-signature';

    $res = postResend($req);

    expect($res->status())->toBe(401)
        ->and(EmailSuppression::count())->toBe(0);
});

it('fails closed with 503 when the webhook secret is unconfigured', function () {
    $req = makeResendWebhookRequest(bouncePayload('x@example.com', 'Permanent'));
    Config::set('services.resend.webhook_secret', '');

    $res = postResend($req);

    expect($res->status())->toBe(503);
});

it('is idempotent — the same bounce delivered twice yields one row', function () {
    $req = makeResendWebhookRequest(bouncePayload('dupe@example.com', 'Permanent', 'Suppressed'));

    postResend($req)->assertStatus(200);
    postResend($req)->assertStatus(200);

    expect(EmailSuppression::where('email_hash', EmailHasher::hash('dupe@example.com'))->count())->toBe(1);
});

it('never 500s the webhook when the suppression write fails (Resend would hammer retries)', function () {
    // Drop the table so the insert throws inside the service — the webhook must
    // still acknowledge with a 2xx rather than surfacing a 500.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS core.email_suppressions');

    $req = makeResendWebhookRequest(bouncePayload('dead@example.com', 'Permanent'));

    $res = postResend($req);

    expect($res->status())->toBeIn([200, 202]);
});
