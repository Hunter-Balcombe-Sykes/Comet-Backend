<?php

namespace App\Jobs\Content;

use App\Models\Content\Item;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Content\ItemMerger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A.6(c): the bio scrape seeds a video/track link as a MANUAL library item
 * before the platform's own ingest run lands the same content as a real
 * ingested item — two rows for one thing. After the connect fetch, fold
 * each manual item whose f_link URL matches an ingested twin INTO the
 * ingested row (the ingested side wins whatever its age — it has facets,
 * media and a live source), pins moving with it and the origin tag kept.
 *
 * The ingest run is asynchronous behind the fetch, so the job releases
 * itself until the connection's source has items, bounded by $tries.
 */
class ReparentBioItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 180, 240];

    public int $timeout = 120;

    public function __construct(public readonly string $connectionId) {}

    public function handle(ItemMerger $merger): void
    {
        $connection = IntegrationConnection::query()->find($this->connectionId);
        if ($connection === null) {
            return;
        }
        $user = User::query()->find($connection->user_id);
        if ($user === null) {
            return;
        }

        $sourceId = DB::table('content.sources')
            ->where('connection_id', $connection->id)
            ->where('kind', 'connection')
            ->value('id');
        $manualSourceId = DB::table('content.sources')
            ->where('user_id', $user->id)
            ->where('kind', 'manual')
            ->value('id');
        if ($manualSourceId === null) {
            return; // nothing bio-seeded, nothing to fold
        }
        if ($sourceId === null) {
            $this->waitForIngest();

            return;
        }

        $ingestedCount = DB::table('content.source_items')
            ->where('source_id', $sourceId)
            ->whereNotNull('item_id')
            ->whereNull('removed_at')
            ->count();
        if ($ingestedCount === 0) {
            $this->waitForIngest();

            return;
        }

        // Manual items whose stored URL an ingested twin also carries.
        $pairs = DB::table('content.f_link as mfl')
            ->join('content.source_items as msi', function ($j) use ($manualSourceId) {
                $j->on('msi.item_id', '=', 'mfl.item_id')->where('msi.source_id', '=', $manualSourceId);
            })
            ->join('content.f_link as ifl', 'ifl.url', '=', 'mfl.url')
            ->join('content.source_items as isi', function ($j) use ($sourceId) {
                $j->on('isi.item_id', '=', 'ifl.item_id')->where('isi.source_id', '=', $sourceId);
            })
            ->where('mfl.source_id', $manualSourceId)
            ->whereColumn('mfl.item_id', '!=', 'ifl.item_id')
            ->whereNull('isi.removed_at')
            ->distinct()
            ->get(['mfl.item_id as manual_id', 'ifl.item_id as ingested_id']);

        $folded = 0;
        foreach ($pairs as $pair) {
            $manual = Item::query()->whereKey($pair->manual_id)->where('user_id', $user->id)->first();
            $ingested = Item::query()->whereKey($pair->ingested_id)->where('user_id', $user->id)->first();
            if ($manual === null || $ingested === null) {
                continue;
            }

            // The origin tag ('scrape'|'manual') must survive the fold —
            // ProjectionWriter replaces tag sets per write, and the sweep
            // that retires scrape-seeded cards keys on it.
            $originTag = DB::table('content.item_tags')
                ->where('item_id', $manual->id)
                ->where('tag_type', 'link_origin')
                ->first();

            $merger->fold($user, $ingested, $manual, 'bio item reparented onto its ingested twin');
            $folded++;

            if ($originTag !== null) {
                $hasTag = DB::table('content.item_tags')
                    ->where('item_id', $ingested->id)
                    ->where('tag_type', 'link_origin')
                    ->exists();
                if (! $hasTag) {
                    DB::table('content.item_tags')->insert([
                        'id' => (string) Str::uuid(),
                        'item_id' => $ingested->id,
                        'source_id' => $originTag->source_id,
                        'tag' => $originTag->tag,
                        'tag_type' => 'link_origin',
                    ]);
                }
            }
        }

        if ($folded > 0) {
            Log::info('content.reparent_bio_items', [
                'user_id' => (string) $user->id,
                'connection_id' => (string) $connection->id,
                'folded' => $folded,
            ]);
        }
    }

    /** Ingest has not landed yet: retry on the backoff schedule, give up quietly at the cap. */
    private function waitForIngest(): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 240);
        }
    }
}
