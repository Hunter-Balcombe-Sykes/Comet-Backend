<?php

namespace App\Services\Analytics\Writers;

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
// v2: clicks without a blockId are valid (skeleton sitepages — destination url +
// labels are on the event itself), and TYPE_SESSION_PING upserts
// analytics.site_sessions with GREATEST() merges (idempotent under retries).
class PostgresEventWriter implements AnalyticsEventWriter
{
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
        $sessionEvents = [];

        foreach ($events as $event) {
            match ($event->type) {
                AnalyticsEvent::TYPE_PAGEVIEW => $visitRows[] = $this->visitRow($event),
                AnalyticsEvent::TYPE_CLICK => $this->appendClickRow($event, $blocks, $clickRows),
                AnalyticsEvent::TYPE_SECTION_VIEW => $this->appendSectionRow($event, $blocks, $sectionRows),
                AnalyticsEvent::TYPE_SESSION_PING => $sessionEvents[] = $event,
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
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
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
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII) and cap UA.
            'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
            'referrer' => AnalyticsEventSanitizer::referrer($e->referrer),
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'region_code' => $e->regionCode,
            'city' => $e->city,
            'device_type' => $e->deviceType,
            'latitude' => $e->latitude,
            'longitude' => $e->longitude,
        ];
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
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII) and cap UA.
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
            // PRIV-5/6: strip referrer query strings (UTM-embedded PII) and cap UA.
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
     * Upsert one session row from a heartbeat. PK is the client-minted session
     * UUID; GREATEST() on last_seen/duration makes at-least-once delivery and
     * out-of-order pings idempotent. The site_id guard stops a hostile client
     * replaying someone else's session UUID against a different site. Origin
     * fields (geo/device/referrer/visitor) are first-write-wins by design.
     */
    private function upsertSession(AnalyticsEvent $e): void
    {
        // started_at derived in PHP (last_seen − cumulative seconds) so the SQL
        // stays driver-portable — SQLite test envs lack make_interval().
        $seconds = max(0, min(86400, (int) ($e->durationSeconds ?? 0)));
        $lastSeen = Carbon::parse($e->occurredAt);
        $startedAt = $lastSeen->copy()->subSeconds($seconds);

        $greatest = DB::connection('pgsql')->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        DB::connection('pgsql')->statement(
            "INSERT INTO analytics.site_sessions
                (id, user_id, site_id, visitor_id, started_at, last_seen_at, duration_seconds,
                 country_code, region_code, device_type, referrer, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
                last_seen_at = {$greatest}(site_sessions.last_seen_at, EXCLUDED.last_seen_at),
                duration_seconds = {$greatest}(site_sessions.duration_seconds, EXCLUDED.duration_seconds)
             WHERE site_sessions.site_id = EXCLUDED.site_id",
            [
                $e->sessionId,
                $e->userId,
                $e->siteId,
                $e->visitorId,
                $startedAt->toISOString(),
                $lastSeen->toISOString(),
                $seconds,
                $e->countryCode,
                $e->regionCode,
                $e->deviceType,
                // PRIV-5: strip referrer query strings (UTM-embedded PII).
                AnalyticsEventSanitizer::referrer($e->referrer),
                now()->toISOString(),
            ]
        );
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
