<?php

namespace App\Console\Commands;

use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImagePaletteExtractor;
use App\Services\Media\MediaDiskResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

// Backfill sweep (#76 Part A): extract dominant-colour + palette metadata for
// EXISTING gallery/content images that predate palette extraction (or where a
// prior extraction failed) so IdentityEvidence::mediaPalette() → the
// ImageryPaletteFactor lights up for current users. New uploads get their
// palette inline in ImageVariantService; this closes the gap for the back
// catalogue. Idempotent (skips rows that already have a palette), chunked, and
// per-row failure-tolerant — one unreadable original never aborts the sweep.
//
//   --dry-run    report how many WOULD be filled, write nothing.
//   --site=<id>  limit to a single site.
//   --limit=<n>  cap the number of rows processed this run (0 = no cap).
class BackfillMediaPaletteCommand extends Command
{
    protected $signature = 'media:backfill-palette
        {--dry-run : Report what would change without writing anything}
        {--site= : Limit to a single site id}
        {--limit=0 : Cap rows processed this run (0 = no cap)}';

    protected $description = 'Extract + store colour-palette metadata for existing gallery images (backfills the ImageryPaletteFactor source)';

    // One-off manual backfill, not scheduled. Per-row work is a network fetch
    // (stream the original from R2/S3) plus image decode + palette extraction —
    // heavier than a DB-only sweep — and the backlog is unbounded unless
    // --limit caps it, so this gets a generous hour-long ceiling.
    // Documentation only — Illuminate\Console\Command never reads $timeout (unlike
    // the enforced, identically-named property on a ShouldQueue job).
    protected $timeout = 3600;

    public function handle(ImagePaletteExtractor $extractor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $site = $this->option('site');
        $limit = max(0, (int) $this->option('limit'));

        // Only READY images in a gallery/content pool with no palette yet are
        // candidates — failed/pending rows have no reliable original to read.
        $query = SiteMedia::query()
            ->whereIn('pool', SiteMedia::GALLERY_POOLS)
            ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->whereNull('deleted_at')
            ->whereNotNull('path')
            ->whereNull('palette')
            ->when(is_string($site) && trim($site) !== '', fn ($q) => $q->where('site_id', trim($site)))
            ->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No gallery images need a palette backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s%d image(s) without a palette%s.',
            $dryRun ? '[dry-run] ' : '',
            $total,
            $limit > 0 ? " (processing up to {$limit})" : '',
        ));

        $disk = Storage::disk(MediaDiskResolver::resolve());
        $processed = 0;
        $filled = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($query->cursor() as $media) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            try {
                $palette = $this->extractForRow($extractor, $disk, (string) $media->path);
                if ($palette === null) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    SiteMedia::query()
                        ->where('id', $media->id)
                        ->whereNull('deleted_at')
                        ->update([
                            'dominant_color' => $palette['dominant'],
                            'palette' => json_encode($palette),
                        ]);
                }
                $filled++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->warn("  ! {$media->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            '%sDone. %d processed, %d %s, %d skipped (no usable palette), %d failed.',
            $dryRun ? '[dry-run] ' : '',
            $processed,
            $filled,
            $dryRun ? 'would fill' : 'filled',
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Stream the original to a local temp file and extract a palette from it.
     * Returns null when the original is missing or yields no usable palette.
     *
     * @return array{dominant: string, colors: list<string>, saturation: float, warm: bool}|null
     */
    private function extractForRow(ImagePaletteExtractor $extractor, Filesystem $disk, string $path): ?array
    {
        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sidest_palette_');
        if ($tmp === false) {
            return null;
        }

        try {
            $stream = $disk->readStream($path);
            if (! $stream) {
                return null;
            }
            $dest = fopen($tmp, 'wb');
            if (! $dest) {
                fclose($stream);

                return null;
            }
            stream_copy_to_stream($stream, $dest);
            fclose($stream);
            fclose($dest);

            return $extractor->fromPath($tmp);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
