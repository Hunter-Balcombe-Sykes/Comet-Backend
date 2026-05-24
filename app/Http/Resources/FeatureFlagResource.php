<?php

namespace App\Http\Resources;

class FeatureFlagResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'key' => $this->key,
            'description' => $this->description,
            'default_enabled' => (bool) $this->default_enabled,
            'rollout_percent' => (int) $this->rollout_percent,
            'override_count' => $this->whenCounted('overrides'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
