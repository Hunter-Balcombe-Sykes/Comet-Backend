<?php

namespace App\Http\Resources;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;

// API resource for content-usage media items (owner uploads).
//
// Pass `include_variants: true` via withAdditional() (or the static make helper)
// to include resolved variant/stream maps:
//
//   SiteMediaResource::make($media)->additional(['include_variants' => true])
//
// The $includeVariants flag matches the helper's parameter exactly — images get
// variantUrls(); videos get the mp4/poster map when ready, else empty arrays.
class SiteMediaResource extends ApiResource
{
    /**
     * @param  bool  $includeVariants  When true, resolved variant URLs are appended.
     */
    public function __construct($resource, private readonly bool $includeVariants = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteMedia $media */
        $media = $this->resource;

        $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;
        $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;
        $isProcessing = $media->processing_state === SiteMedia::PROCESSING_STATE_PENDING
            || $media->processing_state === SiteMedia::PROCESSING_STATE_PROCESSING;

        $payload = [
            'id' => (string) $media->id,
            'usage' => $media->usage,
            // Legacy alias of `usage` (rename 2026-09-04). Ships beside the new
            // key for one deploy so the dashboard can migrate on its own clock.
            // Load-bearing until then: the DEPLOYED dashboard's mapPoolImages
            // drops any image row whose field is missing (returns null), so
            // removing this early empties the grid silently rather than
            // erroring. Drop it once
            // PartnaAu/partna-frontend#refactor/site-media-usage-rename deploys.
            'pool' => $media->usage,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'sort_order' => $media->sort_order,
            'media_type' => $media->media_type,
            'processing_state' => $media->processing_state,
            'processing' => $isProcessing, // backward-compat boolean
            'processing_error' => $media->processing_error,
            'created_at' => $media->created_at?->toIso8601String(),
            'updated_at' => $media->updated_at?->toIso8601String(),
        ];

        if ($isVideo) {
            $payload['duration_ms'] = $media->duration_ms;
            $payload['poster'] = null;
        }

        if (! $this->includeVariants) {
            return $payload;
        }

        if ($isVideo) {
            if ($isReady) {
                $mvList = $media->relationLoaded('mediaVariants')
                    ? $media->mediaVariants
                    : $media->mediaVariants()->get();

                $variants = [];
                $poster = null;

                // Two MP4 tiers (optimized 720p / maximized 1080p) + poster.
                // HLS was removed (2026-05-29) — the dashboard plays the mp4
                // directly, so we no longer emit a streams map.
                foreach ($mvList as $mv) {
                    if ($mv->artifact_type === 'mp4') {
                        $variants[$mv->variant_key] = $mv->url;
                    } elseif ($mv->artifact_type === 'poster') {
                        $poster = $mv->url;
                    }
                }

                $payload['variants'] = $variants;
                $payload['poster'] = $poster;
            } else {
                $payload['variants'] = [];
                $payload['poster'] = null;
            }
        } else {
            $payload['variants'] = $isReady ? $media->variantUrls() : [];
        }

        return $payload;
    }
}
