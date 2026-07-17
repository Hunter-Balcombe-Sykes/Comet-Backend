<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;

// OV-A: one availability rule row (segment_id null = the global row).
class FeatureAvailabilityRuleResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'feature_key' => $this->feature_key,
            'mode' => $this->mode,
            'segment_id' => $this->segment_id,
            'segment_name' => $this->whenLoaded('segment', fn () => $this->segment?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
