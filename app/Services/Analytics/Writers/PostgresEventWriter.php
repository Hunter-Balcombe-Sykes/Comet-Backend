<?php

namespace App\Services\Analytics\Writers;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\TrackableBlockTypes;
use Illuminate\Support\Facades\Log;

// Persists analytics events to the raw Postgres tables. Owns authoritative block
// validation (moved off the request hot path — full decouple). Idempotent: the minted
// UUID is the explicit PK and inserts use insertOrIgnore (ON CONFLICT (id) DO NOTHING),
// so an at-least-once retry no-ops instead of double-counting.
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

        foreach ($events as $event) {
            match ($event->type) {
                AnalyticsEvent::TYPE_PAGEVIEW => $visitRows[] = $this->visitRow($event),
                AnalyticsEvent::TYPE_CLICK => $this->appendClickRow($event, $blocks, $clickRows),
                AnalyticsEvent::TYPE_SECTION_VIEW => $this->appendSectionRow($event, $blocks, $sectionRows),
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
            'user_agent' => $e->userAgent,
            'referrer' => $e->referrer,
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
    }

    /** @param  array<string, Block>  $blocks */
    private function appendClickRow(AnalyticsEvent $e, array $blocks, array &$rows): void
    {
        $block = $e->blockId !== null ? ($blocks[$e->blockId] ?? null) : null;

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

        $rows[] = [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'link_block_id' => $e->blockId,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            'user_agent' => $e->userAgent,
            'referrer' => $e->referrer,
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
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
            'user_agent' => $e->userAgent,
            'referrer' => $e->referrer,
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
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
