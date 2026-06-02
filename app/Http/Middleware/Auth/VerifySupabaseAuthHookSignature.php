<?php

namespace App\Http\Middleware\Auth;

use App\Services\Webhooks\StandardWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates POST /webhooks/supabase/auth/* by verifying the Standard Webhooks HMAC
 * signature Supabase attaches to every auth hook delivery.
 *
 * Returns 503 if the secret is unset (fail-closed — deploy bug, not a runtime choice).
 * Returns 401 on signature mismatch. Delegates verification to StandardWebhookVerifier.
 */
class VerifySupabaseAuthHookSignature
{
    public function __construct(
        private readonly StandardWebhookVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('supabase.auth_hook_secret', '');
        if ($secret === '') {
            Log::warning('supabase.auth_hook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => true,
                'message' => 'Auth hook is not configured.',
            ], 503);
        }

        $webhookId = (string) $request->header('webhook-id', '');
        $webhookTimestamp = (string) $request->header('webhook-timestamp', '');
        $webhookSignature = (string) $request->header('webhook-signature', '');
        $rawBody = (string) $request->getContent();

        $valid = $this->verifier->verify(
            configuredSecret: $secret,
            webhookId: $webhookId,
            webhookTimestamp: $webhookTimestamp,
            webhookSignatureHeader: $webhookSignature,
            rawBody: $rawBody,
        );

        if (! $valid) {
            Log::warning('supabase.auth_hook.signature_failed', [
                'webhook_id' => $webhookId,
                'webhook_timestamp' => $webhookTimestamp,
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}
