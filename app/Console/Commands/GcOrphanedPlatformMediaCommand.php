<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use RuntimeException;
use Throwable;

/**
 * RV-11 (prefix GC backstop): garbage-collect Instagram mirror folders under the
 * `platforms/` R2 prefix whose owning `site.platform_connections` row is gone —
 * regardless of WHY, including causes nothing else in the system reclaims:
 * `DeleteMirroredMediaJob::failed()` only reports and logs (no re-dispatch, no
 * ledger row), and `AccountDeletionService::purge()` hard-deletes the user and
 * lets `platform_connections` cascade at the DB level, so
 * `IntegrationConnectionObserver::deleted()` never fires and the mirror is never
 * reclaimed on account deletion. This is a pure DETECTOR — it never deletes
 * storage itself, only dispatches the existing `DeleteMirroredMediaJob`, which
 * already carries the `platforms/` prefix guard, ShouldBeUnique coalescing, and
 * a 4-try backoff on the `scraping` queue.
 *
 * Direction is DB-first: the live set of owned folders is built from ONE cursor
 * pass over Instagram connections BEFORE any storage call, so a failed/partial
 * DB read aborts before a single object is read (never mistaken for "everything
 * is orphaned"). A folder is live if EITHER the derived token
 * (`platforms/instagram/{created_at timestamp}`, InstagramConnectionSeeder's
 * write path) OR the stored `payload._folder` (InstagramPayload's read
 * boundary) matches — a union, so a future folder-scheme change over-retains
 * rather than over-deletes. This union is also what makes the sweeper safe
 * under the known folder-token collision (two connections created in the same
 * wall-clock second share one folder): the folder is protected while ANY owner
 * is live, because the union just accumulates more keys, never fewer.
 *
 * Soft-deleted rows count as live for `platform_gc_deleted_grace_days` (default
 * 7d) — long enough that a FAILED disconnect-delete is still reclaimable, short
 * enough not to wait out the full 30-day `partna:purge-soft-deletes` retention.
 *
 * Storage layout: platforms/instagram/{token}/{photo.jpg,reel.mp4,reel-cover.jpg,
 * profile.jpg} — exactly 4 path segments. Anything else under `platforms/` is
 * counted and logged, never touched: a namespace allowlist (only `instagram`)
 * and a strict segment-count check, so a second platform starting to mirror
 * media, or an unexpected deeper path, is surfaced instead of silently swept
 * under an ownership model this command does not understand.
 *
 * Uses the LITERAL 'media' disk, not MediaDiskResolver::resolve(): the mirror
 * writer (InstagramConnectionSeeder) and deleter (DeleteMirroredMediaJob) both
 * hard-code 'media', and 'media' is `throw => true` in config/filesystems.php
 * (a failed LIST surfaces instead of silently returning empty, which a
 * throw => false disk the resolver could return would not).
 */
class GcOrphanedPlatformMediaCommand extends Command
{
    protected $signature = 'media:gc-orphaned-platform-media
        {--dry-run : Report what would be reclaimed without dispatching}
        {--limit= : Max orphan folders to reclaim this run (default: config platform_gc_max_per_run)}
        {--allow-empty-db : Proceed even though zero live Instagram connections were found (genuinely empty environment)}';

    protected $description = 'Garbage-collect orphaned Instagram mirror folders under platforms/ in R2 by dispatching DeleteMirroredMediaJob (orphan backstop).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $allowEmptyDb = (bool) $this->option('allow-empty-db');

        $minAgeHours = (int) config('partna.media_orphan_sweep.platform_gc_min_age_hours', 24);
        $graceDays = (int) config('partna.media_orphan_sweep.platform_gc_deleted_grace_days', 7);
        $maxPerRun = (int) config('partna.media_orphan_sweep.platform_gc_max_per_run', 200);
        $maxRatio = (float) config('partna.media_orphan_sweep.platform_gc_max_orphan_ratio', 0.25);
        $anomalyFloor = (int) config('partna.media_orphan_sweep.platform_gc_anomaly_floor', 20);
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : $maxPerRun;

