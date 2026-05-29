<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a moderation decision for staff review endpoints.
 * Exposes the decision type, reason, actor (staff id or system flag),
 * and audit metadata. No reporter PII is present on this model.
 */
class DecisionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                     => $this->id,
            'decision_type'          => $this->decision_type,
            'reason'                 => $this->reason,
            'decided_by_staff_id'    => $this->decided_by_staff_id,
            'decided_by_system'      => $this->decided_by_system,
            'auto_actioned'          => $this->auto_actioned,
            'supersedes_decision_id' => $this->supersedes_decision_id,
            'decided_at'             => $this->decided_at?->toIso8601String(),
        ];
    }
}
