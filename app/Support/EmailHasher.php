<?php

namespace App\Support;

/**
 * Single source of truth for hashing recipient email addresses.
 *
 * PII posture: email addresses are NEVER stored or logged in plaintext. Every
 * table/log that references a recipient keys on this HMAC instead. The pepper is
 * config('app.key') so the same address produces a correlatable hash across
 * tables (core.supabase_email_events, core.email_suppressions) and across the
 * send-time suppression gate.
 *
 * This helper is load-bearing: the Resend webhook writer and the MessageSending
 * gate MUST hash identically or a suppressed address would never match at send
 * time. SupabaseEmailEventService::hashEmail() delegates here so WHK-3 rows and
 * suppression rows share one scheme.
 *
 * Normalisation: lowercase + trim. Email addresses are treated case-insensitively
 * here (Supabase normalises to lowercase; providers treat the domain — and in
 * practice the local-part — case-insensitively). Trim guards stray whitespace.
 * Both are no-ops for a validated address, so the digest stays byte-identical to
 * the pre-refactor SupabaseEmailEventService scheme (regression-tested).
 */
class EmailHasher
{
    /**
     * HMAC-SHA256 of the normalised email, peppered with app.key.
     * Returns null for a null/blank address so callers can store NULL rather
     * than a hash of the empty string.
     */
    public static function hash(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalised = strtolower(trim($email));
        if ($normalised === '') {
            return null;
        }

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }
}
