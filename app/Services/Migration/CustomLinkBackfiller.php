<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Convergence Phase 3 / W5: carry the live `partna.custom_link` connections
 * onto `content.*` as `link`-kind items, through the slice-0b manual lane —
 * never raw writes into content.* (parent spec §6).
 *
 * Scope is the 23 custom-link connections ONLY (owner ruling 2026-08-14). The
 * other 18 pseudo-platform connections — order links, storefronts,
 * reservations — keep their own lanes until Phase 6 retires the whole
 * pseudo-platform category, so this reads `surface_key` rather than the
 * `partna.` brand prefix.
 *
 * COORD: `manual:{sha1(strtolower(trim(url)))}` — deliberately the URL, not
 * the connection uuid. It is the same coord PoolItemCreateController mints for
 * a hand-add, so an owner re-typing a migrated link updates the item they
 * already have. A uuid coord would fork the two lanes permanently, and two
 * manual coords carrying ONE url do not merge: Resolver::poisonedKeys() drops
 * a value a single source contributes twice, disabling that url as a joining
 * key for the whole run. Hence the per-user dedupe below.
 *
 * NOT carried: the payload's `favicon` and `logo`. Both are third-party image
 * URLs, and minting content.media_assets for them pulls in slice 1a's borrowed
 * -asset lane (BorrowedAssetPruner, the ref-only degradation rules) for
 * decoration. The pool publishes `thumbnail: null` for a link; if link cards
 * want brand marks, that is its own decision with its own storage question.
 */
class CustomLinkBackfiller
{
    private const SURFACE_KEY = 'partna.custom_link';

    private const POOL = 'custom_links';

    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}

    /** @return array{backfilled: int, duplicate_url: int, skipped_no_url: int, skipped_no_site: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = [
            'backfilled' => 0, 'duplicate_url' => 0, 'skipped_no_url' => 0,
            'skipped_no_site' => 0, 'failed' => 0,
        ];

        $rows = DB::connection('pgsql')->table('site.platform_connections')
            ->where('surface_key', self::SURFACE_KEY)
            ->whereNull('deleted_at')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            // The owner's arrangement, tie-broken deterministically: 17 of the
            // 23 dev rows share sort_order 0, so without created_at the pin
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
        /** @var array<string, float> user id => next pin position */
        $position = [];
        $touchedSites = [];

        foreach ($rows as $connection) {
            try {
                $owner = (string) $connection->user_id;
                $payload = $this->payload($connection);
                $url = trim((string) ($payload['url'] ?? ''));

                if ($url === '') {
                    // Loud rather than silent: a link that vanishes from the
                    // count is one nobody decided to drop.
                    $result['skipped_no_url']++;

                    continue;
                }

                $coord = 'manual:'.sha1(strtolower($url));

                if (isset($seen[$owner][$coord])) {
                    // The same URL attached twice. Writing it again is harmless
                    // (the coord is stable) but counting it as a second link
                    // would make the coverage gate unreconcilable.
                    $result['duplicate_url']++;

                    continue;
                }
                $seen[$owner][$coord] = true;

                if ($dryRun) {
                    $result['backfilled']++;

                    continue;
                }

                $itemId = $this->writer->writeManualItem($owner, $coord, $this->projectionFor($payload, $url));

                $site = $sites[$owner] ??= Site::query()->where('user_id', $owner)->first();

                if ($site === null) {
                    // The item is landed and correct; only the curation half is
                    // impossible. Counted so the gate can tell "not migrated"
                    // from "migrated, unpinnable".
                    $result['skipped_no_site']++;
                    $result['backfilled']++;

                    continue;
                }

                // is_active=false is the owner hiding a link. It maps to a pool
                // EXCLUDE, not a missing pin — a hidden link that simply had no
                // curation row would be re-selected by the section's kind_is
                // rule and republished on the new lane, which is the pathology
                // slice 6 hit from the other side.
                (bool) ($connection->is_active ?? true)
                    ? $this->pin($site, $itemId, $position[$owner] = ($position[$owner] ?? 0.0) + 1.0)
                    : $this->exclude($site, $itemId);

                $touchedSites[(string) $site->id] = true;
                $result['backfilled']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Custom-link backfill failed for one connection.', [
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
     * The connection payload → `link` projection mapping.
     *
     * Deliberately thin, matching PoolItemCreateController's hand-add contract:
     * a custom link is a URL the owner already knows, titled what they called
     * it. `name` is the scraped page title snapshotted at add time; when it is
     * absent the host stands in, exactly as the hand-add lane does it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function projectionFor(array $payload, string $url): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $headline = $name !== ''
            ? $name
            : (string) (parse_url($url, PHP_URL_HOST) ?: $url);

        $description = trim((string) ($payload['description'] ?? ''));

        $projection = [
            'kind' => 'link',
            'headline' => $headline,
            'facets' => [
                'f_text' => ['headline' => $headline],
                'f_link' => ['url' => $url],
            ],
        ];

        // Omitted rather than written null when there is nothing to say — a new
        // item has no stale body to clear, the same rule ManualServiceWriter
        // follows for its own optional facets.
        if ($description !== '') {
            $projection['facets']['f_text']['body'] = $description;
        }

        return $projection;
    }

    /**
     * jsonb reads back as a string through the query builder on Postgres and
     * as TEXT on the SQLite stand-in; an array only ever appears if a caller
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
     * Owner ordering lives in the CURATION half (parent §3.3): a pin, not a new
     * ordering operator. It also protects the row — mergeInto()'s hasCuration
     * check reads site.section_items, so a pinned item cannot be hard-deleted
     * by a later merge (parent §8.3).
     */
    private function pin(Site $site, string $itemId, float $sortKey): void
    {
        $row = $this->curationRow($site, $itemId);
        $row->state = SectionItem::STATE_PINNED;
        $row->sort_key = $sortKey;
        $row->save();
    }

    /** A live-but-hidden link. No sort_key — a stale one would resurface it in pin order. */
    private function exclude(Site $site, string $itemId): void
    {
        $row = $this->curationRow($site, $itemId);
        $row->state = SectionItem::STATE_EXCLUDED;
        $row->sort_key = null;
        $row->save();
    }

    private function curationRow(Site $site, string $itemId): SectionItem
    {
        $section = $this->sections->ensure($site, self::POOL);

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $row->section_id = (string) $section->id;
        $row->item_id = $itemId;
        if (! $row->exists) {
            $row->created_at = now();
        }

        return $row;
    }
}
