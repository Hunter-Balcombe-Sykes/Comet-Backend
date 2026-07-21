<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Services\Site\SocialLinkNormalizer;
use Illuminate\Http\JsonResponse;

/**
 * Frontend-facing static config endpoints, grouped by feature area rather than
 * by auth requirement — some are public, some (see below) require auth.
 *
 * Used by the professional dashboard to drive UI affordances that depend on
 * backend config (social platform picker, third-party keys). Responses are
 * effectively static between deploys.
 *
 * Security:
 *   - All responses go through dedicated services that strip internal-only
 *     fields (regex patterns, host allowlists, etc.) before returning.
 *   - socialPlatforms(): no PII, no auth required, aggressively cacheable via CDN.
 *   - integrations(): requires `user.api` auth (public-surface/SEC-1) — see its
 *     own docblock.
 */
class PublicConfigController extends ApiController
{
    public function __construct(
        private readonly SocialLinkNormalizer $normalizer
    ) {}

    /**
     * GET /api/public/config/social-platforms
     *
     * Returns the list of supported platforms with frontend-facing metadata
     * (display name, icon key, placeholder, category) plus the canonical
     * `categories` enum. Used by the professional dashboard to render the
     * platform picker grouped by category. See docs/social-links.md.
     */
    public function socialPlatforms(): JsonResponse
    {
        return $this->successCached([
            'platforms' => $this->normalizer->getPublicRegistry(),
            'categories' => config('partna.link_categories', []),
        ]);
    }

    /**
     * GET /api/config/integrations (authenticated — see routes/api/user.php)
     *
     * Client-safe third-party keys for the dashboard.
     *
     * public-surface/SEC-1: moved behind `user.api` auth from the former
     * unauthenticated `/public/config/integrations` — the only named consumer
     * is this logged-in dashboard, so there's no product reason to serve it
     * pre-auth. Uses plain success() (not successCached()) since that helper
     * is for unauthenticated, CDN-cacheable routes only; AddPublicCacheHeaders
     * would force no-store on this authenticated route anyway.
     *
     * Current consumers:
     *   - Address autocomplete (Google Places) on the professional dashboard.
     *
     * Response shape: { googleMapsApiKey: string|null }
     */
    public function integrations(): JsonResponse
    {
        return $this->success([
            'googleMapsApiKey' => config('services.google_maps.api_key'),
        ]);
    }
}
