<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 1a §3.7: land the live gallery/content uploads as media-kind content
 * items, through the slice-0b manual lane — NEVER raw writes into content.*.
 *
 * Coord manual:{site_media_uuid}: stable, so a re-run UPDATES (idempotent),
 * and the legacy identifier survives site.site_media's eventual demotion
 * (slice 7). Deliberately does not resurrect user-removed items —
 * writeManualItem() clears only source-level absence; only a person
 * re-adding means "bring it back" (PoolItemCreateController's job).
 *
 * design/documents pools stay put (parent spec §2.1): design assets are
 * brand chrome, documents are downloads — neither is gallery content.
 */
class MediaUploadBackfiller
{
    /** Delivery-tier preference when reading dims/mime for the asset row. */
    private const TIER_ORDER = ['optimized', 'maximized'];

    public function __construct(private readonly ProjectionWriter $writer) {}

    /** @return array{backfilled: int, skipped_not_ready: int, skipped_no_variant: int, failed: int} */
    public function run(bool $dryRun = false, ?string $siteId = null): array
    {
        $result = ['backfilled' => 0, 'skipped_not_ready' => 0, 'skipped_no_variant' => 0, 'failed' => 0];
        $touchedSites = [];

        $rows = SiteMedia::query()
            ->whereIn('pool', SiteMedia::GALLERY_POOLS)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->with(['site', 'mediaVariants'])
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $media) {
            try {
                if ($media->processing_state !== SiteMedia::PROCESSING_STATE_READY) {
                    $result['skipped_not_ready']++;

                    continue;
                }

                $variant = $this->bestVariant($media);
                if ($variant === null) {
                    // A frame that can never resolve would be an empty card.
                    // Counted, not silently dropped — the operator decides.
                    $result['skipped_no_variant']++;

                    continue;
                }

                // Fail LOUDLY on an ownerless site (parent spec §8.2) — a
                // silent skip here is an upload that vanishes from the count.
                $userId = $media->site?->user_id;
                if ($userId === null) {
                    throw new \RuntimeException("site_media {$media->id}: site or owner missing.");
                }

                if ($dryRun) {
                    $result['backfilled']++;

                    continue;
                }

                $headline = trim((string) ($media->caption ?? '')) ?: trim((string) ($media->alt_text ?? ''));

                $projection = [
                    'kind' => 'media',
                    'media' => [[
                        'role' => 'cover',
                        'site_media_id' => (string) $media->id,
                        'alt' => $media->alt_text,
                        'width' => $variant->width === null ? null : (int) $variant->width,
                        'height' => $variant->height === null ? null : (int) $variant->height,
                        'mime_type' => $variant->mime,
                    ]],
                ];
                if ($headline !== '') {
                    $projection['headline'] = $headline;
                }

                $this->writer->writeManualItem((string) $userId, 'manual:'.$media->id, $projection);

                $touchedSites[(string) $media->site_id] = true;
                $result['backfilled']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Media upload backfill failed for one row.', [
                    'site_media_id' => $media->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        if (! $dryRun) {
            $this->invalidate(array_keys($touchedSites));
        }

        return $result;
    }

    private function bestVariant(SiteMedia $media): ?MediaVariant
    {
        $webp = $media->mediaVariants->where('artifact_type', 'webp');
        foreach (self::TIER_ORDER as $tier) {
            $hit = $webp->firstWhere('variant_key', $tier);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $webp->first();
    }

    /**
     * Raw-write seam — all three lanes per touched site (spec §4).
     * writeManualItem() bumped build state per item already; updated_at and
     * the edge purge are the two lanes it deliberately does not own.
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
