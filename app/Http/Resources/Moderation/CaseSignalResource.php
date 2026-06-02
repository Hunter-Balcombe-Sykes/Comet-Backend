<?php

namespace App\Http\Resources\Moderation;

use App\Http\Resources\ApiResource;

/**
 * Serializes a case signal for staff endpoints.
 *
 * NOTE: reporter_email and reporter_ip_hash are intentionally excluded — these
 * are PII fields that must never appear in API responses. Only non-identifying
 * fields (source, reason codes, timestamps) are exposed here.
 */
class CaseSignalResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id'             => (string) $this->id,
            'signal_source'  => $this->signal_source,
            'reason_code'    => $this->reason_code,
            'reason_details' => $this->reason_details,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
