<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\Service;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Carbon;

/**
 * Freshness boost — an ADDITIVE, decaying score term for newly-added content,
 * so new pages/items surface before they have earned engagement (cold start),
 * then fade back to pure engagement ranking over ~a month.
 *
 *   boost = W · 2^(-age_days / HALF_LIFE_DAYS)
 *
 * age comes from the owning row's created_at — the only grains with a STABLE
 * per-entity created_at (recon 2026-07-10):
 *   - page      : newest ACTIVE platform connection mapping to the page
 *                 (PLATFORM_TO_PAGE), plus native site.services rows → 'services'.
 *                 Disconnect→reconnect resets created_at, which reads as
 *                 "re-added = fresh again" — acceptable semantics.
 *   - link_item : each custom link is its own connection row; payload.url is
 *                 byte-stable (enrichment never rewrites it) and equals the
 *                 item's content_key.
 * NOT freshness-eligible (no stable per-item created_at — documented, not
 * half-built): shop_product / listen_item / watch_item (items live inside one
 * connection's payload — a uniform boost can't change intra-page order) and
 * Fresha services (payload.selection.services[] is rewritten wholesale on sync).
 *
 * Consumed by ComputeContentPopularityScores (score = base·recency + boost;
 * zero-signal keys are seeded so brand-new content ranks at all) and surfaced
 * by DevInsightsController so the boost is visible in the score breakdown.
 */
class ContentFreshness
{
    public const HALF_LIFE_DAYS = 14.0;

    // A brand-new PAGE starts at +15 — visible against typical page scores
    // (~90-420 on an engaged site; dominant on a quiet one, which is the point).
    public const W_PAGE = 15.0;

    // A brand-new ITEM starts at +3 — exactly one click's head start.
    public const W_ITEM = 3.0;

    // Below this the boost is noise; skipping keeps zero-signal seeding bounded
    // (a connection stops seeding score rows ~4 months after it was added).
    private const MIN_BOOST = 0.05;

    /**
     * Freshness boosts for one site, keyed like the scoring job's content grains.
     *
     * @return array{page: array<string, float>, link_item: array<string, float>}
     */
    public function boostsForSite(Site $site): array
    {
        $now = now();

        // Newest created_at per page from active connections (SoftDeletes global
        // scope excludes trashed; scopeActive excludes is_active=false).
        $pageNewest = [];
        $linkItems = [];

        IntegrationConnection::query()
            ->where('user_id', $site->user_id)
            ->active()
            ->get(['platform', 'resource_kind', 'payload', 'created_at'])
            ->each(function (IntegrationConnection $conn) use (&$pageNewest, &$linkItems): void {
                if ($conn->created_at === null) {
                    return;
                }

                $page = SitepageDataResolverService::PLATFORM_TO_PAGE[$conn->platform] ?? null;
                if ($page !== null) {
                    if (! isset($pageNewest[$page]) || $conn->created_at->gt($pageNewest[$page])) {
                        $pageNewest[$page] = $conn->created_at;
                    }
                }

                // Custom link rows: content_key = payload.url (byte-stable).
                if ($conn->platform === 'custom') {
                    $url = is_array($conn->payload) ? ($conn->payload['url'] ?? null) : null;
                    if (is_string($url) && $url !== '') {
                        $linkItems[$url] = $conn->created_at;
                    }
                }
            });

        // Native services are their own rows with a stable created_at; a newly
        // added service freshens the Book page. (Fresha-synced services live in
        // a wholesale-rewritten payload array — excluded, see class docblock.)
        $newestService = Service::query()
            ->where('user_id', $site->user_id)
            ->where('is_active', true)
            ->max('created_at');
        if ($newestService !== null) {
            $serviceAt = Carbon::parse($newestService);
            if (! isset($pageNewest['services']) || $serviceAt->gt($pageNewest['services'])) {
                $pageNewest['services'] = $serviceAt;
            }
        }

        $pages = [];
        foreach ($pageNewest as $page => $createdAt) {
            $boost = $this->boost(self::W_PAGE, $createdAt, $now);
            if ($boost !== null) {
                $pages[$page] = $boost;
            }
        }

        $links = [];
        foreach ($linkItems as $url => $createdAt) {
            $boost = $this->boost(self::W_ITEM, $createdAt, $now);
            if ($boost !== null) {
                $links[$url] = $boost;
            }
        }

        return ['page' => $pages, 'link_item' => $links];
    }

    /** Decayed boost, or null once it has faded below MIN_BOOST. */
    private function boost(float $weight, Carbon $createdAt, Carbon $now): ?float
    {
        $ageDays = max(0.0, $now->getTimestamp() - $createdAt->getTimestamp()) / 86400.0;
        $boost = $weight * 2 ** (-$ageDays / self::HALF_LIFE_DAYS);

        return $boost >= self::MIN_BOOST ? $boost : null;
    }
}
