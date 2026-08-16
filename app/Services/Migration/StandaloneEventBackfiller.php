<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Services\Content\ManualEventWriter;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 7 Phase 4 / parent §7 step 1: carry the live STANDALONE event
 * connections — `resource_kind = 'event'`, one event added by URL from the
 * Tickets & Events card — onto `content.*` as `event`-kind items, through the
 * slice-0b manual lane. Never raw writes into content.* (parent spec §6).
 *
 * WHY THIS EXISTS AT ALL. Slice 2 retired the ACCOUNT half of the legacy events
 * lane but left standalone rows publishing their full payload, because a
 * standalone row has no ingest connector (ConnectorRegistry::MAP holds
 * organiser-level eventbrite/humanitix only, and SourceProvisioner provisions
 * from an ORGANISER url), so it landed no content.items and the pool could not
 * represent it. Slice 0b's manual write lane is what makes it representable —
 * an owner-authored item with no connector is exactly what
 * ProjectionWriter::writeManualItem() is for. Emptying the standalone payload
 * BEFORE this runs would blank the events, not migrate them.
 *
 * COORD: `manual:{sha1(strtolower(trim(link)))}` — the event URL, not the
 * connection uuid, and byte-identical to ManualEventWriter::coordFor(). §1.7's
 * one-coord-per-canonical-URL rule: an owner re-adding the same event by hand
 * updates the item they already have. A uuid coord would fork the two lanes
 * permanently, and two manual coords carrying ONE url do not merge —
 * Resolver::poisonedKeys() drops a value a single source contributes twice,
 * disabling that url as a joining key for the whole run. Hence the per-user
 * dedupe below.
 *
 * NOT carried: the payload's `image`. Phase 3 declined to mint
 * content.media_assets for third-party image URLs and EventsCatalog's
 * hand-added lane inherited that ruling; a migration is not where it reopens.
 *
 * Re-runnable by design (parent invariant #4). Until Phase 6 retires the
 * per-platform `POST /api/platforms/{eventbrite|humanitix}/events` verb — which
 * still writes a connection row — a re-run is how an event added there reaches
 * the pool.
 */
class StandaloneEventBackfiller
{
    private const POOL = ManualEventWriter::POOL;

    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}

    /** @return array{backfilled: int, duplicate_url: int, skipped_no_url: int, skipped_no_site: int, already_curated: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = [
            'backfilled' => 0, 'duplicate_url' => 0, 'skipped_no_url' => 0,
            'skipped_no_site' => 0, 'already_curated' => 0, 'failed' => 0,
        ];

        // resource_kind, not a resource_id prefix: the discriminator column is
        // the identity (FOUND-34), and an ACCOUNT row's events already land in
        // content.items through the ingest connectors — carrying them here too
        // would mint a second, thinner copy of every one of them.
        $rows = DB::connection('pgsql')->table('site.platform_connections')
            ->where('resource_kind', 'event')
            ->whereNull('deleted_at')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            // The owner's arrangement, tie-broken deterministically — standalone
            // rows all carry sort_order 0 on dev, so without created_at the pin
            // order would be whatever the scan returned.
            ->orderBy('user_id')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, Site|null> */
        $sites = [];
        /** @var array<string, array<string, true>> user id => coords already written this run */
        $seen = [];
        /** @var array<string, float> section id => last pin position written */
        $position = [];
        $touchedSites = [];

        foreach ($rows as $connection) {
            try {
                $owner = (string) $connection->user_id;
                $event = StandaloneEventPayload::fromArray($this->payload($connection))->event();
                $url = trim((string) ($event['link'] ?? ''));

                if ($url === '') {
                    // Loud rather than silent: an event that vanishes from the
                    // count is one nobody decided to drop.
                    $result['skipped_no_url']++;

                    continue;
                }

                $coord = ManualEventWriter::coordFor($url);

                if (isset($seen[$owner][$coord])) {
                    $result['duplicate_url']++;

                    continue;
                }
                $seen[$owner][$coord] = true;

                if ($dryRun) {
                    $result['backfilled']++;

                    continue;
                }

                $itemId = $this->writer->writeManualItem(
                    $owner,
                    $coord,
                    ManualEventWriter::projectStandalone($event, $url),
                );

                $site = $sites[$owner] ??= Site::query()->where('user_id', $owner)->first();

                if ($site === null) {
                    // The item is landed and correct; only the curation half is
                    // impossible. Counted so the gate can tell "not migrated"
                    // from "migrated, unpinnable".
                    $result['skipped_no_site']++;
                    $result['backfilled']++;

                    continue;
                }

                $curated = $this->curate(
                    $site,
                    $itemId,
                    (bool) ($connection->is_active ?? true),
                    $position,
                );

                if ($curated) {
                    $touchedSites[(string) $site->id] = true;
                } else {
                    $result['already_curated']++;
                }

                $result['backfilled']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Standalone-event backfill failed for one connection.', [
                    'connection_id' => $connection->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        if (! $dryRun) {
            // All three lanes, once per touched site (parent spec §4/§9.2).
            // writeManualItem() bumps build state per item; updated_at and the
            // edge purge are the two it deliberately does not own.
            SiteCacheLanes::bust(array_keys($touchedSites));
        }

        return $result;
    }

    /**
     * jsonb reads back as a string through the query builder on Postgres and as
     * TEXT on the SQLite stand-in; an array only ever appears if a caller
     * pre-decoded it.
     *
     * @return array<string, mixed>
     */
    private function payload(object $connection): array
    {
        $raw = $connection->payload ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write this item's curation, unless it already has some. Returns whether
     * anything was written.
     *
     * WHY AN ACTIVE ROW IS PINNED rather than left to the events pool's own
     * `kind_is` + `upcoming_occurrence` rule: the legacy wire published a
     * standalone event's payload REGARDLESS of its dates —
     * PublicIntegrationConnectionResource never filtered on them. The rule
     * alone would silently drop an event that has already started (one of the
     * two dev rows starts in 2024 and runs to December 2026), turning the
     * payload retirement into a partial blackout instead of a migration. A pin
     * is unioned with the rule candidates, so it reproduces the old wire
     * exactly. It is also what protects the row from a later merge's
     * hard-delete (mergeInto()'s hasCuration check, parent §8.3).
     *
     * is_active=false is the owner hiding an event. It maps to a pool EXCLUDE,
     * not a missing pin — a hidden event with no curation row would be
     * re-selected by the section's rule and republished on the new lane, which
     * is the pathology slice 6 hit from the other side.
     *
     * FIRST WRITE WINS. This command is meant to be re-run, so a second run
     * must not restate its opinion over the owner's: clobbering would flip an
     * `excluded` row back to `pinned` and republish an event the owner hid.
     * The legacy `is_active` flag therefore governs only at FIRST SIGHT.
     *
     * @param  array<string, float>  $position  per-section pin counter, by reference
     */
    private function curate(Site $site, string $itemId, bool $isActive, array &$position): bool
    {
        $section = $this->sections->ensure($site, self::POOL);
        $sectionId = (string) $section->id;

        $exists = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('item_id', $itemId)
            ->exists();

        if ($exists) {
            return false;
        }

        $row = new SectionItem;
        $row->section_id = $sectionId;
        $row->item_id = $itemId;
        $row->created_at = now();

        if ($isActive) {
            $row->state = SectionItem::STATE_PINNED;
            // Seeded from what is already pinned so a re-run APPENDS. Starting
            // from 1.0 every time would drop each newly-added event in front of
            // the arrangement the first run wrote.
            $row->sort_key = $position[$sectionId] = ($position[$sectionId] ?? $this->highestSortKey($sectionId)) + 1.0;
        } else {
            // No sort_key — a stale one would resurface it in pin order the
            // moment a later write forgets to clear excluded state first.
            $row->state = SectionItem::STATE_EXCLUDED;
            $row->sort_key = null;
        }

        $row->save();

        return true;
    }

    /** The highest pin position already in this section, or 0.0. */
    private function highestSortKey(string $sectionId): float
    {
        $highest = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 0.0 : (float) $highest;
    }
}
