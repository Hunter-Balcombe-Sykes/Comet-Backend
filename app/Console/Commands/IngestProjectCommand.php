<?php

namespace App\Console\Commands;

use App\Ingest\Projection\ProjectionWriter;
use App\Ingest\Projection\ProjectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

// Re-derives content rows from the landed record log without re-fetching a
// byte (the whole point of content-addressed landing — plan §4, and the
// command Projector's contract names). Idempotent: a plain run over unchanged
// records writes the same rows and creates nothing new. --rebuild first drops
// the projection-derived rows (facets, media links, offers, tags, identity
// keys) so a projector whose OUTPUT SHAPE changed leaves no orphans behind;
// items, anchors, pins and overrides are never dropped — those carry user
// state a rebuild must survive.
class IngestProjectCommand extends Command
{
    protected $signature = 'ingest:project
        {--rebuild : Drop projection-derived rows first, then re-derive}
        {--user= : Only sources belonging to this user id}
        {--source= : Only this ingest source id}
        {--dry-run : Report what would be projected without writing}';

    protected $description = 'Project landed record versions into content items and facets.';

    public function handle(ProjectionWriter $writer): int
    {
        $totals = ['streams' => 0, 'projected' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
        $seen = 0;
        $chunkSize = (int) config('partna.ingest.projection_source_chunk', 200);

        // chunkById(id), NOT ->get()/cursor(): pdo_pgsql has no unbuffered
        // fetch mode, so cursor() would still buffer the whole result set in
        // libpq while pinning one open result set for the entire multi-hour
        // run. chunkById bounds both.
        //
        // Deliberately NO ->orderBy('created_at') here (there was one; it was
        // removed). chunkById()'s forPageAfterId() only strips orders on the
        // `id` column (Builder::removeExistingOrdersFor), so a surviving
        // `created_at` order would sort BEFORE the appended `orderBy('id')`,
        // making the emitted SQL `ORDER BY created_at ASC, id ASC` while the
        // keyset predicate is `WHERE id > ?`. That predicate does not
        // partition a result set ordered primarily by a different column —
        // rows whose id sorts below the cursor but whose created_at sorts
        // later are skipped permanently, with no error. Cross-source merge
        // resolution is priority-based (ProjectionWriter::projectStream), not
        // arrival-order-based, so walking by id instead of created_at changes
        // nothing observable except console line order.
        DB::table('ingest.sources')
            ->when($this->option('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->chunkById($chunkSize, function ($sources) use ($writer, &$totals, &$seen): void {
                $seen += $sources->count();

                // Streams pre-fetch is scoped to THIS chunk, not all matching
                // sources — a global whereIn over every source id would
                // reintroduce the unbounded load this chunking exists to
                // remove. Kills the N+1 (one query per chunk instead of one
                // per source) without re-materialising the whole table.
                $streamsBySource = DB::table('ingest.streams')
                    ->whereIn('source_id', $sources->pluck('id')->all())
                    ->get(['id', 'source_id', 'stream_name'])
                    ->groupBy('source_id');

                foreach ($sources as $source) {
                    $streams = $streamsBySource->get($source->id, collect());

                    foreach ($streams as $stream) {
                        if (! ProjectorRegistry::has((string) $source->source_key, (string) $stream->stream_name)) {
                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $count = DB::table('ingest.record_state')
                                ->where('stream_id', $stream->id)
                                ->whereNull('tombstoned_at')
                                ->count();
                            $this->line("would project: {$source->source_key}/{$stream->stream_name} ({$count} live records)");
                            $totals['streams']++;
                            $totals['projected'] += $count;

                            continue;
                        }

                        if ($this->option('rebuild')) {
                            $this->dropDerivedRows($source->connection_id !== null ? (string) $source->connection_id : null, (string) $stream->id);
                        }

                        try {
                            // No fetchedInRunId: this command re-derives
                            // from the landed log without fetching a byte, so it
                            // has no ingestion to report and must not restate
                            // any source item's ingest scope. Restamping here
                            // with the source's PRESENT selection is how a
                            // storewide corpus would silently become one
                            // employee's — see ProjectionWriter::projectStream().
                            $result = $writer->projectStream((array) $source, (string) $stream->id, (string) $stream->stream_name);
                        } catch (Throwable $e) {
                            report($e);
                            $totals['failed']++;
                            $this->error("failed: {$source->source_key}/{$stream->stream_name} — {$e->getMessage()}");

                            continue;
                        }

                        $totals['streams']++;
                        if ($result['status'] === 'skipped') {
                            $totals['skipped']++;
                            $this->line("skipped: {$source->source_key}/{$stream->stream_name} ({$result['reason']})");

                            continue;
                        }

                        $totals['projected'] += $result['projected'] ?? 0;
                        $totals['removed'] += $result['removed'] ?? 0;
                        $this->line(sprintf(
                            'projected: %s/%s — %d record(s) into %d item(s), %d retired',
                            $source->source_key,
                            $stream->stream_name,
                            $result['projected'] ?? 0,
                            $result['items'] ?? 0,
                            $result['removed'] ?? 0,
                        ));
                    }
                }
            });

        if ($seen === 0) {
            $this->info('No ingest sources match.');

            return self::SUCCESS;
        }

        $this->table(['streams', 'records projected', 'retired', 'skipped', 'failed'], [[
            $totals['streams'], $totals['projected'], $totals['removed'], $totals['skipped'], $totals['failed'],
        ]]);

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Remove what projection alone derived for this stream's source-items so
     * a changed projector re-emits from scratch. Everything dropped here is
     * re-derivable from the record log; nothing user-owned is touched.
     *
     * Takes the connection id directly — the caller already has it on the
     * `ingest.sources` row it's iterating, so re-querying it here (as this
     * used to) was a redundant per-stream N+1 under --rebuild.
     *
     * SCALE-14: the id lists are chunked into the DELETEs rather than passed
     * whole. A source with 50k source_items used to serialise 50k uuid binds
     * into each of the 18 DELETEs below (~900k binds per stream, over
     * Postgres' 65,535-per-statement limit before the memory cost). At the
     * shared projection_write_chunk of 500 each DELETE carries 500 uuids + 1
     * source_id, and a chunk is 18 such statements.
     *
     * Deliberately NOT a transaction. One wrapping 18 x ceil(n/500) DELETEs
     * across every facet table is a far worse lock story than the partial-drop
     * risk it removes, and a mid-loop failure is recoverable: everything
     * dropped here is re-derivable from the record log, and --rebuild
     * re-projects this same stream seconds later.
     *
     * Rejected: replacing the `item_id IN (...)` lists with a correlated
     * subquery over content.source_items. It would delete the PHP
     * materialisation entirely and collapse to one statement per table — but
     * it also changes the target from "the ids we plucked" to "whatever the
     * subquery sees now", a different snapshot, and projectStream() rewrites
     * content.source_items moments later. Chunking is the conservative choice.
     */
    private function dropDerivedRows(?string $connectionId, string $streamId): void
    {
        if ($connectionId === null) {
            return;
        }
        $contentSourceId = DB::table('content.sources')->where('connection_id', $connectionId)->value('id');
        if ($contentSourceId === null) {
            return;
        }

        // Same key ProjectionWriter::writeChunk() reads — one knob for every
        // bind list in the projection stage, not a second one to keep in sync.
        $chunk = (int) config('partna.ingest.projection_write_chunk', 500);
        $chunk = $chunk > 0 ? $chunk : 500;

        $sourceItemIds = DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('stream_id', $streamId)
            ->pluck('id')
            ->all();
        if ($sourceItemIds === []) {
            return;
        }

        foreach (array_chunk($sourceItemIds, $chunk) as $idChunk) {
            DB::table('content.identity_keys')->whereIn('source_item_id', $idChunk)->delete();
        }

        // ->values(): unique() preserves the original keys, and while whereIn
        // and array_chunk both ignore them, a list<string> is what the
        // annotation elsewhere in this stage promises.
        $itemIds = DB::table('content.source_items')
            ->whereIn('id', $sourceItemIds)
            ->whereNotNull('item_id')
            ->pluck('item_id')
            ->unique()
            ->values()
            ->all();
        if ($itemIds === []) {
            return;
        }

        foreach (array_chunk($itemIds, $chunk) as $idChunk) {
            foreach (['item_media', 'offers', 'item_tags', 'f_action'] as $collection) {
                DB::table("content.{$collection}")
                    ->whereIn('item_id', $idChunk)
                    ->where('source_id', $contentSourceId)
                    ->delete();
            }
            foreach (['f_text', 'f_link', 'f_duration', 'f_published', 'f_occurrence', 'f_embed', 'f_playable', 'f_authored', 'f_catalog', 'f_place', 'f_rated', 'f_review', 'f_channel', 'f_file'] as $facet) {
                DB::table("content.{$facet}")
                    ->whereIn('item_id', $idChunk)
                    ->where('source_id', $contentSourceId)
                    ->delete();
            }
        }
    }
}
