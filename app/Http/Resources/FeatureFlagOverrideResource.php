<?php

namespace App\Http\Resources;

class FeatureFlagOverrideResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'flag_key' => $this->flag_key,
            'user_id' => $this->user_id,
            'enabled' => (bool) $this->enabled,
            'reason' => $this->reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
