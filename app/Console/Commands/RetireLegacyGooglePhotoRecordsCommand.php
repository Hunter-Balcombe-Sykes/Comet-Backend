<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off, idempotent post-deploy step for R6 (overnight 2026-08-18): Google
 * photo records used to be keyed on the rotating `photos[].name` ref, so every
 * item minted before the stable `gphoto:` key is a duplicate the connector will
 * never retire (its coverage is Unknown). Retire those source_items + items and
 * tombstone their record_state so a rebuild cannot resurrect them. Ran on dev
 * (50 items); run once on production after deploy.
 */
class RetireLegacyGooglePhotoRecordsCommand extends Command
{
    protected $signature = 'content:retire-legacy-gphoto-records {--dry-run}';

    protected $description = 'Retire name-keyed (pre-R6) Google photo items and tombstone their record_state.';

    public function handle(): int
    {
        $legacy = DB::table('content.source_items as si')
            ->join('content.sources as s', 's.id', '=', 'si.source_id')
            ->where('s.label', 'google_business')
            ->where('si.kind', 'media')
            ->whereNull('si.removed_at')
            ->where('si.coord', 'like', '%:places/%')
            ->get(['si.id', 'si.item_id']);
        $records = DB::table('ingest.record_state as rs')
            ->join('ingest.streams as st', 'st.id', '=', 'rs.stream_id')
            ->join('ingest.sources as so', 'so.id', '=', 'st.source_id')
            ->where('so.source_key', 'google_business')
            ->where('st.stream_name', 'media')
            ->where('rs.key', 'like', 'places/%')
            ->whereNull('rs.tombstoned_at')
            ->count();
        $this->info("legacy source_items: {$legacy->count()}, legacy record_state rows: {$records}");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($legacy) {
            $ids = $legacy->pluck('id')->all();
            $itemIds = $legacy->pluck('item_id')->unique()->all();
            DB::table('content.source_items')->whereIn('id', $ids)->update(['removed_at' => now()]);
            // Only items with no other live source.
            $keep = DB::table('content.source_items')->whereIn('item_id', $itemIds)->whereNull('removed_at')->pluck('item_id')->all();
            DB::table('content.items')->whereIn('id', array_diff($itemIds, $keep))->whereNull('removed_at')->update(['removed_at' => now()]);
            DB::statement("update ingest.record_state rs set tombstoned_at = now() from ingest.streams st join ingest.sources so on so.id = st.source_id where rs.stream_id = st.id and so.source_key = 'google_business' and st.stream_name = 'media' and rs.key like 'places/%' and rs.tombstoned_at is null");
        });
        $this->info('retired.');

        return self::SUCCESS;
    }
}
