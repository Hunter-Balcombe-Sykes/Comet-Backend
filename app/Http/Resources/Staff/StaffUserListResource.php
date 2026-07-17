<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

// Light per-row shape for the staff professional list (index).
// Intentionally leaner than UserStaffResource (the detail/show resource).
//
// PII gate: primary_email and phone are null unless $showPii is true.
// Only admin staff have $showPii=true — enforced by the controller before
// passing the flag here. Shape is byte-identical to the hand-rolled array
// in StaffUserController::index() prior to B17.
class StaffUserListResource extends ApiResource
{
    /**
     * @param  mixed  $resource  User model instance
     * @param  bool  $showPii  True only for admin staff; gates email + phone visibility
     */
    public function __construct($resource, private readonly bool $showPii = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = $this->resource->site;

        return [
            'id' => (string) $this->resource->id,
            'handle' => $this->resource->handle,
            'display_name' => $this->resource->display_name,
            'status' => $this->resource->status,
            'primary_email' => $this->showPii ? $this->resource->primary_email : null,
            'phone' => $this->showPii ? $this->resource->phone : null,
            'created_at' => optional($this->resource->created_at)->toISOString(),
            'updated_at' => optional($this->resource->updated_at)->toISOString(),

            'site' => $site ? [
                'id' => (string) $site->id,
                'subdomain' => $site->subdomain,
                'is_published' => (bool) $site->is_published,
                // architecture_id replaces theme — architectures are code constants, not DB rows.
                'architecture_id' => $site->architecture_id,
            ] : null,
        ];
    }
}
