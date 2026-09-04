<?php

namespace App\Console\Commands;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Services\Content\ManualMediaWriter;
use App\Site\Documents\BuildState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Item 5 backfill (2026-09-01): legacy GalleryAutoGrabber rows predate the
 * upload→pool bridge, so their bytes exist with no media-pool item — invisible
 * on /media and on the sitepage. Mint the missing items through the SAME
 * manual lane the live bridge uses, under the 'website:' provenance coord.
 *
 * Discriminator: NO anchor under any manual namespace (website:, upload:, or
 * the slice-1a backfill's manual:). The upload pipeline stores no
 * original_filename and the pool column stops discriminating once migration
 * 20260901200000 folds gallery rows into POOL_CONTENT, so absence-of-bridge
 * is the one marker that survives the flip. It is also honest per the
 * verified 2026-09-01 state: every un-itemed gallery-lane row IS a website
 * grab (hand gallery uploads all carry slice-1a 'manual:' items). The one
 * mis-tag this can produce — a live content upload whose best-effort bridge
 * faulted gets healed under 'website:' instead of 'upload:' — still fixes
 * the actual invisibility, and the delete lane probes both namespaces.
 *
 * f_published is dated at the row's created_at — NOT now() like the live
 * bridge — so a months-old grab does not outrank the owner's newer content
 * in newest order (X5: dated beats undated, then date decides).
 */
class BackfillWebsiteGrabMediaCommand extends Command
{
    protected $signature = 'content:backfill-website-grab-media
        {--dry-run : Report counts without writing}
        {--site= : Only this site id}';

    protected $description = 'Mint website-provenance media-pool items for legacy GalleryAutoGrabber rows (Item 5)';

    /** Delivery-tier preference when reading dims/mime for the item entry — mirrors MediaUploadBackfiller. */
    private const TIER_ORDER = ['optimized', 'maximized'];

    public function __construct(private readonly ProjectionWriter $writer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $siteId = $this->option('site') ?: null;

        $result = ['minted' => 0, 'already_bridged' => 0, 'skipped_not_ready' => 0, 'skipped_no_variant' => 0, 'failed' => 0];
        $touchedSites = [];

        // GALLERY_POOLS, not POOL_CONTENT: tolerant of running before the
        // pool-flip migration as well as after — the anchor-absence check
        // below is the real discriminator either way.
        $rows = SiteMedia::query()
            ->whereIn('usage', SiteMedia::LISTABLE_USAGES)
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

                // Fail LOUDLY on an ownerless site (slice-1a §8.2 rule) — a
                // silent skip here is a grab that vanishes from the count.
                $userId = $media->site?->user_id;
                if ($userId === null) {
                    throw new \RuntimeException("site_media {$media->id}: site or owner missing.");
                }

                if ($this->alreadyBridged((string) $userId, (string) $media->id)) {
                    $result['already_bridged']++;

                    continue;
                }

                if ($dry) {
                    $result['minted']++;

                    continue;
                }

                $this->writer->writeManualItem((string) $userId, ManualMediaWriter::ORIGIN_WEBSITE.':'.$media->id, [
                    'kind' => 'media',
                    'facets' => [
                        'f_published' => ['published_from' => $media->created_at->toIso8601String()],
                    ],
                    'media' => [[
                        'role' => 'cover',
                        'site_media_id' => (string) $media->id,
                        'alt' => $media->alt_text,
                        'width' => $variant->width === null ? null : (int) $variant->width,
                        'height' => $variant->height === null ? null : (int) $variant->height,
                        'mime_type' => $variant->mime,
                    ]],
                ]);

                $touchedSites[(string) $media->site_id] = true;
                $result['minted']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Website-grab media backfill failed for one row.', [
                    'site_media_id' => $media->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        if (! $dry) {
            $this->invalidate(array_keys($touchedSites));
        }

        $verb = $dry ? 'would mint' : 'minted';
        $this->info("Website grabs: {$verb} {$result['minted']}, "
            ."{$result['already_bridged']} already bridged, "
            ."skipped {$result['skipped_not_ready']} not-ready, "
            ."{$result['skipped_no_variant']} without a webp variant"
            .($result['failed'] > 0 ? ", {$result['failed']} FAILED" : '.'));

        if ($result['failed'] > 0) {
            $this->warn('Failures are reported and logged with site_media ids. Fix and re-run — the lane is idempotent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * A row already carrying an item under ANY manual namespace must not be
     * minted again — a second coord for the same bytes would leave two
     * anchors where one lane suffices, and the existing item already serves
     * the pool.
     */
    private function alreadyBridged(string $userId, string $mediaId): bool
    {
        return DB::connection('pgsql')->table('content.item_anchors')
            ->where('user_id', $userId)
            ->whereIn('coord', [
                ManualMediaWriter::ORIGIN_WEBSITE.':'.$mediaId,
                ManualMediaWriter::ORIGIN_UPLOAD.':'.$mediaId,
                'manual:'.$mediaId,
            ])
            ->exists();
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
     * Raw-write seam — all three lanes per touched site, same shape as
     * MediaUploadBackfiller. writeManualItem() bumped build state per item
     * already; updated_at and the edge purge are the two lanes it
     * deliberately does not own.
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
