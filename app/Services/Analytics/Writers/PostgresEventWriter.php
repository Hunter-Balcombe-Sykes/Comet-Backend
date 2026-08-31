<?php

namespace App\Services\Analytics\Writers;

use App\Models\Analytics\ActionEvent;
use App\Models\Analytics\ItemView;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\AnalyticsEventSanitizer;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\TrackableBlockTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Persists analytics events to the raw Postgres tables. Owns authoritative block
// validation (moved off the request hot path — full decouple). Idempotent: the minted
// UUID is the explicit PK and inserts use insertOrIgnore (ON CONFLICT (id) DO NOTHING),
// so an at-least-once retry no-ops instead of double-counting.
//
// v2: clicks without a blockId are valid (architecture sitepages — destination url +
// labels are on the event itself), and TYPE_SESSION_PING upserts
// analytics.site_sessions with GREATEST() merges (idempotent under retries).
class PostgresEventWriter implements AnalyticsEventWriter
{
    /**
     * PRIV-9: the precision this lane is HANDED. DetectsClientInfo::parseCoordinate()
     * rounds every inbound coordinate to 4dp (~11m) before an AnalyticsEvent is
     * ever built, so roundCoordinate() below can only ever coarsen a value when
     * it is configured finer than this. At 4 or more decimals round() returns its
     * input unchanged for every value that can reach it: not "less rounding",
     * NO rounding. Kept as a named constant because coordinatesAreCoarsened()
     * is the sentence a real business publishes about its own site.
     */
    public const INGEST_PRECISION_DECIMALS = 4;

    /**
     * The coarsest stored precision the privacy policy may still describe as an
     * area rather than a place. 3dp is ~110m — a block. Anything finer stops
     * being "approximate coordinates for that area" and starts being a doorstep,
     * and at INGEST_PRECISION_DECIMALS it is provably a no-op (above).
     */
    public const MAXIMUM_AREA_PRECISION_DECIMALS = 3;

    public static function configuredLocationPrecisionDecimals(): int
    {
        return (int) config('partna.analytics.location_precision_decimals', 2);
    }

    /**
     * Whether stored coordinates are genuinely coarsened — the question
     * SitePolicyResolver asks this lane before publishing "rounded down in
     * precision before they are saved".
     *
     * That sentence used to be unconditional prose while the rounding behind it
     * hung off a knob with no floor, so PARTNA_ANALYTICS_LOCATION_PRECISION_DECIMALS=6
     * stored exactly what arrived and left a real business asserting, on its own
     * sitepage, a privacy measure its platform was not performing. The knob is
     * deliberately NOT clamped: an operator choosing finer geo data is making a
     * legitimate choice, and the fix for a false sentence is a true sentence,
     * not a config value silently overruled.
     */
    public static function coordinatesAreCoarsened(): bool
    {
        return self::configuredLocationPrecisionDecimals() <= self::MAXIMUM_AREA_PRECISION_DECIMALS;
    }

    public function write(AnalyticsEvent $event): void
    {
        $this->writeMany([$event]);
    }

    /** @param  AnalyticsEvent[]  $events */
    public function writeMany(array $events): void
    {
        if ($events === []) {
            return;
        }

        // Batch-load every referenced block in ONE query so validation never
        // degrades to a SELECT per event (matters for the future BufferedIngestor).
        $blocks = $this->loadBlocks($events);

        $visitRows = [];
        $clickRows = [];
        $sectionRows = [];
        $itemRows = [];
        $actionRows = [];
        $sessionEvents = [];
        $dwellEvents = [];

        foreach ($events as $event) {
            match ($event->type) {
                AnalyticsEvent::TYPE_PAGEVIEW => $visitRows[] = $this->visitRow($event),
                AnalyticsEvent::TYPE_CLICK => $this->appendClickRow($event, $blocks, $clickRows),
                AnalyticsEvent::TYPE_SECTION_VIEW => $this->appendSectionRow($event, $blocks, $sectionRows),
                AnalyticsEvent::TYPE_ITEM_VIEW => $this->appendItemRow($event, $itemRows),
                AnalyticsEvent::TYPE_ACTION_SEEN, AnalyticsEvent::TYPE_ACTION_TAP => $this->appendActionRow($event, $actionRows),
                AnalyticsEvent::TYPE_SESSION_PING => $sessionEvents[] = $event,
                AnalyticsEvent::TYPE_SECTION_DWELL => $dwellEvents[] = $event,
                default => $this->drop($event, 'unknown_type'),
            };
        }

        if ($visitRows !== []) {
            SiteVisit::query()->insertOrIgnore($visitRows);
        }
        if ($clickRows !== []) {
            LinkClick::query()->insertOrIgnore($clickRows);
        }
        if ($sectionRows !== []) {
            SectionView::query()->insertOrIgnore($sectionRows);
        }
        if ($itemRows !== []) {
            ItemView::query()->insertOrIgnore($itemRows);
        }
        if ($actionRows !== []) {
            ActionEvent::query()->insertOrIgnore($actionRows);
        }
        if ($sessionEvents !== []) {
            $this->upsertSessions($sessionEvents);
        }
        // Dwell AFTER section inserts so a same-batch impression row exists
        // before its dwell annotation looks for it.
        foreach ($dwellEvents as $event) {
            $this->applySectionDwell($event);
        }
    }

