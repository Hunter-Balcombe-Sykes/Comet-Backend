<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static shared-secret gate for the ManyChat build webhook.
 *
 * Deliberately NOT VerifySupabaseHookSignature: that verifies a Standard
 * Webhooks HMAC over the body, and ManyChat's External Request action cannot
 * produce one (spec §5.1). This is the weaker scheme, chosen knowingly.
 *
 * 503 when the secret is unset — a deploy bug, fail-closed, matching
 * VerifySupabaseHookSignature's contract. 401 on mismatch.
 */
class VerifyManyChatWebhook
{
    public const HEADER = 'X-Partna-Webhook-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.manychat.webhook_secret', '');
        if ($secret === '') {
            Log::warning('manychat.webhook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => 'hook_not_configured',
                'message' => 'ManyChat hook is not configured.',
            ], 503);
        }

        $presented = (string) $request->header(self::HEADER, '');

        if ($presented === '' || ! hash_equals($secret, $presented)) {
            Log::warning('manychat.webhook.signature_failed');

            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid webhook credentials.',
            ], 401);
        }

        return $next($request);
    }
}
