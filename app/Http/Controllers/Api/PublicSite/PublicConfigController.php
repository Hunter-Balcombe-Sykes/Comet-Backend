<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Services\Site\SocialLinkNormalizer;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated endpoints that expose static frontend-facing config.
 *
 * Used by the professional dashboard to drive UI affordances that depend on
 * backend config (currently: the social platform picker for link blocks). The
 * frontend caches these responses at app load and refreshes occasionally —
 * they're effectively static between deploys.
 *
 * Security:
 *   - All responses go through dedicated services that strip internal-only
 *     fields (regex patterns, host allowlists, etc.) before returning.
 *   - No PII, no auth required. Aggressively cacheable via CDN.
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
        return response()
            ->json([
                'platforms' => $this->normalizer->getPublicRegistry(),
                'categories' => config('partna.link_categories', []),
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /api/public/config/integrations
     *
     * Client-safe third-party keys for the frontend. Each key here must be
     * HTTP-referrer-restricted (or equivalent) in its provider so exposing it
     * publicly is safe — any bearer that isn't coming from an allowlisted
     * domain gets rejected by the provider itself.
     *
     * Current consumers:
     *   - Address autocomplete (Google Places) on the professional dashboard.
     *
     * Response shape: { googleMapsApiKey: string|null }
     */
    public function integrations(): JsonResponse
    {
        return response()
            ->json([
                'googleMapsApiKey' => config('services.google_maps.api_key'),
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