        $ageCutoff = now()->subHours($minAgeHours)->timestamp;
        $graceCutoff = now()->subDays($graceDays);

        // Step 1 — build the live set FIRST, from a single Eloquent cursor pass.
        // Any Throwable aborts here, before a single storage call, so a DB outage
        // can never be mistaken for "every folder is orphaned".
        $live = [];
        $liveRows = 0;
        try {
            IntegrationConnection::withTrashed()
                ->where('platform', Platform::Instagram->value)
                ->select(['id', 'created_at', 'deleted_at', 'payload'])
                ->cursor()
                ->each(function (IntegrationConnection $connection) use (&$live, &$liveRows, $graceCutoff): void {
                    $liveRows++;

                    // A row soft-deleted beyond the grace window has already had its
                    // folder dispatched for deletion (IntegrationConnectionObserver).
                    // Excluding it from the live set is what makes a FAILED delete
                    // reclaimable at all; the grace window protects an in-flight one.
                    if ($connection->deleted_at !== null && $connection->deleted_at->lt($graceCutoff)) {
                        return;
                    }

                    if ($connection->created_at !== null) {
                        $live['platforms/instagram/'.$connection->created_at->timestamp] = true;
                    }

                    $stored = InstagramPayload::fromArray($connection->payload)->folder;
                    if (is_string($stored) && $stored !== '') {
                        $live[trim($stored, '/')] = true;
                    }
                });
        } catch (Throwable $e) {
            report($e);
            Log::error('media.gc_orphaned_platform_media.live_set_failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return self::FAILURE;
        }

        // Step 2 — lazily LIST platforms/ deep. getDriver()->listContents() returns
        // a generator-backed DirectoryListing, so the full prefix is never
        // materialised into memory. Group by token; enforce the namespace
        // allowlist and path-shape strictness inline (never deleted, only counted).
        $disk = Storage::disk('media');
        $groups = [];
        $objectsListed = 0;
        $unrecognisedLayout = 0;
        $unsweptNamespaces = [];

        foreach ($disk->getDriver()->listContents('platforms', true) as $attributes) {
            if (! $attributes instanceof FileAttributes) {
                continue; // directory entries
            }
            $objectsListed++;
            $path = $attributes->path();
            $parts = explode('/', $path);

            if (count($parts) !== 4) {
                // Mirror writes exactly 4 segments. Deeper/shallower is unknown
                // territory — count it, never reclaim it.
                $unrecognisedLayout++;

                continue;
            }

            if ($parts[1] !== 'instagram') {
                $unsweptNamespaces[$parts[1]] = ($unsweptNamespaces[$parts[1]] ?? 0) + 1;

                continue;
            }

            $token = $parts[2];
            $groups[$token]['prefix'] = "platforms/instagram/{$token}";
            $groups[$token]['files'][] = $path;
        }

        $foldersListed = count($groups);

        if ($foldersListed === 0) {
            $this->info('No platform media on disk; nothing to garbage-collect.');
            Log::info('media.gc_orphaned_platform_media.complete', [
                'dry_run' => $dryRun,
                'folders_listed' => 0,
                'objects_listed' => $objectsListed,
                'live_rows' => $liveRows,
                'candidates' => 0,
                'dispatched' => 0,
                'skipped_too_fresh' => 0,
                'unrecognised_layout' => $unrecognisedLayout,
                'unswept_namespaces' => $unsweptNamespaces,
                'capped' => false,
            ]);

            return self::SUCCESS;
        }

        // Empty-live-set refusal: with at least one instagram folder on disk, a
        // truly empty live set is indistinguishable from a misconfigured/wrong DB
        // — refuse rather than treat every folder as orphaned.
        if ($live === [] && ! $allowEmptyDb) {
            $exception = new RuntimeException(
                "media:gc-orphaned-platform-media refused to run: {$foldersListed} instagram folder(s) on disk but zero live rows in site.platform_connections. Pass --allow-empty-db to override for a genuinely empty environment."
            );
            report($exception);
            Log::error('media.gc_orphaned_platform_media.empty_live_set', [
                'folders_listed' => $foldersListed,
            ]);

            return self::FAILURE;
        }

        // Step 3 — classify: unowned folders, then defer the lastModified() round
        // trips (each a storage call) to only those candidates, exactly as
        // media:gc-orphaned-video-artifacts does.
        $candidates = [];
        $skippedTooFresh = 0;

        foreach ($groups as $token => $group) {
            if (isset($live[$group['prefix']])) {
                continue; // owned — derived or stored folder matched a live row.
            }

            $newest = 0;
            foreach ($group['files'] as $file) {
                $newest = max($newest, (int) $disk->lastModified($file));
            }

            if ($newest > $ageCutoff) {
                // Too fresh — possible in-flight mirror write racing this scan's
                // DB snapshot. Not a candidate; not counted toward the ratio guard.
                $skippedTooFresh++;

                continue;
            }

            $candidates[$token] = [
                'prefix' => $group['prefix'],
                'files' => $group['files'],
                'newest' => $newest,
            ];
        }

        $candidateCount = count($candidates);

        // Anomaly abort: a quarter (default) of the whole instagram prefix going
        // orphan in one run is not a plausible orphan population — it's a broken
        // live-set build. Dispatch NOTHING. Floor guards a tiny dev bucket where
        // 1-of-2 folders would otherwise always trip the ratio.
        if ($candidateCount >= $anomalyFloor && ($candidateCount / $foldersListed) > $maxRatio) {
            $exception = new RuntimeException(
                "media:gc-orphaned-platform-media aborted: {$candidateCount}/{$foldersListed} instagram folders classified as orphan, exceeding the {$maxRatio} ratio guard. Dispatching nothing."
            );
            report($exception);
            Log::error('media.gc_orphaned_platform_media.orphan_ratio_exceeded', [
                'candidates' => $candidateCount,
                'folders_listed' => $foldersListed,
                'max_ratio' => $maxRatio,
                'anomaly_floor' => $anomalyFloor,
            ]);

            return self::FAILURE;
        }

        // Step 4 — cap + dispatch (or preview, under --dry-run).
        $capped = $candidateCount > $limit;
        $toProcess = array_slice($candidates, 0, $limit, true);
        $dispatched = 0;

        foreach ($toProcess as $candidate) {
            $prefix = $candidate['prefix'];
            $objectCount = count($candidate['files']);

            Log::warning('media.orphan_platform_media_gc', [
                'folder' => $prefix,
                'object_count' => $objectCount,
                'newest_object_at' => $candidate['newest'] > 0 ? Carbon::createFromTimestamp($candidate['newest'])->toIso8601String() : null,
                'dry_run' => $dryRun,
            ]);

            if ($dryRun) {
                $this->line("[dry-run] would reclaim {$prefix} ({$objectCount} object(s))");

                continue;
            }

            DeleteMirroredMediaJob::dispatch($prefix);
            $dispatched++;
            $this->line("Dispatched reclaim for {$prefix} ({$objectCount} object(s))");
        }

        if ($capped) {
            Log::warning('media.orphan_platform_media_gc.capped', [
                'limit' => $limit,
                'candidates' => $candidateCount,
            ]);
        }

        $this->info($dryRun
            ? "[dry-run] Would dispatch {$candidateCount} reclaim job(s) for orphaned platform media."
            : "Dispatched {$dispatched} reclaim job(s) for orphaned platform media."
        );

        Log::info('media.gc_orphaned_platform_media.complete', [
            'dry_run' => $dryRun,
            'folders_listed' => $foldersListed,
            'objects_listed' => $objectsListed,
            'live_rows' => $liveRows,
            'candidates' => $candidateCount,
            'dispatched' => $dispatched,
            'skipped_too_fresh' => $skippedTooFresh,
            'unrecognised_layout' => $unrecognisedLayout,
            'unswept_namespaces' => $unsweptNamespaces,
            'capped' => $capped,
        ]);

        return self::SUCCESS;
    }
}
