<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// V2: API resource for site.blocks rows where block_group='sections'.
// Replaces the private serializeSection() that called $section->toArray() —
// the explicit allowlist here is the audit fix (P2-35).
//
// Visibility tuple (from SectionVisibilityService::batchCheck or checkVisibilityRequirements)
// can be supplied via the constructor — index() passes it per-section; upsert/remove
// pass null (the post-mutation response doesn't need to expose the gate).
class SectionBlockResource extends ApiResource
{
    /**
     * @param  array{0: bool, 1: ?string}|null  $visibility  [canPublish, requirementReason]
     */
    public function __construct($resource, private readonly ?array $visibility = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isLive = (bool) ($this->is_active ?? false);

        $payload = [
            'id' => (string) $this->id,
            'professional_id' => $this->professional_id,
            'site_id' => $this->site_id,
            'block_type' => $this->block_type,
            'block_group' => $this->block_group,
            'title' => $this->title,
            // Sections don't use url/icon_key today — but the Block table shares
            // those columns with link blocks. Keep them in the response shape
            // so consumers with null-checks don't break.
            'url' => $this->url,
            'icon_key' => $this->icon_key,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_enabled' => $this->is_enabled,
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'publication_state' => $isLive ? 'live' : 'draft',
            'is_live' => $isLive,
        ];

        if ($this->visibility !== null) {
            [$canPublish, $reason] = $this->visibility;
            $payload['can_publish'] = $canPublish;
            $payload['requirement_reason'] = $reason;
        }

        return $payload;
    }
}
