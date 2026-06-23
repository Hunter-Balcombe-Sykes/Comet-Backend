<?php

namespace App\Console\Commands;

use App\Models\Core\Site\SiteMedia;
use App\Services\Media\MediaDiskResolver;
use App\Services\Media\VideoVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * P1-08 (prefix GC backstop): garbage-collect video R2 objects that no longer
 * have a backing site_media row — regardless of WHY they were orphaned (account
 * purge whose cleanup job failed, an aborted/failed upload, a crashed transcode).
 *
 * Where the ledger sweep is precise and deletion-driven, this is a blunt,
 * unbounded scan: list the whole videos/ prefix, group by media id, and delete
 * any group with no site_media row (withTrashed) once it is older than the age
 * guard. The age guard is the safety margin against deleting files for a row
 * that was created but not yet visible to this scan (a just-started upload).
 *
 * Heavier than the ledger sweep (a full prefix LIST), so it runs less often.
 * Storage layout: videos/{userId}/{mediaId}/{artifact}.
 */
class GcOrphanedVideoArtifactsCommand extends Command
{
    protected $signature = 'media:gc-orphaned-video-artifacts';

    protected $description = 'Garbage-collect video R2 objects whose site_media row no longer exists (orphan backstop).';

    public function handle(VideoVariantService $videos): int
    {
        $minAgeHours = (int) config('partna.media_orphan_sweep.gc_min_age_hours', 24);
        $cutoff = now()->subHours($minAgeHours)->timestamp;

        $disk = Storage::disk(MediaDiskResolver::resolve());

        // Group every object under videos/ by its media id. Defer the per-object
        // lastModified() calls (each is a storage round-trip) until after the row
        // check — only orphan candidates, which are rare, need the age guard.
        // getDriver()->listContents() returns a lazy DirectoryListing (generator-
        // backed) so the full prefix list is never materialised into memory.
        $groups = [];
        foreach ($disk->getDriver()->listContents('videos', true) as $attributes) {
            if (! $attributes instanceof FileAttributes) {
                continue; // skip directory entries
            }
            $file = $attributes->path();
            $parts = explode('/', $file);
            if (count($parts) < 4) {
                continue; // not videos/{user}/{media}/{file} — leave it alone.
            }

            $mediaId = $parts[2];
            $groups[$mediaId]['prefix'] = "{$parts[0]}/{$parts[1]}/{$parts[2]}";
            $groups[$mediaId]['files'][] = $file;
        }

        if ($groups === []) {
            $this->info('No video artifacts on disk; nothing to garbage-collect.');

            return self::SUCCESS;
        }

        // A single query resolves which media ids still have a row (withTrashed:
        // a soft-deleted row means a normal delete whose async cleanup is still
        // in flight — not an orphan).
        $live = SiteMedia::withTrashed()
            ->whereIn('id', array_keys($groups))
            ->pluck('id')
            ->all();
        $live = array_flip($live);

        $deletedGroups = 0;
        $deletedObjects = 0;

        foreach ($groups as $mediaId => $group) {
            if (isset($live[$mediaId])) {
                continue; // still referenced.
            }

            // Orphan candidate — now (and only now) pay for the age guard.
            $newest = 0;
            foreach ($group['files'] as $file) {
                $newest = max($newest, (int) $disk->lastModified($file));
            }
            if ($newest > $cutoff) {
                continue; // too fresh — within the age guard (possible in-flight upload).
            }

            $result = $videos->purgeStoragePrefix($group['prefix']);
            if ($result['deleted'] > 0) {
                $deletedGroups++;
                $deletedObjects += $result['deleted'];
                Log::warning('media.orphan_video_artifacts_gc', [
                    'base_prefix' => $result['basePrefix'],
                    'deleted' => $result['deleted'],
                ]);
            }
        }

        $this->info("Garbage-collected {$deletedObjects} object(s) across {$deletedGroups} orphaned video item(s).");
        Log::info('media.gc_orphaned_video_artifacts.complete', [
            'deleted_objects' => $deletedObjects,
            'deleted_groups' => $deletedGroups,
        ]);

        return self::SUCCESS;
    }
}
