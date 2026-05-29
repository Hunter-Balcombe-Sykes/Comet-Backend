<?php

namespace App\Http\Middleware\Cloudflare;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Cloudflare webhook signatures and prevents replays.
 *
 * Signature scheme: HMAC-SHA256("<ts>.<body>", secret)
 * Header format:    Cf-Webhook-Signature: "t=<ts>,v1=<hex>"
 *
 * Rejects:
 * - Missing signature header → 401
 * - Bad HMAC → 401
 * - Timestamp older than 5 minutes → 401 (clock-skew window)
 * - Signature seen in last 10 minutes (Redis nonce) → 409
 */
class VerifyCloudflareWebhookSignature
{
    private const SKEW_SECONDS = 300;        // 5 minutes
    private const NONCE_TTL    = 600;        // 10 minutes

    public function handle(Request $request, Closure $next): Response
    {
        $sigHeader = $request->header('Cf-Webhook-Signature');
        $tsHeader  = $request->header('Cf-Webhook-Timestamp');

        if ($sigHeader === null || $tsHeader === null) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        if (! preg_match('/t=(\d+),v1=([a-f0-9]+)/', $sigHeader, $m)) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }
        [, $ts, $providedHex] = $m;
        $ts = (int) $ts;

        if (abs(time() - $ts) > self::SKEW_SECONDS) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        // Fail-closed: reject immediately if the secret is not configured.
        // hash_hmac() coerces null → '' and would accept attacker-crafted
        // signatures signed with the same empty string key.
        $secret = config('partna.moderation.csam.cloudflare_webhook_secret');
        if (! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        $body   = $request->getContent();
        $expected = hash_hmac('sha256', "{$ts}.{$body}", $secret);

        if (! hash_equals($expected, $providedHex)) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        // phpredis returns false on a failed NX set; Predis returns null.
        // Treat any falsy return as "key already existed" → replay.
        $nonceKey = "moderation:cf_webhook_nonce:{$providedHex}";
        if (! Redis::set($nonceKey, '1', 'EX', self::NONCE_TTL, 'NX')) {
            return response()->json(['error' => 'REPLAY_DETECTED'], 409);
        }

        return $next($request);
    }
}
