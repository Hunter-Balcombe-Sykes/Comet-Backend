<?php

namespace App\Services\Platforms;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualEventWriter;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolSectionProvisioner;
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

    /** Handled-without-write count (tombstoned re-scans) — the importer
     * surfaces it in the run detail so suppression is read, not absorbed. */
    private int $tombstonedThisRun = 0;

    public function tombstonedThisRun(): int
    {
        return $this->tombstonedThisRun;
    }

    public function __construct(
        private readonly MediaPageReader $reader,
        private readonly ProjectionWriter $writer,
    ) {}

    /**
     * Seed one media pool item from a scanned or pasted link. Returns the
     * canonical item URL as the "written" signal (there is no resource_id —
     * an item is not a connection), null when nothing was written and the
     * caller should card the link instead.
     *
     * Origin 'paste' is a DIRECT request (same doctrine as
     * RoutingContext::isDirectRequest): it wins the item tombstone — a
     * person pasting the link again means "bring it back" — and un-deletes,
     * exactly as the pool hand-add lane does. Every other origin respects
     * the tombstone. $pin is the paste lane's "the owner asked for this on
     * their site"; scans never pin.
     */
    public function seedItem(User $user, string $url, ?string $origin = null, bool $pin = false): ?string
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

        // Never resurrect FROM A SCAN: the owner removed this exact item
        // before, and only a person's direct paste means "bring it back".
        $removed = $origin === 'paste' ? false : DB::connection('pgsql')->table('content.items as i')
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
            $this->tombstonedThisRun++;

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
            $itemId = $this->writer->writeManualItem((string) $user->id, $coord, $projection);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('media_seeder.pool_write_failed', [
                'user_id' => (string) $user->id, 'platform' => $read['platform'], 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($origin === 'paste') {
            // A direct paste un-deletes, exactly as the pool hand-add lane
            // does (its docblock owns the reasoning; scans never reach here).
            DB::connection('pgsql')->table('content.items')
                ->where('id', $itemId)
                ->where('user_id', (string) $user->id)
                ->whereNotNull('removed_at')
                ->update(['removed_at' => null, 'updated_at' => now()]);
        }

        if ($pin) {
            $this->pin($user, $itemId);
        }
        // Scan lanes: deliberately NO pin and NO removed_at reset — the
        // class note owns the reasoning.
        $this->seededThisRun++;

        return $canonical;
    }

    /**
     * The paste lane's pin — same find-or-new FORCED-state write every other
     * pin site uses (ManualEventWriter::pin owns the reasoning), aimed at
     * the item's OWN pool section (video → watch, track → listen…).
     */
    private function pin(User $user, string $itemId): void
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return;
        }
        $kind = DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('kind');
        $pool = PoolRegistry::poolForKind((string) $kind);
        if ($pool === null) {
            return;
        }

        $section = app(PoolSectionProvisioner::class)->ensure($site, $pool);
        $pin = SectionItem::query()
            ->where('section_id', (string) $section->id)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $pin->section_id = (string) $section->id;
        $pin->item_id = $itemId;
        $pin->state = SectionItem::STATE_PINNED;
        $pin->sort_key ??= 1.0 + (float) (SectionItem::query()
            ->where('section_id', (string) $section->id)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key') ?? 0.0);
        if (! $pin->exists) {
            $pin->created_at = now();
        }
        $pin->save();

        SiteCacheLanes::bust([(string) $site->id]);
    }
}
