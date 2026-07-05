<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;

// Wire format for one content-library upload (a pool='content' SiteMedia row).
// Emits a single resolved `url` (the optimized WebP variant, maximized as
// fallback) — the same precedence the sitepage/gallery read path uses — plus
// the accessibility/caption text and processing state so the dashboard can
// show a spinner until variants are ready.
class ContentLibraryUploadResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteMedia $media */
        $media = $this->resource;

        $variants = $media->variantUrls();
        $url = $variants['optimized'] ?? $variants['maximized'] ?? null;

        return [
            'id' => (string) $media->id,
            'url' => is_string($url) && $url !== '' ? $url : null,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'media_type' => $media->media_type,
            'processing_state' => $media->processing_state,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
