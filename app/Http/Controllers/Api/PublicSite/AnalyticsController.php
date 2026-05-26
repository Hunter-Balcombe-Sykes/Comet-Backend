<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionSeenRequest;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Records pageview and click analytics events from public mini-sites.
// Read path queries raw site_visits + link_clicks tables directly; cache invalidation
// bumps a per-professional version token so dashboards see fresh totals on next read.
class AnalyticsController extends ApiController
{
    use DetectsClientInfo;
    use HashesClientData;
    use ResolvesSiteFromRequest;

    public function __construct() {}

    public function pageview(PageviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            // 422 when site_id was given but failed the subdomain cross-check (IDOR attempt).
            // 404 when only a subdomain was given and simply wasn't found.
            $statusCode = ! empty($data['site_id']) ? 422 : 404;

            return $this->error('Site not found', $statusCode);
        }

        // 404 not 403: public endpoint — returning 403 would reveal the site exists but is unpublished
        if (! $site->is_published) {
            return $this->error('Site not found', 404);
        }

        // Create pageview record
        $visit = new SiteVisit([
            'occurred_at' => now(),
            'session_id' => $data['session_id'] ?? null,
            'visitor_id' => $data['visitor_id'] ?? null,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
            'referrer' => $data['referrer'] ?? $request->headers->get('referer'),
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'country_code' => $this->detectCountryCode($request),
            'device_type' => $this->detectDeviceType($request->userAgent()),
        ]);
        $visit->user_id = $site->user_id;
        $visit->site_id = $site->id;
        $visit->save();

