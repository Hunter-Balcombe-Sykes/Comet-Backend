<?php

namespace App\Services\WebsiteScan;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\Log;

/**
 * Priority chain over every accent-colour source now available for a site:
 * theme-color (scanned from the previous website) -> the site's own logo's
 * dominant colour -> favicon dominant colour -> the top gallery image's
 * dominant colour. Returns the first candidate that passes AccentQuality's
 * gate, else null. A1: logs when nothing qualifies (used to be a silent
 * favicon/theme-color-only no-op with no trail).
 */
class SiteAccentResolver
{
    public function resolve(string $siteId, ?string $themeColor, ?string $faviconColor): ?string
    {
        $candidates = [
            $themeColor,
            $this->logoDominant($siteId),
            $faviconColor,
            $this->galleryDominant($siteId),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $hex = AccentQuality::normalizeHex($candidate);
            if ($hex !== null && AccentQuality::qualifies($hex)) {
                return $hex;
            }
        }

        Log::info('website_accent.no_candidate', ['site_id' => $siteId]);

        return null;
    }

    /** logo_full preferred (the fuller, more representative mark) over logo_square. */
    private function logoDominant(string $siteId): ?string
    {
        return $this->readyDesignDominant($siteId, SiteMedia::PURPOSE_LOGO_FULL)
            ?? $this->readyDesignDominant($siteId, SiteMedia::PURPOSE_LOGO_SQUARE);
    }

    private function readyDesignDominant(string $siteId, string $purpose): ?string
    {
        return SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', $purpose)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->whereNotNull('dominant_color')
            ->value('dominant_color');
    }

    /**
     * Curated tier = the one media pool (site_media POOL_CONTENT: owner
     * uploads + previous-website grabs, the only writers of that byte lane).
     * Instagram imagery stays excluded by construction — platform mirrors
     * land in content.media_assets, never site_media. Item 5 repointed this
     * tier off the retired POOL_GALLERY (2026-09-01).
     */
    private function galleryDominant(string $siteId): ?string
    {
        return SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_CONTENT)
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->whereNotNull('dominant_color')
            ->orderBy('sort_order')
            ->value('dominant_color');
    }
}
