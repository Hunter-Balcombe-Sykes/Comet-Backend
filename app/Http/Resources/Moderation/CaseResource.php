<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List-view serialization of a moderation case — no nested relations.
 * PII fields (reporter_email, reporter_ip_hash) live on case_signals, not
 * here, so this resource is safe to return to any staff endpoint.
 */
class CaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'case_type'                => $this->case_type,
            'reportable_type'          => $this->reportable_type,
            'reportable_id'            => $this->reportable_id,
            'reportable_owner_user_id' => $this->reportable_owner_user_id,
            'severity'                 => $this->severity,
            'status'                   => $this->status,
            'signal_count'             => $this->signal_count,
            'priority'                 => $this->priority,
            'auto_actioned'            => $this->auto_actioned,
            'triaged_at'               => $this->triaged_at?->toIso8601String(),
            'resolved_at'              => $this->resolved_at?->toIso8601String(),
            'created_at'               => $this->created_at?->toIso8601String(),
        ];
    }
}
