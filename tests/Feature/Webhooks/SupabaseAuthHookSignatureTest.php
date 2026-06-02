<?php

use App\Services\Auth\SupabaseAuthHookService;

beforeEach(function () {
    config(['supabase.auth_hook_secret' => 'whsec_test_secret_at_least_32_bytes_long_xx']);
});

function signStandardWebhookPayload(string $secret, string $id, int $timestamp, string $body): string
{
    $signedContent = "{$id}.{$timestamp}.{$body}";
    $signature = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

    return "v1,{$signature}";
}

it('accepts a valid Standard Webhooks signature', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc","factor_id":"xyz","valid":true}';
    $id = 'msg_test_1';
    $ts = time();
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    $sig = signStandardWebhookPayload($secret, $id, $ts, $body);

    expect($svc->verifySignature($id, (string) $ts, $sig, $body))->toBeTrue();
});

it('rejects a forged signature', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';

    expect($svc->verifySignature('msg_1', (string) time(), 'v1,wrong_signature', $body))->toBeFalse();
});

it('rejects a signature signed with a different secret', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';
    $id = 'msg_1';
    $ts = time();

    $sig = signStandardWebhookPayload('different_secret', $id, $ts, $body);

    expect($svc->verifySignature($id, (string) $ts, $sig, $body))->toBeFalse();
});

it('rejects a timestamp outside the tolerance window', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';
    $id = 'msg_1';
    $oldTs = time() - 600; // 10 minutes old
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    $sig = signStandardWebhookPayload($secret, $id, $oldTs, $body);

    expect($svc->verifySignature($id, (string) $oldTs, $sig, $body))->toBeFalse();
});

it('accepts when at least one signature in a multi-signature header matches', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';
    $id = 'msg_1';
    $ts = time();
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    $validSig = signStandardWebhookPayload($secret, $id, $ts, $body);
    $combined = "v1,wrong_signature {$validSig}"; // space-separated per Standard Webhooks

    expect($svc->verifySignature($id, (string) $ts, $combined, $body))->toBeTrue();
});
