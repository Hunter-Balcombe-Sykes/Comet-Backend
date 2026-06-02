<?php

namespace App\Services\Email;

use App\Services\Webhooks\StandardWebhookVerifier;

/**
 * Thin adapter over StandardWebhookVerifier for the Send Email Hook.
 * Preserves the interface used by VerifySupabaseEmailHookSignature.
 */
class SupabaseEmailHookSignatureVerifier
{
    public function __construct(private readonly StandardWebhookVerifier $verifier) {}

    /**
     * @param  string  $configuredSecret  raw value from config('services.supabase.email_hook_secret')
     * @return bool true when at least one v1 signature in the header matches
     */
    public function verify(
        string $configuredSecret,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignatureHeader,
        string $rawBody,
    ): bool {
        return $this->verifier->verify(
            configuredSecret: $configuredSecret,
            webhookId: $webhookId,
            webhookTimestamp: $webhookTimestamp,
            webhookSignatureHeader: $webhookSignatureHeader,
            rawBody: $rawBody,
        );
    }
}
