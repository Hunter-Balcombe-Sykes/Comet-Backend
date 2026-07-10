<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ItemSeenRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PingRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionDwellRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionSeenRequest;
use App\Models\Core\Site\Site;
use App\Services\Analytics\AnalyticsDedupGuard;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\AnalyticsEventSanitizer;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        if (! $this->originAllowed($request, $site, $data)) {
            return $this->error('Site not found', 404);
        }

        // NOTE: pageview intentionally has NO bot filter and NO dedup (preserved). A bot
        // UA still records a pageview today; changing that is a separate metrics decision.
        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_PAGEVIEW,
            request: $request,
            site: $site,
            data: $data,
            // pageview still skips the bot/URL-shape filter (sanitizeReferrer(), preserved) —
            // but buildEvent() now runs every referrer through AnalyticsEventSanitizer, so a
            // malformed value ends up null here exactly as it already did at write time (JOB-1).
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

        if (! $this->originAllowed($request, $site, $data)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            // Bot path: 200 with a message and NO id (preserved). Fake-success avoids
            // fingerprinting the filter.
            return $this->success(['message' => 'Click recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (target, strongest identifier) for a short window (config-driven,
        // CFG-1). Legacy block clicks keep the original key shape; v2 url-clicks scope
        // by site + destination hash. Returns the original id on a duplicate so the
        // response is byte-identical to today's "return existing id".
        $identifier = $this->dedupIdentifier($data, $request);
        $blockId = $data['block_id'] ?? null;
        // SEM-1: lowercase platform so the dedup target matches what buildEvent() stores —
        // otherwise "Instagram" then "instagram" mint two keys for the same destination.
        $target = $blockId ?? $site->id.':'.md5(($data['url'] ?? '').'|'.strtolower($data['platform'] ?? ''));
        $claim = $this->dedup->claim(
            CacheKeyGenerator::analyticsClickDedup($target, $identifier),
            $id,
            (int) config('partna.analytics.click_dedup_ttl_seconds', 3),
        );
        if (! $claim['novel']) {
            return $this->success(['message' => 'Click recorded', 'click_id' => $claim['id']], 201);
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_CLICK,
            request: $request,
            site: $site,
            data: $data,
            // click sanitises the referrer (preserved SEC behaviour).
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            blockId: $blockId,
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

        if (! $this->originAllowed($request, $site, $data)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Section view recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (site, section_key, strongest identifier) for a config-driven window (CFG-1).
        $identifier = $this->dedupIdentifier($data, $request);
        $key = CacheKeyGenerator::analyticsSectionDedup($site->id, $data['section_key'], $identifier);
        $claim = $this->dedup->claim($key, $id, (int) config('partna.analytics.section_dedup_ttl_seconds', 300));
        if (! $claim['novel']) {
            return $this->success(['message' => 'Section view recorded', 'view_id' => $claim['id']], 201);
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
     * Per-page dwell (V1 signal). The client reports a section's CUMULATIVE
     * visible-time on panel-leave; the writer GREATEST-merges it onto the matching
     * section_views row. No dedup guard — the merge is idempotent under retries
     * and out-of-order delivery (ping's pattern). Always 200 (no row id minted;
     * bot and non-bot responses are indistinguishable by design).
     */
    public function sectionDwell(SectionDwellRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if (! $this->originAllowed($request, $site, $data)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'ok'], 200);
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_SECTION_DWELL,
            request: $request,
            site: $site,
            data: $data,
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            sectionKey: $data['section_key'],
            durationMs: (int) $data['duration_ms'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'ok'], 200);
    }

    /**
     * Item-impression ingest (analytics v2, popularity scoring). Mirrors
     * sectionSeen exactly — resolve + publication-gate + origin-bind + bot-drop +
     * 5min Redis dedup — but the dedup key + written grain are per item
     * (item_type:item_id) instead of per section_key. Writes analytics.item_views.
     */
    public function itemSeen(ItemSeenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if (! $this->originAllowed($request, $site, $data)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Item view recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (site, item_type, item_id, strongest identifier) for a config-driven
        // window (CFG-1) — same default + fail-open semantics as section-seen.
        $identifier = $this->dedupIdentifier($data, $request);
        $key = CacheKeyGenerator::analyticsItemDedup($site->id, $data['item_type'], $data['item_id'], $identifier);
        $claim = $this->dedup->claim($key, $id, (int) config('partna.analytics.item_dedup_ttl_seconds', 300));
        if (! $claim['novel']) {
            return $this->success(['message' => 'Item view recorded', 'view_id' => $claim['id']], 201);
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_ITEM_VIEW,
            request: $request,
            site: $site,
            data: $data,
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            sectionKey: $data['section_key'] ?? null,
            itemType: $data['item_type'],
            itemId: $data['item_id'],
            itemTitle: $data['item_title'] ?? null,
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Item view recorded', 'view_id' => $event->id], 201);
    }

    /**
     * Session heartbeat (analytics v2). The tracker reports cumulative visible-time
     * every ~25s; the writer upserts analytics.site_sessions with GREATEST() so
     * there is no dedup here — retries and out-of-order delivery are harmless.
     */
    public function ping(PingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if (! $this->originAllowed($request, $site, $data)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'ok'], 200);
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_SESSION_PING,
            request: $request,
            site: $site,
            data: $data,
            // First ping carries document.referrer; raw is fine (session row, not URL-joined).
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            durationSeconds: (int) $data['seconds'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'ok'], 200);
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

    /**
     * Bind the ingest event to the site's canonical origin(s).
     *
     * Browsers cannot forge the Origin header from JS, so this closes the dominant
     * cross-tenant injection vector: an attacker who knows a victim's site_id UUID
     * (exposed in public page payloads) cannot POST fabricated events because their
     * page will carry the WRONG origin.
     *
     * Allowed-host set for a resolved $site:
     *   1. {site->subdomain}.{partna.public_domain}  — always present
     *   2. site->custom_domain                        — included only when status = 'active'
     *
     * Origin-header precedence:
     *   Origin header → Referer header host → absent
     *
     * When Origin AND Referer are both absent: allowed only if the request supplied
     * BOTH site_id AND subdomain and they passed the cross-check (i.e. subdomain is
     * already in $data, meaning resolveSiteFromData() validated the pair). This covers
     * server-side / synthetic callers that never emit an Origin while still closing the
     * site_id-only hole.
     *
     * @param  array<string, mixed>  $data  validated request data (post-prepareForValidation)
     */
    private function originAllowed(Request $request, Site $site, array $data): bool
    {
        // Build the allowed-host set for this site.
        $publicDomain = config('partna.public_domain');
        $allowed = [strtolower($site->subdomain.'.'.$publicDomain)];

        // Include the active custom domain if the site has one verified.
        if (
            ! empty($site->custom_domain) &&
            $site->custom_domain_status === 'active'
        ) {
            $allowed[] = strtolower($site->custom_domain);
        }

        // Extract the request's origin host from Origin header, then Referer.
        $originHost = $this->parseOriginHost($request);

        if ($originHost === null) {
            // No origin signal — allow only when both site_id and subdomain were
            // provided and survived the resolver cross-check (the resolver already
            // confirmed they match, so subdomain will be in $data here).
            return ! empty($data['site_id']) && ! empty($data['subdomain']);
        }

        return in_array($originHost, $allowed, true);
    }

    /**
     * Extract a lowercase host from the Origin header, falling back to Referer.
     * Returns null when neither header is present or parseable.
     */
    private function parseOriginHost(Request $request): ?string
    {
        // Origin is the primary signal — browsers always send it for cross-origin POSTs.
        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== '') {
            // Origin may be 'null' (sandboxed iframes) — treat as absent.
            if ($origin === 'null') {
                $origin = null;
            } else {
                $host = parse_url($origin, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    return strtolower($host);
                }
            }
        }

        // Fall back to Referer host (less reliable but acceptable as a secondary signal).
        $referer = $request->headers->get('Referer');
        if ($referer !== null && $referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return null;
    }

    /**
     * Strongest identifier for a dedup claim: visitor_id (persistent) > session_id >
     * an IP-hash fallback, so a beacon that sends neither doesn't skip dedup entirely
     * (WHK-1). "ip:" namespaces the fallback — visitor/session values are UUIDs and
     * never contain a colon, so this can never collide with a real identifier.
     */
    private function dedupIdentifier(array $data, Request $request): string
    {
        return $data['visitor_id'] ?? $data['session_id'] ?? 'ip:'.$this->hashIp($request->ip());
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
        ?int $durationSeconds = null,
        ?string $itemType = null,
        ?string $itemId = null,
        ?string $itemTitle = null,
        ?int $durationMs = null,
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
            // JOB-1: sanitise here — the single choke point every beacon type funnels
            // through — so a raw UTM-embedded-PII referrer never reaches the Redis queue
            // payload. Idempotent with PostgresEventWriter's own call (AnalyticsEventSanitizerTest),
            // so nothing that ends up in Postgres changes. The one exception is a referrer
            // long enough to hit the 512-char cap AND carrying astral-plane characters:
            // Str::limit truncates on display width, which can perturb the second pass.
            // That drifts toward more redaction, never less, so it cannot leak PII.
            referrer: AnalyticsEventSanitizer::referrer($referrer),
            utmSource: $data['utm_source'] ?? null,
            utmMedium: $data['utm_medium'] ?? null,
            utmCampaign: $data['utm_campaign'] ?? null,
            countryCode: $this->detectCountryCode($request),
            deviceType: $this->detectDeviceType($request->userAgent()),
            blockId: $blockId,
            // sectionSeen passes the key explicitly; v2 clicks carry the section
            // they happened in via payload (which section hosted the anchor).
            sectionKey: $sectionKey ?? $data['section_key'] ?? null,
            regionCode: $this->detectRegionCode($request),
            city: $this->detectCity($request),
            url: $data['url'] ?? null,
            platform: isset($data['platform']) ? strtolower($data['platform']) : null,
            productId: $data['product_id'] ?? null,
            productTitle: $data['product_title'] ?? null,
            label: $data['label'] ?? null,
            durationSeconds: $durationSeconds,
            latitude: $this->detectLatitude($request),
            longitude: $this->detectLongitude($request),
            itemType: $itemType,
            itemId: $itemId,
            itemTitle: $itemTitle,
            durationMs: $durationMs,
        );
    }

    // Mirrors the old inline rule: keep only values that are valid URLs.
    private function sanitizeReferrer(?string $raw): ?string
    {
        return ($raw !== null && filter_var($raw, FILTER_VALIDATE_URL)) ? $raw : null;
    }

    /**
     * Real-user monitoring beacon. Logs first-paint / load timings to a structured
     * channel for offline percentile analysis. No DB writes.
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
            Log::info('rum', [
                'handle' => strtolower($handle),
                'ttfb_ms' => isset($payload['ttfb']) ? (int) $payload['ttfb'] : null,
                'dom_ms' => isset($payload['dom']) ? (int) $payload['dom'] : null,
                'load_ms' => isset($payload['load']) ? (int) $payload['load'] : null,
                'fcp_ms' => isset($payload['fcp']) ? (int) $payload['fcp'] : null,
                'lkg' => isset($payload['lkg']) ? (bool) $payload['lkg'] : false,
                // PRIV-1: shared cap, not an inline substr — AnalyticsEventSanitizer::userAgent()
                // passes '' as Str::limit's $end, so there's no truncation-marker mismatch versus
                // the old substr(). Net behaviour change: absent UA logs as null, not ''.
                'ua' => AnalyticsEventSanitizer::userAgent($request->userAgent()),
                'country' => $request->header('cf-ipcountry'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('analytics.rum_logging_failed', ['error' => $e->getMessage()]);
        }

        return $this->success(['message' => 'ok'], 200);
    }
}
