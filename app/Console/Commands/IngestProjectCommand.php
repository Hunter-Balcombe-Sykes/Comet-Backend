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
        $sources = DB::table('ingest.sources')
            ->when($this->option('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('created_at')
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No ingest sources match.');

            return self::SUCCESS;
        }

        $totals = ['streams' => 0, 'projected' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($sources as $source) {
            $streams = DB::table('ingest.streams')->where('source_id', $source->id)->get(['id', 'stream_name']);

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
                    $this->dropDerivedRows((string) $source->id, (string) $stream->id);
                }

                try {
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

        $this->table(['streams', 'records projected', 'retired', 'skipped', 'failed'], [[
            $totals['streams'], $totals['projected'], $totals['removed'], $totals['skipped'], $totals['failed'],
        ]]);

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Remove what projection alone derived for this stream's source-items so
     * a changed projector re-emits from scratch. Everything dropped here is
     * re-derivable from the record log; nothing user-owned is touched.
     */
    private function dropDerivedRows(string $ingestSourceId, string $streamId): void
    {
        $connectionId = DB::table('ingest.sources')->where('id', $ingestSourceId)->value('connection_id');
        if ($connectionId === null) {
            return;
        }
        $contentSourceId = DB::table('content.sources')->where('connection_id', $connectionId)->value('id');
        if ($contentSourceId === null) {
            return;
        }

        $sourceItemIds = DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('stream_id', $streamId)
            ->pluck('id')
            ->all();
        if ($sourceItemIds === []) {
            return;
        }

        DB::table('content.identity_keys')->whereIn('source_item_id', $sourceItemIds)->delete();

        $itemIds = DB::table('content.source_items')
            ->whereIn('id', $sourceItemIds)
            ->whereNotNull('item_id')
            ->pluck('item_id')
            ->unique()
            ->all();

        foreach (['item_media', 'offers', 'item_tags', 'f_action'] as $collection) {
            DB::table("content.{$collection}")
                ->whereIn('item_id', $itemIds)
                ->where('source_id', $contentSourceId)
                ->delete();
        }
        foreach (['f_text', 'f_link', 'f_duration', 'f_published', 'f_occurrence', 'f_embed', 'f_playable', 'f_authored', 'f_catalog', 'f_place', 'f_rated', 'f_review', 'f_channel', 'f_file'] as $facet) {
            DB::table("content.{$facet}")
                ->whereIn('item_id', $itemIds)
                ->where('source_id', $contentSourceId)
                ->delete();
        }
    }
}
