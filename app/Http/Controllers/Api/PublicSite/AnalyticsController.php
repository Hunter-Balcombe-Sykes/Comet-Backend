<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionSeenRequest;
use App\Models\Core\Site\Site;
use App\Services\Analytics\AnalyticsDedupGuard;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Records pageview / click / section-seen analytics events from public mini-sites.
//
// Write path is fully decoupled: the controller validates (in-memory), resolves the
// site, mints the row PK + stamps request-time fields, does a Redis-only dedup, and
// hands the event to the AnalyticsIngestor. No Postgres WRITE and no read-for-write on
// the hot path; authoritative block validation lives in the writer (worker side).
class AnalyticsController extends ApiController
{
    use DetectsClientInfo;
    use HashesClientData;
    use ResolvesSiteFromRequest;

    public function __construct(
        private readonly AnalyticsIngestor $ingestor,
        private readonly AnalyticsDedupGuard $dedup,
    ) {}

    public function pageview(PageviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        // NOTE: pageview intentionally has NO bot filter and NO dedup (preserved). A bot
        // UA still records a pageview today; changing that is a separate metrics decision.
        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_PAGEVIEW,
            request: $request,
            site: $site,
            data: $data,
            // pageview stores the RAW referrer (no URL sanitisation — preserved).
            referrer: $data['referrer'] ?? $request->headers->get('referer'),
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Pageview recorded', 'visit_id' => $event->id], 201);
    }

    public function click(ClickRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            // Bot path: 200 with a message and NO id (preserved). Fake-success avoids
            // fingerprinting the filter.
            return $this->success(['message' => 'Click recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (block, strongest identifier) for 3s. Returns the original id on a
        // duplicate so the response is byte-identical to today's "return existing id".
        $identifier = $data['visitor_id'] ?? $data['session_id'] ?? null;
        if ($identifier !== null) {
            $claim = $this->dedup->claim("analytics:dedup:click:{$data['block_id']}:{$identifier}", $id, 3);
            if (! $claim['novel']) {
                return $this->success(['message' => 'Click recorded', 'click_id' => $claim['id']], 201);
            }
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_CLICK,
            request: $request,
            site: $site,
            data: $data,
            // click sanitises the referrer (preserved SEC behaviour).
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            blockId: $data['block_id'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Click recorded', 'click_id' => $event->id], 201);
    }

    public function sectionSeen(SectionSeenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Section view recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (site, section_key, strongest identifier) for 5min.
        $identifier = $data['visitor_id'] ?? $data['session_id'] ?? null;
        if ($identifier !== null) {
            $key = "analytics:dedup:section:{$site->id}:{$data['section_key']}:{$identifier}";
            $claim = $this->dedup->claim($key, $id, 300);
            if (! $claim['novel']) {
                return $this->success(['message' => 'Section view recorded', 'view_id' => $claim['id']], 201);
            }
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_SECTION_VIEW,
            request: $request,
            site: $site,
            data: $data,
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            blockId: $data['block_id'] ?? null,
            sectionKey: $data['section_key'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Section view recorded', 'view_id' => $event->id], 201);
    }

    /**
     * Resolve + publication-gate the site. On failure, sets $error to the right JSON
     * response (422 IDOR when site_id was supplied but cross-check failed; otherwise
     * 404 — never 403, no existence leak) and returns null.
     */
    private function resolvePublishedSite(array $data, ?JsonResponse &$error): ?Site
    {
        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            $status = ! empty($data['site_id']) ? 422 : 404;
            $error = $this->error('Site not found', $status);

            return null;
        }

        if (! $site->is_published) {
            $error = $this->error('Site not found', 404);

            return null;
        }

        $error = null;

        return $site;
    }

    // Front-loads every request-derived field into the DTO (occurred_at, geo, device,
    // ip hash, UA). The worker has no request object, so anything not captured here is
    // lost. occurred_at is request-time, ISO-8601.
    private function buildEvent(
        string $type,
        Request $request,
        Site $site,
        array $data,
        ?string $referrer,
        ?string $id = null,
        ?string $blockId = null,
        ?string $sectionKey = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            id: $id ?? (string) Str::orderedUuid(),
            type: $type,
            occurredAt: now()->toISOString(),
            userId: $site->user_id,
            siteId: $site->id,
            sessionId: $data['session_id'] ?? null,
            visitorId: $data['visitor_id'] ?? null,
            ipHash: $this->hashIp($request->ip()),
            userAgent: $request->userAgent(),
            referrer: $referrer,
            utmSource: $data['utm_source'] ?? null,
            utmMedium: $data['utm_medium'] ?? null,
            utmCampaign: $data['utm_campaign'] ?? null,
            countryCode: $this->detectCountryCode($request),
            deviceType: $this->detectDeviceType($request->userAgent()),
            blockId: $blockId,
            sectionKey: $sectionKey,
        );
    }

    // Mirrors the old inline rule: keep only values that are valid URLs.
    private function sanitizeReferrer(?string $raw): ?string
    {
        return ($raw !== null && filter_var($raw, FILTER_VALIDATE_URL)) ? $raw : null;
    }

    /**
     * Real-user monitoring beacon — unchanged. Logs first-paint / load timings to a
     * structured channel for offline percentile analysis. No DB writes.
     */
    public function rum(Request $request): JsonResponse
    {
        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'ok'], 200);
        }

        $payload = $request->json()->all();
        $handle = isset($payload['handle']) ? (string) $payload['handle'] : null;
        if (! $handle || ! preg_match('/^[a-z0-9-]{1,63}$/i', $handle)) {
            return $this->success(['message' => 'ok'], 200);
        }

        try {
            \Illuminate\Support\Facades\Log::info('rum', [
                'handle' => strtolower($handle),
                'ttfb_ms' => isset($payload['ttfb']) ? (int) $payload['ttfb'] : null,
                'dom_ms' => isset($payload['dom']) ? (int) $payload['dom'] : null,
                'load_ms' => isset($payload['load']) ? (int) $payload['load'] : null,
                'fcp_ms' => isset($payload['fcp']) ? (int) $payload['fcp'] : null,
                'lkg' => isset($payload['lkg']) ? (bool) $payload['lkg'] : false,
                'ua' => substr((string) $request->userAgent(), 0, 256),
                'country' => $request->header('cf-ipcountry'),
            ]);
        } catch (\Throwable $e) {
            // RUM is best-effort; never bubble logging errors back to the visitor.
        }

        return $this->success(['message' => 'ok'], 200);
    }
}
