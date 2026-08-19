<?php

namespace App\Services\Platforms;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\User\User;
use App\Services\Content\ManualEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The media twin of EventsSeeder's standalone arm (T6, 2026-08-20): a scanned
// VIDEO/TRACK/RELEASE/EPISODE link becomes a real watch/listen-pool item —
// platform-canonical URL, real kind from the URL grammar, the page's own
// title and cover via MediaPageReader's oEmbed/OG read — exactly what the
// paste lane writes through PoolItemCreateController's media arm.
//
// Two deliberate differences from events:
//  - NOT auto-pinned. Scan-found media lands in the LIBRARY only (owner,
//    2026-08-20) — a bio full of "watch this" links must not take over the
//    selection. Events pin because a dated list self-retires; media doesn't.
//  - Tombstone-safe at the ITEM level: a removed item's coord is never
//    resurrected by a re-scan. (The hand-add lane un-deletes on purpose —
//    a person re-typing the link means "bring it back"; a scan doesn't.)
class MediaSeeder
{
    /**
     * Per-RUN cap on seeded media items, so one Linktree cannot flood the
     * Watch pool. Generous from birth (T9, owner 2026-08-20: 30, not
     * events' 10). Instance state: importers hold one instance per run, and
     * queue jobs get a fresh container per job.
     */
    public const MAX_ITEMS_PER_RUN = 30;

    private int $seededThisRun = 0;

    public function __construct(
        private readonly MediaPageReader $reader,
        private readonly ProjectionWriter $writer,
    ) {}

    /**
     * Seed one media pool item from a scanned link. Returns the canonical
     * item URL as the "written" signal (there is no resource_id — an item is
     * not a connection), null when nothing was written and the caller should
     * card the link instead.
     */
    public function seedItem(User $user, string $url, ?string $origin = null): ?string
    {
        if ($this->seededThisRun >= self::MAX_ITEMS_PER_RUN) {
            Log::info('media_seeder.run_cap', ['user_id' => (string) $user->id]);

            return null;
        }

        // Grammar first (pure), then the page read for the words and cover. A
        // failed read cards the link — an item titled by its host is worse
        // than an honest card (same rule the paste lane applies).
        $read = $this->reader->read($url);
        if ($read === null) {
            return null;
        }

        $canonical = $read['canonical'];
        $coord = ManualEventWriter::coordFor($canonical);

        // Never resurrect: the owner removed this exact item before. The
        // hand-add lane's un-delete is a person saying "bring it back" — a
        // re-scan is not.
        $removed = DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as csi', 'csi.item_id', '=', 'i.id')
            ->join('content.sources as cs', function ($join) {
                $join->on('cs.id', '=', 'csi.source_id')->where('cs.kind', '=', 'manual');
            })
            ->where('i.user_id', (string) $user->id)
            ->where('csi.coord', $coord)
            ->whereNotNull('i.removed_at')
            ->exists();
        if ($removed) {
            // Returned as HANDLED (the canonical), not null: null sends the
            // caller to its card write, and a link card for a removed item
            // resurrects it in another pool. The URL's home is the (removed)
            // item; removed stays removed, and nothing else may carry it.
            Log::info('media_seeder.tombstoned', ['user_id' => (string) $user->id, 'coord' => $coord]);

            return $canonical;
        }

        $projection = [
            'kind' => $read['kind'],
            'headline' => $read['title'],
            'facets' => [
                'f_text' => ['headline' => $read['title']],
                'f_link' => ['url' => $canonical],
            ],
            // 'found in your bio link', not 'added by you' — same typed
            // origin tag the events lane rides (tag_type 'origin').
            'tags' => $origin === null || $origin === '' ? [] : [['tag' => $origin, 'tag_type' => 'origin']],
        ];
        if ($read['thumbnail'] !== null && $read['thumbnail'] !== '') {
            $projection['media'] = [['role' => 'cover', 'url' => $read['thumbnail']]];
        }

        try {
            $this->writer->writeManualItem((string) $user->id, $coord, $projection);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('media_seeder.pool_write_failed', [
                'user_id' => (string) $user->id, 'platform' => $read['platform'], 'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Deliberately NO pin and NO removed_at reset — see the class note.
        $this->seededThisRun++;

        return $canonical;
    }
}
