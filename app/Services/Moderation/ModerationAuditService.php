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
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'email', 'reporter_email', 'raw_ip', 'ip', 'reporter_ip',
        'phone', 'password', 'token',
    ];

    public function recordStaffAction(
        PartnaStaff $staff,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'id'             => (string) \Illuminate\Support\Str::uuid(),
            'actor_kind'     => 'staff',
            'actor_staff_id' => $staff->id,
            'action'         => $action,
            'target_type'    => $targetType,
            'target_id'      => $targetId,
            'payload'        => $this->scrubPii($payload),
        ]);
    }

    public function recordSystemAction(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'id'             => (string) \Illuminate\Support\Str::uuid(),
            'actor_kind'     => 'system',
            'actor_staff_id' => null,
            'action'         => $action,
            'target_type'    => $targetType,
            'target_id'      => $targetId,
            'payload'        => $this->scrubPii($payload),
        ]);
    }

    private function scrubPii(array $payload): array
    {
        return array_diff_key($payload, array_flip(self::FORBIDDEN_PAYLOAD_KEYS));
    }
}
