<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use Illuminate\Support\Facades\Storage;

/**
 * The ONE media-asset → servable-URL seam (slice 1a §3.3).
 *
 * Precedence per asset: storage_path (owned bytes, 1b's Instagram mirror
 * feeds this) → site_media_id (best webp rendition out of the working
 * variant pipeline) → source_url (vendor link, passed through — unless it
 * is a Meta CDN link, which is omitted until mirrored) → omitted.
 * An unresolvable asset is ABSENT from the result, never null — the ten
 * ref-only Google assets degrade to an empty gallery, not broken images.
 *
 * Batched by construction: one variant query for the whole page of items.
 * This sits on the public profile hot path behind the 60s payload cache.
 */
class MediaUrlResolver
{
    /** Delivery preference. 'optimized' is the in-page/gallery tier (config/partna.php image_variants). */
    private const TIER_ORDER = ['optimized', 'maximized'];

    /**
     * @param  iterable<object>  $assets  rows carrying id, source_url, storage_path, site_media_id, width, height
     * @return array<string, array{url: string, width: int|null, height: int|null}> keyed by media_assets.id
     */
    public function resolve(iterable $assets): array
    {
        $assets = collect($assets)->unique('id');

        $variantByMedia = $this->bestVariants(
            $assets->filter(fn (object $a) => empty($a->storage_path) && ! empty($a->site_media_id))
                ->pluck('site_media_id')->unique()->values()->all()
        );

        $out = [];
        foreach ($assets as $asset) {
            $resolved = $this->resolveOne($asset, $variantByMedia);
            if ($resolved !== null) {
                $out[(string) $asset->id] = $resolved;
            }
        }

        return $out;
    }

    /** @return array{url: string, width: int|null, height: int|null}|null */
    private function resolveOne(object $asset, array $variantByMedia): ?array
    {
        if (! empty($asset->storage_path)) {
            return [
                'url' => Storage::disk((string) config('partna.media_disk'))->url((string) $asset->storage_path),
                'width' => $asset->width === null ? null : (int) $asset->width,
                'height' => $asset->height === null ? null : (int) $asset->height,
            ];
        }

        $variant = ! empty($asset->site_media_id) ? ($variantByMedia[(string) $asset->site_media_id] ?? null) : null;
        if ($variant !== null && $variant->url !== '') {
            // The variant row's OWN dims: renditions are capped, and the
            // asset row's dims describe the original.
            return [
                'url' => $variant->url,
                'width' => $variant->width === null ? null : (int) $variant->width,
                'height' => $variant->height === null ? null : (int) $variant->height,
            ];
        }

        // A raw Meta CDN link is NOT servable (owner, 2026-09-02): it is
        // signed to expire, and the browser blocks it cross-origin — the
        // gallery showed empty cards until the mirror landed. Omit it; the
        // item appears once its bytes are ours.
        if (! empty($asset->source_url) && ! self::unservableMetaImage((string) $asset->source_url)) {
            return [
                'url' => (string) $asset->source_url,
                'width' => $asset->width === null ? null : (int) $asset->width,
                'height' => $asset->height === null ? null : (int) $asset->height,
            ];
        }

        return null;
    }

    /**
     * One query for the whole batch; best webp rendition per site_media.
     *
     * @param  list<string>  $siteMediaIds
     * @return array<string, MediaVariant>
     */
    private function bestVariants(array $siteMediaIds): array
    {
        if ($siteMediaIds === []) {
            return [];
        }

        $rank = array_flip(self::TIER_ORDER);

        return MediaVariant::query()
            ->whereIn('media_id', $siteMediaIds)
            ->where('artifact_type', 'webp')
            ->get()
            ->groupBy('media_id')
            ->map(fn ($variants) => $variants->sortBy(
                fn (MediaVariant $v) => $rank[$v->variant_key] ?? PHP_INT_MAX
            )->first())
            ->all();
    }

    /**
     * A Meta CDN IMAGE is omitted until mirrored — the browser blocks it
     * cross-origin, so it only ever rendered as a blank card. A Meta VIDEO
     * keeps serving from its (refreshed) source url: the progressive serve
     * that lets a reel play while its mirror drains (2026-08-28) — video
     * elements are not subject to the same block.
     */
    private static function unservableMetaImage(string $url): bool
    {
        if (! InstagramMediaUrl::isMetaCdn($url)) {
            return false;
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('~\.(mp4|m4v|mov|webm|m3u8)$~', $path) !== 1 && ! str_contains($path, '/o1/v/');
    }
}
