<?php

namespace App\Services\Webhooks;

/**
 * Standard Webhooks HMAC-SHA256 verification per https://www.standardwebhooks.com/
 *
 * Handles the Supabase secret format `v1,whsec_<base64>`, the Resend/Svix format
 * `whsec_<base64>` (both: the bytes after `whsec_` are the signing key), bare
 * base64, and plain string as a fallback.
 *
 * Signed payload: {webhook-id}.{webhook-timestamp}.{raw-body}
 * Header:         webhook-signature: v1,<base64-sig> [v1,<sig2> ...]  (space-separated during rotation)
 */
class StandardWebhookVerifier
{
    /**
     * @param  string  $configuredSecret  raw value from config (e.g. `v1,whsec_<base64>`)
     * @return bool true when at least one v1 signature in the header matches
     */
    public function verify(
        string $configuredSecret,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignatureHeader,
        string $rawBody,
    ): bool {
        if ($configuredSecret === '' || $webhookId === '' || $webhookTimestamp === '' || $webhookSignatureHeader === '') {
            return false;
        }

        // Guard against non-numeric timestamps before casting to int.
        if (! ctype_digit($webhookTimestamp)) {
            return false;
        }

        if (abs(time() - (int) $webhookTimestamp) > $this->timestampTolerance()) {
            return false;
        }

        $secretBytes = $this->decodeSecret($configuredSecret);
        if ($secretBytes === null) {
            return false;
        }

        $signedPayload = $webhookId.'.'.$webhookTimestamp.'.'.$rawBody;
        $expected = base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true));

        foreach ($this->parseSignatures($webhookSignatureHeader) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CFG-1: shared with SupabaseEmailHookController's dedup TTL — one config
     * key so the signature-replay window and the dedup-anchor lifetime can't
     * drift apart (partna.cache.ttls.webhook_timestamp_tolerance).
     *
     * Guarded so this class stays instantiable with no Laravel app booted
     * (see StandardWebhookVerifierTest — "pure crypto, no boot required"):
     * the container helper always exists, but a bare, un-bootstrapped
     * Container has nothing bound under the 'config' abstract, so calling
     * config() directly would throw BindingResolutionException.
     */
    private function timestampTolerance(): int
    {
        if (! app()->bound('config')) {
            return 300;
        }

        return (int) config('partna.cache.ttls.webhook_timestamp_tolerance', 300);
    }

    /**
     * Decodes a Supabase-issued secret into its raw signing bytes.
     *
     * @return string|null binary signing-key bytes, or null if format is unrecognised
     */
    private function decodeSecret(string $configuredSecret): ?string
    {
        // Supabase format: `v1,whsec_<base64>` — the bytes after the prefix are the key.
        if (str_starts_with($configuredSecret, 'v1,whsec_')) {
            $bytes = base64_decode(substr($configuredSecret, strlen('v1,whsec_')), true);

            return $bytes === false ? null : $bytes;
        }

        // Resend/Svix format: bare `whsec_<base64>` (no `v1,` prefix). Same as
        // above minus the version tag — the base64 after `whsec_` decodes to the
        // signing key bytes. Placed before the bare-base64 branch: the `_` in
        // `whsec_` fails that branch's regex, so without this a Resend secret
        // would fall through to "plain string" and HMAC with the literal
        // `whsec_…` text (silent mismatch → every delivery 401s).
        //
        // Unlike the `v1,whsec_` branch, a decode failure here FALLS THROUGH
        // rather than returning null: bare `whsec_` is ambiguous — it may be a
        // real Svix secret OR a plain/dev secret that merely starts with those
        // bytes and isn't base64 (see SupabaseAuthHookSignatureTest's plain-string
        // case). Returning null would wrongly reject such a secret.
        if (str_starts_with($configuredSecret, 'whsec_')) {
            $bytes = base64_decode(substr($configuredSecret, strlen('whsec_')), true);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }

        // Bare base64 (legacy / manual rotations).
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $configuredSecret)) {
            $bytes = base64_decode($configuredSecret, true);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }

        // Plain string — use directly (covers dev secrets with underscores etc.).
        return $configuredSecret;
    }

    /**
     * Parses space-separated `v1,<sig>` tokens from the webhook-signature header.
     *
     * @return list<string> base64 signature values, in header order
     */
    private function parseSignatures(string $header): array
    {
        $sigs = [];
        foreach (preg_split('/\s+/', trim($header)) as $part) {
            if ($part === '' || ! str_starts_with($part, 'v1,')) {
                continue;
            }
            $sigs[] = substr($part, 3);
        }

        return $sigs;
    }
}
