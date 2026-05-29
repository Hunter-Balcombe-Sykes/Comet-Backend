<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    Config::set('partna.moderation.csam.cloudflare_webhook_secret', 'test-secret');
});

function csamWebhookPayload(): array
{
    return [
        'match_id'      => 'm-001',
        'r2_key'        => 'quarantine/foo.jpg',
        'matched_hash'  => 'aabbccdd' . str_repeat('0', 56),
        'confidence'    => 'exact',
    ];
}

function signPayload(array $payload, string $secret, ?int $timestamp = null): array
{
    $ts = $timestamp ?? time();
    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', "{$ts}.{$body}", $secret);
    return [
        'body'    => $body,
        'headers' => [
            'Cf-Webhook-Timestamp' => (string) $ts,
            'Cf-Webhook-Signature' => "t={$ts},v1={$sig}",
        ],
    ];
}

/**
 * Laravel's ->call() takes server vars (6th arg) rather than plain header names.
 * Headers must be prefixed with HTTP_ and uppercased with - → _.
 */
function cfHeaders(array $headers): array
{
    $server = [];
    foreach ($headers as $key => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
    }
    $server['CONTENT_TYPE'] = 'application/json';
    return $server;
}

it('rejects requests with no signature header', function () {
    $res = $this->call('POST', '/api/v1/internal/cloudflare-csam-webhook', [], [], [], [], json_encode(csamWebhookPayload()));
    $res->assertStatus(401);
});

it('rejects requests with bad signature', function () {
    $signed = signPayload(csamWebhookPayload(), 'wrong-secret');
    $res = $this->call('POST', '/api/v1/internal/cloudflare-csam-webhook', [], [], [], cfHeaders($signed['headers']), $signed['body']);
    $res->assertStatus(401);
});

it('accepts a valid signature', function () {
    $signed = signPayload(csamWebhookPayload(), 'test-secret');
    $res = $this->call('POST', '/api/v1/internal/cloudflare-csam-webhook', [], [], [], cfHeaders($signed['headers']), $signed['body']);
    expect($res->status())->not->toBe(401);
});

it('rejects a replay (same timestamp + signature) within nonce window', function () {
    $signed = signPayload(csamWebhookPayload(), 'test-secret');
    $serverVars = cfHeaders($signed['headers']);

    $this->call('POST', '/api/v1/internal/cloudflare-csam-webhook', [], [], [], $serverVars, $signed['body']);

    $second = $this->call('POST', '/api/v1/internal/cloudflare-csam-webhook', [], [], [], $serverVars, $signed['body']);
    $second->assertStatus(409);
});

it('rejects requests with timestamp older than 5 minutes', function () {
    $signed = signPayload(csamWebhookPayload(), 'test-secret', timestamp: time() - 600);
    $res = $this->call('POST', '/api/v1/internal/cloudflare-csam-webhook', [], [], [], cfHeaders($signed['headers']), $signed['body']);
    $res->assertStatus(401);
});