    /**
     * @param  AnalyticsEvent[]  $events
     * @return array<string, Block>
     */
    private function loadBlocks(array $events): array
    {
        $ids = [];
        foreach ($events as $e) {
            if ($e->blockId !== null) {
                $ids[$e->blockId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        return Block::query()->whereIn('id', array_keys($ids))->get()->keyBy('id')->all();
    }

    /** @return array<string, mixed> */
    private function visitRow(AnalyticsEvent $e): array
    {
        return [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII); PRIV-2 reduces UA to family/major version.
            'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
            'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'region_code' => $e->regionCode,
            'city' => $e->city,
            'device_type' => $e->deviceType,
            // PRIV-1: round to config('partna.analytics.location_precision_decimals') at
            // the persistence boundary — see roundCoordinate() docblock.
            'latitude' => $this->roundCoordinate($e->latitude),
            'longitude' => $this->roundCoordinate($e->longitude),
        ];
    }

    /**
     * PRIV-1: round a visitor coordinate to configuredLocationPrecisionDecimals()
     * (default 2dp, ≈1.1km) — enough for city/region analytics, not enough to locate a
     * person. Null passes through unchanged (never coerced to 0.0).
     */
    private function roundCoordinate(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($value, self::configuredLocationPrecisionDecimals());
    }

    /** @param  array<string, Block>  $blocks */
    private function appendClickRow(AnalyticsEvent $e, array $blocks, array &$rows): void
    {
        // v2 path — no block reference: the event self-describes (url + labels).
        // ClickRequest guarantees url is present when block_id is absent.
        if ($e->blockId === null) {
            if ($e->url === null) {
                $this->drop($e, 'url_missing');

                return;
            }

            $rows[] = $this->clickRow($e);

            return;
        }

        // Legacy block path — authoritative existence/ownership/trackability checks.
        $block = $blocks[$e->blockId] ?? null;

        if (! $block) {
            $this->drop($e, 'block_missing');

            return;
        }
        if ($block->site_id !== $e->siteId) {
            $this->drop($e, 'block_foreign_site');

            return;
        }
        if (! TrackableBlockTypes::isClickTrackable($block->block_group, $block->block_type)) {
            $this->drop($e, 'block_not_trackable');

            return;
        }
        if (! $block->is_active) {
            $this->drop($e, 'block_inactive');

            return;
        }

        $rows[] = $this->clickRow($e);
    }

    /** @return array<string, mixed> */
    private function clickRow(AnalyticsEvent $e): array
    {
        return [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'link_block_id' => $e->blockId,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII); PRIV-2 reduces UA to family/major version.
            'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
            'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'url' => $e->url,
            'platform' => $e->platform,
            'product_id' => $e->productId,
            'product_title' => $e->productTitle,
            'section_key' => $e->sectionKey,
            'label' => $e->label,
            'country_code' => $e->countryCode,
            'region_code' => $e->regionCode,
            'device_type' => $e->deviceType,
        ];
    }

    /** @param  array<string, Block>  $blocks */
    private function appendSectionRow(AnalyticsEvent $e, array $blocks, array &$rows): void
    {
        // block_id is OPTIONAL for sections; null is valid (header/footer/bio). When
        // present it must belong to the site — cross-site IDOR defence, preserved from
        // the controller and relocated here.
        if ($e->blockId !== null) {
            $block = $blocks[$e->blockId] ?? null;
            if (! $block) {
                $this->drop($e, 'block_missing');

                return;
            }
            if ($block->site_id !== $e->siteId) {
                $this->drop($e, 'block_foreign_site');

                return;
            }
        }

        $rows[] = [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'block_id' => $e->blockId,
            'section_key' => $e->sectionKey,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII); PRIV-2 reduces UA to family/major version.
            'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
            'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
    }

    /**
     * Append an item-impression row (analytics.item_views). No block FK — the
     * event self-describes via item_type/item_id (mirrors the v2 click path).
     * item_type/item_id are required at the FormRequest; the null guard here is
     * defence-in-depth so a malformed queued payload drops rather than 500s on
     * the NOT NULL columns.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function appendItemRow(AnalyticsEvent $e, array &$rows): void
    {
        if ($e->itemType === null || $e->itemId === null) {
            $this->drop($e, 'item_identity_missing');

            return;
        }

        $rows[] = [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'item_type' => $e->itemType,
            'item_id' => $e->itemId,
            'item_title' => $e->itemTitle,
            'section_key' => $e->sectionKey,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII); PRIV-2 reduces UA to family/major version.
            'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
            'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
    }

    /**
     * Append an action exposure/tap row (analytics.action_events). No block
     * FK — self-describes via action_id, mirrors appendItemRow() exactly.
     * event kind ('seen'/'tap') is the AnalyticsEvent type itself, not a
     * separate field, so it's derived here rather than carried on the DTO.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function appendActionRow(AnalyticsEvent $e, array &$rows): void
    {
        if ($e->actionId === null) {
            $this->drop($e, 'action_identity_missing');

            return;
        }

        $rows[] = [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'action_id' => $e->actionId,
            'event' => $e->type === AnalyticsEvent::TYPE_ACTION_TAP ? 'tap' : 'seen',
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII); PRIV-2 reduces UA to family/major version.
            'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
            'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
    }

    /**
     * Upsert session rows from a batch of heartbeats in ONE round trip (CACHE-1 —
     * was a per-event loop, the only sibling array in writeMany() not already
     * batched). PK is the composite (client-minted session UUID, site_id) —
     * #DINT-1: a bare id-only PK let a session id reused across two different
     * sites (one visitor browsing two Partna sites) silently drop the second
     * site's heartbeat, because the old id-only conflict target found a row that
     * belonged to the WRONG site and a WHERE guard blocked the update with
     * nowhere for the insert to go. Keying the conflict target on (id, site_id)
     * means a conflict can only ever fire when site_id already matches, so two
     * sites sharing a session id now get two independent rows instead of one
     * clobbering silently over the other. GREATEST() on last_seen/duration makes
     * at-least-once delivery and out-of-order pings idempotent. Origin fields
     * (geo/device/referrer/visitor) are first-write-wins by design.
     *
     * Postgres forbids an INSERT ... ON CONFLICT DO UPDATE from touching the same
     * row twice within one statement ("ON CONFLICT DO UPDATE command cannot
     * affect row a second time"), so same-batch pings for the same (id, site_id)
     * are pre-merged in PHP below — first-write-wins fields keep the FIRST
     * event's values (array order, same as the old sequential-upsert order),
     * last_seen_at/duration_seconds GREATEST-merge across the batch exactly like
     * the per-row ON CONFLICT clause would.
     *
     * @param  AnalyticsEvent[]  $events
     */
    private function upsertSessions(array $events): void
    {
        $rows = [];
        foreach ($events as $e) {
            // started_at derived in PHP (last_seen − cumulative seconds) so the SQL
            // stays driver-portable — SQLite test envs lack make_interval().
            $seconds = max(0, min(86400, (int) ($e->durationSeconds ?? 0)));
            $lastSeen = Carbon::parse($e->occurredAt);
            $key = $e->sessionId.'|'.$e->siteId;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'id' => $e->sessionId,
                    'user_id' => $e->userId,
                    'site_id' => $e->siteId,
                    'visitor_id' => $e->visitorId,
                    'started_at' => $lastSeen->copy()->subSeconds($seconds),
                    'last_seen_at' => $lastSeen,
                    'duration_seconds' => $seconds,
                    'country_code' => $e->countryCode,
                    'region_code' => $e->regionCode,
                    'device_type' => $e->deviceType,
                    // PRIV-5: strip referrer query strings (UTM-embedded PII).
                    'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
                ];

                continue;
            }

            // Same session repeated within this batch — merge like the DB
            // conflict clause would; started_at (and the other first-write-wins
            // fields) stay untouched, matching per-row upsert semantics.
            if ($lastSeen->gt($rows[$key]['last_seen_at'])) {
                $rows[$key]['last_seen_at'] = $lastSeen;
            }
            if ($seconds > $rows[$key]['duration_seconds']) {
                $rows[$key]['duration_seconds'] = $seconds;
            }
        }

