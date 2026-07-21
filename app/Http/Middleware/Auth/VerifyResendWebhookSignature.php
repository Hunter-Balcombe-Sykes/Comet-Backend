<?php

namespace App\Http\Middleware\Auth;

use App\Services\Webhooks\StandardWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard Webhooks (Svix) HMAC signature gate for Resend webhook deliveries.
 *
 * Resend signs via Svix — which authored Standard Webhooks — so the crypto is
 * exactly what StandardWebhookVerifier already computes: a `v1,<base64>`
 * signature over `{id}.{timestamp}.{raw-body}`. This gate differs from
 * VerifySupabaseHookSignature only in:
 *   - header namespace: `svix-id` / `svix-timestamp` / `svix-signature`
 *     (Supabase uses `webhook-*`), and
 *   - secret envelope: bare `whsec_<base64>` (Supabase uses `v1,whsec_<base64>`),
 *     handled by StandardWebhookVerifier::decodeSecret().
 *
 * Kept as a separate class rather than folding svix-* into the Supabase gate so
 * the shipped OTP/auth signature path is provably untouched.
 *
 * Returns 503 if the secret is unset (fail-closed — a deploy bug, not a runtime
 * choice), 401 on signature mismatch. Verified against Resend's webhook docs
 * (svix-* headers, `v1,` signature) on 2026-07-21.
 */
class VerifyResendWebhookSignature
{
    public function __construct(
        private readonly StandardWebhookVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.resend.webhook_secret', '');
        if ($secret === '') {
            Log::warning('resend.webhook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => 'hook_not_configured',
                'message' => 'Resend webhook is not configured.',
            ], 503);
        }

        $svixId = (string) $request->header('svix-id', '');
        $svixTimestamp = (string) $request->header('svix-timestamp', '');
        $svixSignature = (string) $request->header('svix-signature', '');
        $rawBody = (string) $request->getContent();

        $valid = $this->verifier->verify(
            configuredSecret: $secret,
            webhookId: $svixId,
            webhookTimestamp: $svixTimestamp,
            webhookSignatureHeader: $svixSignature,
            rawBody: $rawBody,
        );

        if (! $valid) {
            Log::warning('resend.webhook.signature_failed', [
                'svix_id' => $svixId,
                'svix_timestamp' => $svixTimestamp,
            ]);

            return response()->json([
                'error' => 'invalid_signature',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}
