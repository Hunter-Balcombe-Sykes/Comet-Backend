<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;

// OV-A: staff-facing segment payload. resolved_count / manual_member_count are
// attached by the controller (additional attributes), not columns.
class UserSegmentResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'filters' => $this->filters ?? [],
            'manual_member_count' => $this->whenCounted('members'),
            'resolved_count' => $this->when(isset($this->resolved_count), fn () => (int) $this->resolved_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
