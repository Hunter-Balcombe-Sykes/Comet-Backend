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

    /** @return array{backfilled: int, duplicate_url: int, skipped_no_url: int, skipped_no_site: int, already_curated: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = [
            'backfilled' => 0, 'duplicate_url' => 0, 'skipped_no_url' => 0,
            'skipped_no_site' => 0, 'already_curated' => 0, 'failed' => 0,
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
        /** @var array<string, float> section id => last pin position written */
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

                $itemId = $this->writer->writeManualItem($owner, $coord, $this->linkProjection($payload, $url));

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
     * Deliberately NOT named after ManualServiceWriter's equivalent:
     * FreshaMapperGuardTest pins every file under app/ that names that method,
     * so a new caller of the owner-authored SERVICE price mapper fails the
     * build and forces a deliberate look (a scraped Fresha row through it marks
     * a whole salon's menu free). This is a different mapper on a different
     * kind; joining that list would blunt the guard into "files containing a
     * string" for every later reader — and this comment avoids spelling the
     * literal for the same reason. Kept public as the seam Phase 6 needs when
     * the live custom-link write path moves onto this lane.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function linkProjection(array $payload, string $url): array
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
     * Write this item's curation, unless it already has some. Returns whether
     * anything was written.
     *
     * FIRST WRITE WINS, and that is the whole point. This command is meant to
     * be re-run — it is how a link added after the first pass lands, until
     * Phase 6 moves the live write path — so a second run must not restate its
     * opinion over the owner's. Clobbering unconditionally would flip an
     * `excluded` row (the owner removed this link from their page) back to
     * `pinned` and republish it, which is a migration undoing a person's
     * decision. It also means the legacy `is_active` flag governs only at FIRST
     * SIGHT: after that the pool's own curation is the record.
     *
     * Owner ordering lives in the curation half (parent §3.3): a pin, not a new
     * ordering operator. It also protects the row — mergeInto()'s hasCuration
     * check reads site.section_items, so a pinned item cannot be hard-deleted
     * by a later merge (parent §8.3).
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
            // from 1.0 every time would drop each newly-added link in front of
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
