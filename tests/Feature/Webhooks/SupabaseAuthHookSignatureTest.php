<?php

use App\Services\Webhooks\StandardWebhookVerifier;

function buildSignature(string $secret, string $id, int $timestamp, string $body): string
{
    $signedContent = "{$id}.{$timestamp}.{$body}";

    return 'v1,'.base64_encode(hash_hmac('sha256', $signedContent, $secret, true));
}

it('accepts a valid Standard Webhooks signature with a plain-string secret', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';
    $id = 'msg_test_1';
    $ts = time();
    $body = '{"user_id":"abc","factor_id":"xyz","valid":true}';
    $sig = buildSignature($secret, $id, $ts, $body);

    expect($verifier->verify($secret, $id, (string) $ts, $sig, $body))->toBeTrue();
});

it('accepts a v1,whsec_<base64> secret (Supabase format)', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $rawKey = random_bytes(32);
    $configuredSecret = 'v1,whsec_'.base64_encode($rawKey);
    $id = 'msg_supabase_format';
    $ts = time();
    $body = '{"user_id":"abc"}';
    $signedContent = "{$id}.{$ts}.{$body}";
    $sig = 'v1,'.base64_encode(hash_hmac('sha256', $signedContent, $rawKey, true));

    expect($verifier->verify($configuredSecret, $id, (string) $ts, $sig, $body))->toBeTrue();
});

it('rejects a forged signature', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    expect($verifier->verify($secret, 'msg_1', (string) time(), 'v1,wrong_signature', '{"user_id":"abc"}'))->toBeFalse();
});

it('rejects a signature signed with a different secret', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';
    $id = 'msg_1';
    $ts = time();
    $body = '{"user_id":"abc"}';
    $sig = buildSignature('different_secret', $id, $ts, $body);

    expect($verifier->verify($secret, $id, (string) $ts, $sig, $body))->toBeFalse();
});

it('rejects a timestamp outside the tolerance window', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';
    $id = 'msg_1';
    $oldTs = time() - 600;
    $body = '{"user_id":"abc"}';
    $sig = buildSignature($secret, $id, $oldTs, $body);

    expect($verifier->verify($secret, $id, (string) $oldTs, $sig, $body))->toBeFalse();
});

it('rejects a non-numeric timestamp', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    expect($verifier->verify($secret, 'msg_1', 'not-a-number', 'v1,sig', '{}'))->toBeFalse();
});

it('rejects when any required header is empty', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';
    $ts = (string) time();
    $sig = 'v1,sig';

    expect($verifier->verify('', 'msg_1', $ts, $sig, '{}'))->toBeFalse();
    expect($verifier->verify($secret, '', $ts, $sig, '{}'))->toBeFalse();
    expect($verifier->verify($secret, 'msg_1', '', $sig, '{}'))->toBeFalse();
    expect($verifier->verify($secret, 'msg_1', $ts, '', '{}'))->toBeFalse();
});

it('accepts when at least one signature in a multi-signature header matches', function () {
    $verifier = app(StandardWebhookVerifier::class);
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';
    $id = 'msg_1';
    $ts = time();
    $body = '{"user_id":"abc"}';
    $validSig = buildSignature($secret, $id, $ts, $body);
    $combined = "v1,wrong_signature {$validSig}";

    expect($verifier->verify($secret, $id, (string) $ts, $combined, $body))->toBeTrue();
});
