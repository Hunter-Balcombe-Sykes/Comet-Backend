<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// Wire-format gate for gallery-pool SiteMedia rows (#P2-34).
// Replaces the per-row inline ->map(...) that ProfessionalGalleryController::index
// previously used. The explicit allowlist here keeps future SiteMedia columns
// (e.g. moderation flags, exif blobs) from auto-shipping to the dashboard.
class GalleryImageResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'pool' => $this->pool,
            'alt_text' => $this->alt_text,
            'caption' => $this->caption,
            'sort_order' => $this->sort_order,
            // variantUrls() loads the mediaVariants relation if it isn't already
            // eager-loaded; the controller eager-loads it to avoid the N+1.
            'variants' => $this->variantUrls(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
