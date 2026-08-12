<?php

namespace App\Services\Migration;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Slice 1b D10 — carry the legacy `site.content_selection` media picks into the
 * pool's curated half, and record on the way past the ones that cannot come.
 *
 * Of the 89 rows on dev, 3 migrate and 86 do not. That is a decision, not a
 * mapping failure, and each half has its own reason:
 *
 * - `upload` (3) MIGRATES by `media_id`. Slice 1a's backfiller already gave
 *   each upload an item under the coord `manual:{site_media_id}`, so the
 *   selection just becomes a pin on it.
 *
 * - `google-photo` (80) DROPS. Three independent reasons, any one sufficient:
 *   nobody chose them (`ContentSelectionService::maybeSeedFromGoogle()`
 *   auto-seeds every photo in payload order at connect time); they already
 *   resolve to nothing, because a Places photo ref is reissued on every
 *   Details fetch and none of the stored refs match a live payload; and under
 *   D5 borrowed media has no pinnable destination anyway.
 *
 * - `ig-post` / `ig-reel` (6) DROP. They carry neither `media_id` nor
 *   `external_ref` — they resolved positionally against a live connection
 *   payload, so there is no identifier to migrate by. Reconstructing by
 *   position is explicitly rejected: that order was Instagram's, not the
 *   owner's.
 *
 * NOTHING IS DELETED HERE. `site.content_selection` is dropped in slice 7, so
 * a bad run stays fully recoverable and the "drop" is a decision not to carry,
 * recorded with counts and site ids by the command that drives this.
 */
class ContentSelectionMigrator
{
    private const UPLOAD_TYPES = ['upload'];

    private const GOOGLE_TYPES = ['google-photo'];

    private const INSTAGRAM_TYPES = ['ig-post', 'ig-reel', 'ig-photo'];

    public function __construct(private readonly PoolSectionProvisioner $provisioner) {}

    /**
     * @return array{migrated: int, dropped_google: int, dropped_ig: int, skipped_no_item: int, failed: int, dropped_site_ids: list<string>}
     */
    public function run(bool $dryRun = false, ?string $siteId = null): array
    {
        $result = [
            'migrated' => 0, 'dropped_google' => 0, 'dropped_ig' => 0,
            'skipped_no_item' => 0, 'failed' => 0, 'dropped_site_ids' => [],
        ];
        $touchedSites = [];
        $droppedSites = [];

        $rows = DB::connection('pgsql')->table('site.content_selection')
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('site_id')
            ->orderBy('position')
            ->get();

        foreach ($rows as $row) {
            try {
                $type = (string) $row->entry_type;

                if (in_array($type, self::GOOGLE_TYPES, true)) {
                    $result['dropped_google']++;
                    $droppedSites[(string) $row->site_id] = true;

                    continue;
                }

                if (in_array($type, self::INSTAGRAM_TYPES, true)) {
                    $result['dropped_ig']++;
                    $droppedSites[(string) $row->site_id] = true;

                    continue;
                }

                if (! in_array($type, self::UPLOAD_TYPES, true)) {
                    // An entry_type nobody has decided about must not be
                    // silently swallowed into a drop count.
                    throw new \RuntimeException("content_selection {$row->id}: unhandled entry_type '{$type}'.");
                }

                $itemId = $this->uploadItemId($row->media_id);
                if ($itemId === null) {
                    // 1a's backfiller never gave this upload a home — most
                    // likely a non-'ready' processing_state, which it counts
                    // as skipped rather than dropping. Counted, not fatal.
                    $result['skipped_no_item']++;

                    continue;
                }

                if ($dryRun) {
                    $result['migrated']++;

                    continue;
                }

                $this->pin((string) $row->site_id, $itemId, (int) $row->position);

                $touchedSites[(string) $row->site_id] = true;
                $result['migrated']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Content selection migration failed for one row.', [
                    'content_selection_id' => $row->id ?? null, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        $result['dropped_site_ids'] = array_keys($droppedSites);

        // Only sites whose payload actually CHANGED. Dropping is a no-op, and
        // purging the edge for eighty-six of them would be a self-inflicted
        // cache stampede across every Google-only site.
        if (! $dryRun) {
            $this->invalidate(array_keys($touchedSites));
        }

        return $result;
    }

    /** The item 1a's backfiller landed for this upload, via its `manual:` coord. */
    private function uploadItemId(mixed $mediaId): ?string
    {
        if (! is_string($mediaId) || $mediaId === '') {
            return null;
        }

        $itemId = DB::connection('pgsql')->table('content.source_items')
            ->where('coord', 'manual:'.$mediaId)
            ->whereNull('removed_at')
            ->value('item_id');

        return $itemId === null ? null : (string) $itemId;
    }

    /**
     * Pin the item onto the site's media section.
     *
     * `sort_key` is the selection's own `position`, so the owner's ordering
     * survives — collapsing it would silently reshuffle a curated gallery.
     * Idempotent: an existing row for this (section, item) is left exactly as
     * it is, which also means a re-run cannot undo a later hand-reorder.
     */
    private function pin(string $siteId, string $itemId, int $position): void
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            throw new \RuntimeException("content_selection references missing site {$siteId}.");
        }

        $section = $this->provisioner->ensure($site, 'media');

        $exists = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('pgsql')->table('site.section_items')->insert([
            'id' => (string) Str::uuid(),
            'section_id' => (string) $section->id,
            'item_id' => $itemId,
            'state' => 'pinned',
            'sort_key' => (float) $position,
            'created_at' => now(),
        ]);
    }

    /**
     * All three lanes (parent §9.2). Copied from MediaUploadBackfiller, NOT
     * from PoolController::poolChanged() — that one runs two lanes on purpose,
     * because an interactive pool edit self-heals when the 60s payload TTL
     * expires. A bulk migration has no such luxury; it must be correct the
     * moment it finishes.
     *
     * No CI check enforces this. BuildState's own docblock claims one and it
     * does not exist (parent §9.1), so it is asserted in this service's tests.
     *
     * @param  list<string>  $siteIds
     */
    private function invalidate(array $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            BuildState::bump($siteId);
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->update(['updated_at' => now()]);
            $subdomain = (string) (DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->value('subdomain') ?? '');
            if ($subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
    }
}
