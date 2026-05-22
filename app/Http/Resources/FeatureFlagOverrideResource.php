<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FeatureFlagOverrideResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'flag_key' => $this->flag_key,
            'professional_id' => $this->professional_id,
            'enabled' => (bool) $this->enabled,
            'reason' => $this->reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
