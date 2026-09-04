<?php

namespace App\Console\Commands\Media;

use App\Jobs\Media\MirrorMediaAssetJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-copy owned media bytes into the CURRENT media bucket.
 *
 * Built for the 2026-09-04 bucket move (Laravel Cloud managed R2 → our own
 * Oceania bucket behind media.partna.au, no object copy): every mirrored
 * asset's storage_path points at an object that no longer exists on the new
 * disk. Clearing the path and re-dispatching is the same "no bytes yet" state
 * a fresh projection leaves, so nothing else has to know the bucket changed.
 * Also the cheapest way to backfill the thumbnail tier on rows mirrored
 * before it existed.
 *
 * Owned rows only (mirror_eligible, no site_media_id): uploads live in the
 * variant pipeline and borrowed media must never be copied.
 */
class RemirrorMediaCommand extends Command
{
    protected $signature = 'media:remirror
        {--user= : Only this user id}
        {--include-failed : Also reset assets that exhausted their mirror attempts}
        {--dry-run : Count only; write and dispatch nothing}';

    protected $description = 'Clear storage_path on owned mirrored assets and queue a fresh mirror for each (bucket move / thumbnail backfill)';

    public function handle(): int
    {
        $query = DB::connection('pgsql')->table('content.media_assets')
            ->where('mirror_eligible', true)
            ->whereNull('site_media_id')
            ->whereNotNull('source_url')
            ->where(fn ($q) => $q
                ->whereNotNull('storage_path')
                ->when($this->option('include-failed'), fn ($q) => $q->orWhere('mirror_attempts', '>', 0)));

        if (is_string($this->option('user')) && $this->option('user') !== '') {
            $query->where('user_id', $this->option('user'));
        }

        $rows = $query->orderBy('created_at')->get(['id', 'user_id', 'source_url', 'mime_type']);
        $this->info(sprintf('%d asset(s) to re-mirror', $rows->count()));

        if ($this->option('dry-run') || $rows->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($rows->chunk(200) as $chunk) {
            DB::connection('pgsql')->table('content.media_assets')
                ->whereIn('id', $chunk->pluck('id')->all())
                ->update([
                    'storage_path' => null,
                    'mirror_attempts' => 0,
                    'mirror_last_attempt_at' => null,
                    'mirror_last_reason' => null,
                ]);

            foreach ($chunk as $row) {
                MirrorMediaAssetJob::dispatch(
                    (string) $row->user_id,
                    (string) $row->id,
                    (string) $row->source_url,
                    video: str_starts_with((string) ($row->mime_type ?? ''), 'video/'),
                );
            }
        }

        $this->info('Dispatched.');

        return self::SUCCESS;
    }
}
