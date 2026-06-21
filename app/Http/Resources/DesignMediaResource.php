<?php

namespace App\Http\Resources;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;

// API resource for design-pool singleton images (logos, cover images). Used by
// UserDesignMediaController for both GET /design-media and the upload response.
//
// Fields: id (string), purpose, processing_state, processing (bool),
// url (optimized variant URL or null), svg_url (vectorized logo URL or null),
// variants (map of all WebP variant keys).
class DesignMediaResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteMedia $media */
        $media = $this->resource;

        $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;
        $variants = $isReady ? $media->variantUrls() : [];

        return [
            'id' => (string) $media->id,
            'purpose' => $media->purpose,
            'processing_state' => $media->processing_state,
            'processing' => in_array($media->processing_state, [
                SiteMedia::PROCESSING_STATE_PENDING,
                SiteMedia::PROCESSING_STATE_PROCESSING,
            ], true),
            'url' => $variants['optimized'] ?? $variants['original'] ?? null,
            'svg_url' => $isReady ? $media->svgVariantUrl() : null,
            'variants' => $variants,
        ];
    }
}
