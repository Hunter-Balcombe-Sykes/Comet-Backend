<?php

namespace App\Services\Notifications;

use App\Models\Core\Notifications\SupabaseEmailEvent;
use App\Support\EmailHasher;
use Illuminate\Support\Facades\Log;

/**
 * WHK-3: Writes forensic trail rows for every Supabase auth-email webhook outcome.
 *
 * Design constraints:
 * - All methods are fault-tolerant: a trail write must NEVER propagate an
 *   exception to the caller. The mail delivery path is the primary concern;
 *   the trail is best-effort.
 * - Recipient email is stored as a SHA256 HMAC (app.key as pepper) — never plaintext.
 * - raw_payload is deep-stripped of all token and email fields before storage.
 * - No token column exists on the model; WHK-4 (replay) is deferred.
 * - Upsert on webhook_id conflict so late Supabase retries (after the 300s Redis
 *   window) update the existing row rather than throwing a unique-key error.
 */
class SupabaseEmailEventService
{
    /**
     * Record a successful Mail::queue() dispatch for a handled action type.
     *
     * @param  array<string, mixed>  $payload  Raw inbound webhook payload.
     * @param  string  $webhookId  Standard Webhooks idempotency key.
     * @param  string  $actionType  Supabase email_action_type.
     * @param  string|null  $recipientEmail  Plaintext email — hashed before storage.
     * @param  string|null  $requestId  X-Request-Id for Cloud log correlation.
     */
    public function recordQueued(
        array $payload,
        string $webhookId,
        string $actionType,
        ?string $recipientEmail,
        ?string $requestId = null,
    ): void {
        $this->write($webhookId, $actionType, $recipientEmail, $requestId, $payload, [
            'status' => SupabaseEmailEvent::STATUS_QUEUED,
            'queued_at' => now(),
            'failed_at' => null,
            'error' => null,
        ]);
    }

    /**
     * Record a Mail::queue() failure (Throwable caught in the controller).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordFailed(
        array $payload,
        string $webhookId,
        string $actionType,
        ?string $recipientEmail,
        \Throwable $e,
        ?string $requestId = null,
    ): void {
        $this->write($webhookId, $actionType, $recipientEmail, $requestId, $payload, [
            'status' => SupabaseEmailEvent::STATUS_FAILED,
            'failed_at' => now(),
            'queued_at' => null,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Record an unsupported / unhandled action type (resolveMailable returned null).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordUnhandled(
        array $payload,
        string $webhookId,
        string $actionType,
        ?string $recipientEmail,
        ?string $requestId = null,
    ): void {
        $this->write($webhookId, $actionType, $recipientEmail, $requestId, $payload, [
            'status' => SupabaseEmailEvent::STATUS_UNHANDLED,
            'queued_at' => null,
            'failed_at' => null,
            'error' => null,
        ]);
    }

    /**
     * Core upsert — called by all public methods.
     *
     * Wraps in try/catch so any DB error (transient, schema drift, etc.) is logged
     * but never propagates to the webhook response path.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fields  Status-specific columns to set.
     */
    private function write(
        string $webhookId,
        string $actionType,
        ?string $recipientEmail,
        ?string $requestId,
        array $payload,
        array $fields,
    ): void {
        try {
            $data = array_merge([
                'webhook_id' => $webhookId,
                'request_id' => $requestId,
                'action_type' => $actionType,
                'recipient_email_hash' => $this->hashEmail($recipientEmail),
                'raw_payload' => $this->redactPayload($payload),
            ], $fields);

            // Upsert on webhook_id so a Supabase retry arriving after the 300s Redis
            // dedup window updates the existing row rather than violating the UNIQUE
            // constraint. updateOrCreate() uses the model's connection (pgsql).
            SupabaseEmailEvent::updateOrCreate(
                ['webhook_id' => $webhookId],
                $data,
            );
        } catch (\Throwable $e) {
            // Trail write failure must never break the webhook response.
            Log::error('supabase.email_event.trail_write_failed', [
                'webhook_id' => $webhookId,
                'action' => $actionType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hash the recipient email with SHA256 HMAC using app.key as the pepper.
     * Delegates to the shared App\Support\EmailHasher so this WHK-3 trail and the
     * core.email_suppressions lookup + send-time gate all hash identically —
     * without that parity a suppressed address would never match at send time.
     * The digest is unchanged from the pre-refactor inline scheme (regression-tested
     * in EmailHasherTest + SupabaseEmailHookTest).
     */
    private function hashEmail(?string $email): ?string
    {
        return EmailHasher::hash($email);
    }

    /**
     * Return a copy of $payload with every token and plaintext email field stripped.
     *
     * Strips: token, token_hash, token_new, email, new_email, and any key whose
     * name contains "token" (case-insensitive) to guard against future Supabase
     * payload evolution. Non-PII structural fields (email_action_type, site_url,
     * redirect_to, user_metadata shape) are preserved for debugging.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload): array
    {
        // Keys to remove from any nested array, regardless of depth.
        $tokenKeys = ['token', 'token_hash', 'token_new'];
        $emailKeys = ['email', 'new_email'];

        $redact = function (array $node) use (&$redact, $tokenKeys, $emailKeys): array {
            $out = [];
            foreach ($node as $k => $v) {
                // Strip token fields entirely.
                if (in_array(strtolower((string) $k), $tokenKeys, true)) {
                    continue;
                }
                // Strip email fields entirely.
                if (in_array(strtolower((string) $k), $emailKeys, true)) {
                    continue;
                }
                // Also strip any key whose name contains "token" (case-insensitive).
                if (str_contains(strtolower((string) $k), 'token')) {
                    continue;
                }

                $out[$k] = is_array($v) ? $redact($v) : $v;
            }

            return $out;
        };

        return $redact($payload);
    }
}