        $greatest = DB::connection('pgsql')->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
        $now = now()->toISOString();

        $placeholders = [];
        $bindings = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $row['id'],
                $row['user_id'],
                $row['site_id'],
                $row['visitor_id'],
                $row['started_at']->toISOString(),
                $row['last_seen_at']->toISOString(),
                $row['duration_seconds'],
                $row['country_code'],
                $row['region_code'],
                $row['device_type'],
                $row['referrer'],
                $now,
            );
        }

        DB::connection('pgsql')->statement(
            'INSERT INTO analytics.site_sessions
                (id, user_id, site_id, visitor_id, started_at, last_seen_at, duration_seconds,
                 country_code, region_code, device_type, referrer, created_at)
             VALUES '.implode(', ', $placeholders)."
             ON CONFLICT (id, site_id) DO UPDATE SET
                last_seen_at = {$greatest}(site_sessions.last_seen_at, EXCLUDED.last_seen_at),
                duration_seconds = {$greatest}(site_sessions.duration_seconds, EXCLUDED.duration_seconds)",
            $bindings
        );
    }

    /**
     * Annotate the matching section impression row with cumulative dwell.
     *
     * Targets the LATEST section_views row for (site, section, visitor|session)
     * within 24h and GREATEST-merges duration_ms — the client reports CUMULATIVE
     * visible-time per section, so retries, out-of-order delivery and re-entry
     * reports are all idempotent (ping's pattern). UPDATE only, never INSERT:
     * a dwell whose impression beacon was lost drops (impressions stay exact),
     * which is the right degradation — dwell is the garnish, the impression the meal.
     */
    private function applySectionDwell(AnalyticsEvent $e): void
    {
        if ($e->sectionKey === null || $e->durationMs === null) {
            $this->drop($e, 'dwell_fields_missing');

            return;
        }

        // Prefer the visitor id (persistent) over the session id — the seen beacon
        // sends both, so either matches its row; the dwell request guarantees one.
        [$idColumn, $idValue] = $e->visitorId !== null
            ? ['visitor_id', $e->visitorId]
            : ['session_id', $e->sessionId];
        if ($idValue === null) {
            $this->drop($e, 'dwell_identity_missing');

            return;
        }

        $ms = max(0, min(600_000, $e->durationMs));

        $targetId = DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $e->siteId)
            ->where('section_key', $e->sectionKey)
            ->where($idColumn, $idValue)
            ->where('occurred_at', '>=', Carbon::parse($e->occurredAt)->subDay()->toISOString())
            ->orderByDesc('occurred_at')
            ->limit(1)
            ->value('id');

        if ($targetId === null) {
            $this->drop($e, 'dwell_row_missing');

            return;
        }

        $greatest = DB::connection('pgsql')->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        DB::connection('pgsql')->table('analytics.section_views')
            ->where('id', $targetId)
            // $ms is int-clamped above — safe to interpolate.
            ->update(['duration_ms' => DB::raw("{$greatest}(COALESCE(duration_ms, 0), {$ms})")]);
    }

    // Breadcrumb only — Nightwatch surfaces sustained spikes via log-channel
    // aggregation; a single drop does not page. A true rate alert is a Nightwatch
    // dashboard config (ops follow-up), not code.
    private function drop(AnalyticsEvent $e, string $reason): void
    {
        Log::warning('analytics.ingest.dropped', [
            'reason' => $reason,
            'type' => $e->type,
            'site_id' => $e->siteId,
            'block_id' => $e->blockId,
        ]);
    }
}
