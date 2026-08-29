<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ActionSeenRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ActionTapRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ItemSeenRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PingRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionDwellRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionSeenRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsDedupGuard;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\AnalyticsEventSanitizer;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\DailyCounterClaim;
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
    use EscalatesRepeatedFaults;
    use HashesClientData;
    use ResolvesSiteFromRequest;

    /**
     * TTL on the per-site pageview burst counter — 2x the 60s bucket width. The
     * window is defined by the minute bucket IN the key, so this only has to
     * outlive the bucket it belongs to; the doubling means a claim landing on a
     * window boundary can never leave a key without an expiry.
     */
    private const PAGEVIEW_BURST_BUCKET_SECONDS = 60;

    private const PAGEVIEW_BURST_TTL_SECONDS = self::PAGEVIEW_BURST_BUCKET_SECONDS * 2;

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

        if (! $this->originAllowed($request, $site)) {
            return $this->error('Site not found', 404);
        }

        // pageview intentionally has NO bot filter and NO dedup, and that is UNCHANGED
        // (#W1-SCALE-3, 2026-08-29). A bot UA still records a pageview and is separated
        // at READ time by device_type ('label, don't drop' — owner decision, pinned by
        // PageviewDeviceTypeTest); a genuine refresh still counts. What WAS missing is a
        // tenant-scoped bound: every other control here is per-IP, so a distributed
        // crawler sweep across many source IPs slipped through all of them. The per-site
        // burst cap below is that bound, and it is the whole of the change — do not
        // re-file the bot filter or a visitor-keyed dedup against this method.
        // Checked BEFORE buildEvent(): that builder is pure (no logging, no writes, no
        // dispatch), so running it first would only cost work we are about to discard.
        // Bot LABELLING is unaffected either way — it happens inside buildEvent, which
        // still runs for every request under the cap.
        if (! $this->withinSiteBurstCap($site)) {
            // Over the ceiling: mint the id and answer exactly as a recorded pageview
            // does, dropping only the queue write. Byte-identical response, so there is
            // no wire change and no way to fingerprint the cap.
            //
            // Fixed window, so up to 2x the cap can pass across a bucket boundary, and
            // over the ceiling a real visitor's pageview is dropped alongside a bot's.
            // Both are inherent to a per-site ceiling and were signed off as such.
            return $this->success([
                'message' => 'Pageview recorded',
                'visit_id' => (string) Str::orderedUuid(),
            ], 201);
        }

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

        if (! $this->originAllowed($request, $site)) {
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

        if (! $this->originAllowed($request, $site)) {
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

        if (! $this->originAllowed($request, $site)) {
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

        if (! $this->originAllowed($request, $site)) {
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
     * Action-exposure ingest (unified actions system, demand-rate scoring).
     * Mirrors itemSeen() exactly — resolve + publication-gate + origin-bind +
     * bot-drop + 5min Redis dedup — but keyed by (event='seen', action_id)
     * instead of (item_type, item_id). Writes analytics.action_events.
     */
    public function actionSeen(ActionSeenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if (! $this->originAllowed($request, $site)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Action seen recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        $identifier = $this->dedupIdentifier($data, $request);
        $key = CacheKeyGenerator::analyticsActionDedup($site->id, 'seen', $data['action_id'], $identifier);
        $claim = $this->dedup->claim($key, $id, (int) config('partna.analytics.action_dedup_ttl_seconds', 300));
        if (! $claim['novel']) {
            return $this->success(['message' => 'Action seen recorded', 'view_id' => $claim['id']], 201);
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_ACTION_SEEN,
            request: $request,
            site: $site,
            data: $data,
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            actionId: $data['action_id'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Action seen recorded', 'view_id' => $event->id], 201);
    }

    /**
     * Action-tap ingest (unified actions system, demand-rate scoring). Same
     * shape as actionSeen() but a shorter dedup window — repeated taps on the
     * SAME action within a session are a real, distinct signal each time
     * (matching how ActionScorer counts DISTINCT sessions, not raw
     * events — a short window only collapses accidental double-fires, e.g. a
     * fast double-tap or a duplicate sendBeacon retry).
     */
    public function actionTap(ActionTapRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if (! $this->originAllowed($request, $site)) {
            return $this->error('Site not found', 404);
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Action tap recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        $identifier = $this->dedupIdentifier($data, $request);
        $key = CacheKeyGenerator::analyticsActionDedup($site->id, 'tap', $data['action_id'], $identifier);
        $claim = $this->dedup->claim($key, $id, (int) config('partna.analytics.action_tap_dedup_ttl_seconds', 3));
        if (! $claim['novel']) {
            return $this->success(['message' => 'Action tap recorded', 'click_id' => $claim['id']], 201);
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_ACTION_TAP,
            request: $request,
            site: $site,
            data: $data,
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            actionId: $data['action_id'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Action tap recorded', 'click_id' => $event->id], 201);
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

        if (! $this->originAllowed($request, $site)) {
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

        // T9 (issue 4, 2026-08-27): unclaimed pre-account sites render
        // publicly with is_published=false (profiles endpoint has no publish
        // gate; KV routes the subdomain), so gating ingest on is_published
        // alone silently discarded every visitor to a demo site. Accept the
        // renderable-as-unclaimed case; a CLAIMED owner's unpublished site
        // stays 404 — for them the publish knob IS the visibility switch.
        // By id, not a relation walk (preventLazyLoading is armed outside prod).
        if (! $site->is_published
            && User::query()->whereKey($site->user_id)->value('status') !== 'unclaimed') {
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
     *   Origin header → absent
     *
     * When Origin is absent: rejected. Every legitimate caller is the sitepage's own
     * client-side beacon, and every ingest route is a POST, on which a browser always
     * emits Origin. Referer was accepted here until #SEC-3 (2026-08-24) — a non-browser
     * caller can set it as freely as any other header, so it authenticated nothing. A
     * genuine server-to-server caller must be gated by a shared secret or signed
     * request, never by public identifiers.
     */
    private function originAllowed(Request $request, Site $site): bool
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

        // Extract the request's origin host from the Origin header.
        $originHost = $this->parseOriginHost($request);

        // No origin signal at all — fail closed (SEC-1). site_id and subdomain are
        // both public, so they can never stand in for a browser-issued Origin.
        if ($originHost === null) {
            return false;
        }

        return in_array($originHost, $allowed, true);
    }

    /**
     * Extract a lowercase host from the Origin header. Returns null when the
     * header is absent, is the literal 'null' (sandboxed iframe), or does not
     * parse to a host.
     *
     * #SEC-3: no Referer fallback. Origin is unforgeable from browser JS, which
     * is the entire basis of originAllowed(); Referer is not — a scripted caller
     * sets either header freely, and a site's subdomain is public, so accepting
     * Referer reopened the exact forgery the 2026-07-24 SEC-1 fix closed on the
     * site_id vector. Every legitimate caller is the sitepage's own beacon, and
     * all eight ingest routes are POST, on which a browser always sends Origin.
     */
    private function parseOriginHost(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * #W1-SCALE-3 — per-SITE pageview ingest ceiling, fixed one-minute window.
     *
     * The route already carries throttle:analytics (120/min per visitor IP + a
     * 3000/min per-true-IP backstop, AppServiceProvider::configureRateLimiting).
     * Both are per-IP: a crawler sweep distributed across many source IPs against
     * one viral page satisfies every one of them, and the ingest it generates eats
     * shared `analytics` queue capacity belonging to other tenants. This is the
     * only bound keyed by the thing being protected — the site.
     *
     * FAILS OPEN. Any cache fault ingests normally: analytics is a fail-open
     * subsystem by contract (QueuedIngestor, AnalyticsDedupGuard), and a Valkey
     * blip must never drop or 500 a beacon. A SUSTAINED run of faults still pages
     * via EscalatesRepeatedFaults, under its own 'pageview_burst' label so it
     * cannot merge with the dedup guard's counter.
     *
     * The counter goes through DailyCounterClaim, not a bare Cache::increment:
     * INCRBY on a missing key recreates it with NO expiry, and cache shares a
     * Valkey instance with the queue under instance-wide volatile-lru, where a
     * TTL-less key is permanent, inevictable ballast. DailyCounterClaim asserts
     * the TTL server-side on every path.
     */
    private function withinSiteBurstCap(Site $site): bool
    {
        $cap = (int) config('partna.analytics.pageview_site_cap_per_minute', 2000);

        // A non-positive cap disables the ceiling outright — the kill switch.
        if ($cap <= 0) {
            return true;
        }

        try {
            return DailyCounterClaim::claim(
                // now(), not time(): the bucket then follows Carbon's test clock, so a
                // window-boundary case is expressible instead of being a race.
                CacheKeyGenerator::analyticsPageviewSiteBurst($site->id, intdiv(now()->getTimestamp(), self::PAGEVIEW_BURST_BUCKET_SECONDS)),
                $cap,
                self::PAGEVIEW_BURST_TTL_SECONDS,
            );
        } catch (\Throwable $e) {
            Log::warning('analytics.pageview_burst_cap_fault', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            self::escalateIfSustained($e, 'pageview_burst');

            return true;
        }
    }

    /**
     * Strongest identifier for a dedup claim: visitor_id (persistent) > session_id >
     * an IP-hash fallback, so a beacon that sends neither doesn't skip dedup entirely
     * (WHK-1). "ip:" namespaces the fallback — visitor/session values are UUIDs and
     * never contain a colon, so this can never collide with a real identifier.
     */
    private function dedupIdentifier(array $data, Request $request): string
    {
        return $data['visitor_id'] ?? $data['session_id'] ?? 'ip:'.$this->hashIp($this->visitorIp($request));
    }

    /**
     * The visitor's real IP: the /t/* proxy forwards the original request's
     * connecting IP as x-visitor-ip (the subrequest's own is often a shared
     * Cloudflare colo IP — 2026-08-27); direct hits fall through to the
     * request IP.
     */
    private function visitorIp(Request $request): ?string
    {
        $forwarded = $request->header('x-visitor-ip');

        return is_string($forwarded) && $forwarded !== '' ? $forwarded : $request->ip();
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
        ?string $actionId = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            id: $id ?? (string) Str::orderedUuid(),
            type: $type,
            occurredAt: now()->toISOString(),
            userId: $site->user_id,
            siteId: $site->id,
            sessionId: $data['session_id'] ?? null,
            visitorId: $data['visitor_id'] ?? null,
            ipHash: $this->hashIp($this->visitorIp($request)),
            // PGR-20: same JOB-1 choke-point rationale as referrer below — sanitise
            // here so a raw UA never reaches the Redis queue payload, not just the
            // Postgres row. Idempotent with PostgresEventWriter's own call.
            userAgent: AnalyticsEventSanitizer::userAgent($request->userAgent()),
            // JOB-1: sanitise here — the single choke point every beacon type funnels
            // through — so a raw UTM-embedded-PII referrer never reaches the Redis queue
            // payload. Idempotent with PostgresEventWriter's own call (AnalyticsEventSanitizerTest),
            // so nothing that ends up in Postgres changes. The one exception is a referrer
            // long enough to hit the 512-char cap AND carrying astral-plane characters:
            // Str::limit truncates on display width, which can perturb the second pass.
            // That drifts toward more redaction, never less, so it cannot leak PII.
            referrer: AnalyticsEventSanitizer::referrer($referrer),
            // PGR-18: same drop-on-suspicion discipline as referrer() — a UTM value
            // carrying an email-like substring never reaches the queue payload.
            utmSource: AnalyticsEventSanitizer::utmParam($data['utm_source'] ?? null),
            utmMedium: AnalyticsEventSanitizer::utmParam($data['utm_medium'] ?? null),
            utmCampaign: AnalyticsEventSanitizer::utmParam($data['utm_campaign'] ?? null),
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
            actionId: $actionId,
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
                // PRIV-3: non-reversible — the raw handle is public (it's the
                // subdomain), but hashing keeps this log line consistent with the
                // rest of the codebase's hash-before-log convention and avoids a
                // trivially greppable per-site RUM timing history.
                'handle' => hash('sha256', strtolower($handle)),
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
