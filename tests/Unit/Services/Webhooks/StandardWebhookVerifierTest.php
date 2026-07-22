<?php

use App\Services\Webhooks\StandardWebhookVerifier;

// Pure crypto — no Laravel app boot required.

/**
 * Produce a valid `v1,<base64>` signature header for the given key bytes,
 * exactly as Svix/Standard-Webhooks senders do: sign `{id}.{ts}.{body}`.
 */
function signStdWebhook(string $keyBytes, string $id, string $ts, string $body): string
{
    return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$body, $keyBytes, true));
}

it('accepts a bare whsec_ secret (Resend/Svix format, no v1, prefix)', function () {
    $keyBytes = random_bytes(24);
    $secret = 'whsec_'.base64_encode($keyBytes);   // <- Resend's exact secret envelope

    $id = 'msg_'.bin2hex(random_bytes(6));
    $ts = (string) time();
    $body = '{"type":"email.bounced"}';

    $verifier = new StandardWebhookVerifier;

    expect($verifier->verify($secret, $id, $ts, signStdWebhook($keyBytes, $id, $ts, $body), $body))->toBeTrue();
});

it('still accepts the Supabase v1,whsec_ secret format (regression)', function () {
    $keyBytes = 'partna-test-secret-bytes-32-chars!';
    $secret = 'v1,whsec_'.base64_encode($keyBytes);

    $id = 'msg_abc';
    $ts = (string) time();
    $body = '{"hello":"world"}';

    $verifier = new StandardWebhookVerifier;

    expect($verifier->verify($secret, $id, $ts, signStdWebhook($keyBytes, $id, $ts, $body), $body))->toBeTrue();
});

it('rejects a signature made with the wrong key even for a bare whsec_ secret', function () {
    $secret = 'whsec_'.base64_encode(random_bytes(24));

    $id = 'msg_x';
    $ts = (string) time();
    $body = '{"a":1}';
    $wrongSig = signStdWebhook(random_bytes(24), $id, $ts, $body);

    $verifier = new StandardWebhookVerifier;

    expect($verifier->verify($secret, $id, $ts, $wrongSig, $body))->toBeFalse();
});
