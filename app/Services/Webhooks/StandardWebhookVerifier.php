<?php

namespace App\Services\Webhooks;

/**
 * Standard Webhooks HMAC-SHA256 verification per https://www.standardwebhooks.com/
 *
 * Handles the Supabase secret format `v1,whsec_<base64>` (the bytes after `whsec_`
 * are the signing key), bare base64, and plain string as a fallback.
 *
 * Signed payload: {webhook-id}.{webhook-timestamp}.{raw-body}
 * Header:         webhook-signature: v1,<base64-sig> [v1,<sig2> ...]  (space-separated during rotation)
 */
class StandardWebhookVerifier
{
    /** Reject messages outside this window — replay-attack defense. */
    private const TIMESTAMP_TOLERANCE = 300;

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

        if (abs(time() - (int) $webhookTimestamp) > self::TIMESTAMP_TOLERANCE) {
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
