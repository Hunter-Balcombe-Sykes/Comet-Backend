<?php

namespace App\Http\Middleware\Auth;

use App\Services\Webhooks\StandardWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unified Standard Webhooks HMAC signature gate for Supabase webhook deliveries
 * (auth hook + send-email hook). Behavior is identical for both callers; only
 * the config path, log prefix, and 503 label differ — supplied per route via
 * the middleware alias, e.g. `supabase.auth-hook` / `supabase.email-hook` in
 * bootstrap/app.php.
 *
 * Returns 503 if the secret is unset (fail-closed — deploy bug, not a runtime
 * choice). Returns 401 on signature mismatch. Delegates verification to
 * StandardWebhookVerifier.
 */
class VerifySupabaseHookSignature
{
    public function __construct(
        private readonly StandardWebhookVerifier $verifier,
    ) {}

    /**
     * @param  string  $configKey  dot-path passed to config(), e.g. 'services.supabase.auth_hook_secret'
     * @param  string  $logPrefix  prefix for the `{prefix}.misconfigured` / `{prefix}.signature_failed` log events
     * @param  string  $label  renders into the 503 body as "{label} hook is not configured."
     */
    public function handle(Request $request, Closure $next, string $configKey, string $logPrefix, string $label): Response
    {
        $secret = (string) config($configKey, '');
        if ($secret === '') {
            Log::warning("{$logPrefix}.misconfigured", ['reason' => 'secret_missing']);

            return response()->json([
                'error' => 'hook_not_configured',
                'message' => "{$label} hook is not configured.",
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
            Log::warning("{$logPrefix}.signature_failed", [
                'webhook_id' => $webhookId,
                'webhook_timestamp' => $webhookTimestamp,
            ]);

            return response()->json([
                'error' => 'invalid_signature',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}