        try {
            $this->debounceInvalidateAnalytics($site->user_id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Analytics cache invalidation failed on pageview', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        return $this->success([
            'message' => 'Pageview recorded',
            'visit_id' => $visit->id,
        ], 201);
    }

    public function click(ClickRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            // 422 when site_id was given but failed the subdomain cross-check (IDOR attempt).
            // 404 when only a subdomain was given and simply wasn't found.
            $statusCode = ! empty($data['site_id']) ? 422 : 404;

            return $this->error('Site not found', $statusCode);
        }

        // 404 not 403: public endpoint — returning 403 would reveal the site exists but is unpublished
        if (! $site->is_published) {
            return $this->error('Site not found', 404);
        }

        // Discard bot traffic silently — fake 200 avoids fingerprinting the filter
        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Click recorded'], 200);
        }

        // Verify block exists and belongs to this site
        $block = Block::where('id', $data['block_id'])
            ->where('site_id', $site->id)
            ->first();

        if (! $block) {
            return $this->error('Block not found or does not belong to this site', 404);
        }

        $blockGroup = strtolower((string) $block->block_group);
        $blockType = strtolower((string) $block->block_type);
        $trackableSectionTypes = collect(config('partna.section_block_types', [
            'gallery',
            'services',
            'booking',
        ]))
            ->filter(fn ($type) => is_string($type) && trim($type) !== '')
            ->map(fn (string $type) => strtolower(trim($type)))
            ->values()
            ->all();
        $isTrackableLink = $blockGroup === 'links' && $blockType === 'link';
        $isTrackableSection =
            $blockGroup === 'sections'
            && in_array($blockType, $trackableSectionTypes, true);

        if (! $isTrackableLink && ! $isTrackableSection) {
            return $this->error('Block is not trackable for analytics', 422);
        }

        // 404 not 403: state check on a public endpoint — 403 reveals the block exists but is disabled
        if (! $block->is_active) {
            return $this->error('Block not found', 404);
        }

        // Sanitize referrer: discard values that are not valid URLs (e.g., injected strings)
        $rawReferrer = $data['referrer'] ?? $request->headers->get('referer');
        $referrer = ($rawReferrer !== null && filter_var($rawReferrer, FILTER_VALIDATE_URL)) ? $rawReferrer : null;

        // Dedup: return existing click if same visitor/session hit this block within 3 seconds.
        $hasIdentifier = ! empty($data['visitor_id']) || ! empty($data['session_id']);
        $click = null;
        if ($hasIdentifier) {
            $click = LinkClick::where('link_block_id', $block->id)
                ->where(function ($q) use ($data) {
                    if (! empty($data['visitor_id'])) {
                        $q->orWhere('visitor_id', $data['visitor_id']);
                    }
                    if (! empty($data['session_id'])) {
                        $q->orWhere('session_id', $data['session_id']);
                    }
                })
                ->where('occurred_at', '>=', now()->subSeconds(3))
                ->first();
        }

        if (! $click) {
            $click = new LinkClick([
                'occurred_at' => now(),
                'session_id' => $data['session_id'] ?? null,
                'visitor_id' => $data['visitor_id'] ?? null,
                'ip_hash' => $this->hashIp($request->ip()),
                'user_agent' => $request->userAgent(),
                'referrer' => $referrer,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
            ]);
            $click->user_id = $site->user_id;
            $click->site_id = $site->id;
            $click->link_block_id = $block->id;
            $click->save();
        }

        try {
            $this->debounceInvalidateAnalytics($site->user_id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Analytics cache invalidation failed on click', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        return $this->success([
            'message' => $isTrackableSection ? 'Section interaction recorded' : 'Click recorded',
            'click_id' => $click->id,
        ], 201);
    }

    public function sectionSeen(SectionSeenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            $statusCode = ! empty($data['site_id']) ? 422 : 404;

            return $this->error('Site not found', $statusCode);
        }

        if (! $site->is_published) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Section view recorded'], 200);
        }

        // Optional block_id must belong to this site if provided.
        $block = null;
        if (! empty($data['block_id'])) {
            $block = Block::where('id', $data['block_id'])
                ->where('site_id', $site->id)
                ->first();
            if (! $block) {
                return $this->error('Block not found or does not belong to this site', 404);
            }
        }

        // Dedup: a session viewing the same section within 5 minutes is one engagement.
        // Mirrors the click endpoint's 3-sec dedup but with a longer window because scroll
        // back to a section is common.
        $hasIdentifier = ! empty($data['visitor_id']) || ! empty($data['session_id']);
        $view = null;
        if ($hasIdentifier) {
            $view = SectionView::where('site_id', $site->id)
                ->where('section_key', $data['section_key'])
                ->where(function ($q) use ($data): void {
                    if (! empty($data['visitor_id'])) {
                        $q->orWhere('visitor_id', $data['visitor_id']);
                    }
                    if (! empty($data['session_id'])) {
                        $q->orWhere('session_id', $data['session_id']);
                    }
                })
                ->where('occurred_at', '>=', now()->subMinutes(5))
                ->first();
        }

        if (! $view) {
            $rawReferrer = $data['referrer'] ?? $request->headers->get('referer');
            $referrer = ($rawReferrer !== null && filter_var($rawReferrer, FILTER_VALIDATE_URL)) ? $rawReferrer : null;

            $view = new SectionView([
                'section_key' => $data['section_key'],
                'block_id' => $block?->id,
                'occurred_at' => now(),
                'session_id' => $data['session_id'] ?? null,
                'visitor_id' => $data['visitor_id'] ?? null,
                'ip_hash' => $this->hashIp($request->ip()),
                'user_agent' => $request->userAgent(),
                'referrer' => $referrer,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'country_code' => $this->detectCountryCode($request),
                'device_type' => $this->detectDeviceType($request->userAgent()),
            ]);
            $view->user_id = $site->user_id;
            $view->site_id = $site->id;
            $view->save();
        }

        try {
            $this->debounceInvalidateAnalytics($site->user_id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Analytics cache invalidation failed on section view', [
                'site_id' => $site->id,
                'section_key' => $data['section_key'],
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success([
            'message' => 'Section view recorded',
            'view_id' => $view->id,
        ], 201);
    }

    /**
     * Invalidate analytics cache at most once per 30-second window per professional.
     * High-volume ingest (pageviews, clicks, section views) would otherwise bust SWR
     * on every event.
     */
    private function debounceInvalidateAnalytics(string $userId): void
    {
        if (Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)) {
            Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
        }
    }
}
