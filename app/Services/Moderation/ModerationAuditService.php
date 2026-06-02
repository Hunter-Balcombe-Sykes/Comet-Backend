<?php

namespace App\Services\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;

/**
 * Single write path for the audit.moderation_events table.
 * Any staff action against a moderation entity must call recordStaffAction().
 * Auto-actions call recordSystemAction().
 *
 * Strips PII keys from payload before persisting. Reporters' raw email/IP must
 * never enter the audit trail — they live on case_signals where erasure is
 * supported via moderation:redact-reporter-pii.
 */
class ModerationAuditService
{
    /**
     * Canonical lowercase PII key denylist. Includes common synonyms and
     * alternate casings so callers can't accidentally leak PII via non-standard
     * key names. scrubPii() normalises all keys to lowercase before comparison,
     * and recurses into nested arrays — there is no safe depth limit.
     */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        // Email addresses
        'email', 'reporter_email', 'email_address', 'user_email',
        'contact_email', 'from_email', 'to_email',
        // IP addresses
        'ip', 'raw_ip', 'reporter_ip', 'ip_address', 'client_ip',
        'remote_ip', 'source_ip', 'x_forwarded_for',
        // Phone numbers
        'phone', 'phone_number', 'mobile', 'mobile_number',
        // Credentials
        'password', 'token', 'secret', 'api_key', 'access_token',
        'refresh_token', 'auth_token', 'session_token',
    ];

    public function recordStaffAction(
        PartnaStaff $staff,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'actor_kind' => 'staff',
            'actor_staff_id' => $staff->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $this->scrubPii($payload),
        ]);
    }

    public function recordSystemAction(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'actor_kind' => 'system',
            'actor_staff_id' => null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $this->scrubPii($payload),
        ]);
    }

    /**
     * Recursively strip forbidden PII keys from a payload array.
     * Keys are normalised to lowercase before comparison so callers can't
     * bypass the filter with `Email`, `EMAIL`, or other mixed-case variants.
     */
    private function scrubPii(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_PAYLOAD_KEYS, strict: true)) {
                continue; // drop the key entirely
            }
            $result[$key] = is_array($value) ? $this->scrubPii($value) : $value;
        }

        return $result;
    }
}
