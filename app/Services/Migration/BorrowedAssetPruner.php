<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Slice 1b D4 — collect borrowed media assets we are not entitled to keep.
 *
 * Each Google sync mints a fresh asset set, because a Places photo's resource
 * name is reissued on every Details fetch: the previous rows are orphaned
 * (`item_media.asset_id` is ON DELETE SET NULL) and their items tombstoned by
 * `mergeInto()`. Left alone, `content.media_assets` grows by ten per place per
 * run forever.
 *
 * A borrowed asset that no live `item_media` references, and whose row predates
 * the ~30-day url expiry, is dead by definition — the stored lh3 link 403s. So
 * this is not a tidy-up: Google's Places terms grant photos no caching
 * exemption, and this is the mechanism that keeps borrowed content from being
 * retained.
 *
 * THREE independent spares, because "no storage_path" alone does NOT mean
 * "not ours":
 *
 * 1. `storage_path` set — mirrored, owned bytes. Never pruned.
 * 2. `site_media_id` set — an OWNER'S UPLOAD. Its bytes live in
 *    `site.media_variants`, and the asset row is a pointer into that pipeline
 *    rather than a snapshot of it (1a §3.2), so it legitimately carries no
 *    `storage_path`. Pruning on `storage_path IS NULL` alone would delete an
 *    owner's own photo the moment its item was tombstoned.
 * 3. Referenced by a live `item_media` row, or younger than the window.
 */
class BorrowedAssetPruner
{
    /**
     * Matches the observed Places url lifetime: stored links returned 200 at
     * 27 days and 403 at 29 (spec §2.2). Deliberately the outer bound — a row
     * younger than this may still be serving.
     */
    private const EXPIRY_DAYS = 30;

    private const CHUNK = 500;

    /** @return array{pruned: int, spared_owned: int, spared_referenced: int, spared_recent: int} */
    public function run(bool $dryRun = false): array
    {
        $result = ['pruned' => 0, 'spared_owned' => 0, 'spared_referenced' => 0, 'spared_recent' => 0];
        $cutoff = now()->subDays(self::EXPIRY_DAYS);

        // Counted separately rather than derived, so the operator can see WHY
        // a row survived instead of inferring it from a shrinking total.
        $result['spared_owned'] = (int) DB::connection('pgsql')->table('content.media_assets')
            ->where(fn ($q) => $q->whereNotNull('storage_path')->orWhereNotNull('site_media_id'))
            ->count();

        $borrowed = DB::connection('pgsql')->table('content.media_assets')
            ->whereNull('storage_path')
            ->whereNull('site_media_id');

        $result['spared_recent'] = (int) (clone $borrowed)->where('created_at', '>=', $cutoff)->count();

        $expired = (clone $borrowed)->where('created_at', '<', $cutoff);

        $result['spared_referenced'] = (int) (clone $expired)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('content.item_media')
                ->whereColumn('content.item_media.asset_id', 'content.media_assets.id'))
            ->count();

        $doomed = (clone $expired)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('content.item_media')
                ->whereColumn('content.item_media.asset_id', 'content.media_assets.id'))
            ->pluck('id')
            ->all();

        $result['pruned'] = count($doomed);

        if ($dryRun || $doomed === []) {
            return $result;
        }

        foreach (array_chunk($doomed, self::CHUNK) as $slice) {
            DB::connection('pgsql')->table('content.media_assets')->whereIn('id', $slice)->delete();
        }

        return $result;
    }
}
