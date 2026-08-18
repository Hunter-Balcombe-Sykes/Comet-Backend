<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *
 * The delete loop below needs no cursor: every row it selects it deletes, so
 * there is nothing to page past. Termination is provable rather than assumed
 * — the doomed set shrinks monotonically on each iteration (a delete can only
 * remove rows from it), and `content.item_media.asset_id` is `ON DELETE SET
 * NULL`, so deleting asset A can never flip another asset from spared to
 * doomed and re-grow the set. The loop also cannot spin: a take that deletes
 * zero rows (another process won the race) logs and breaks rather than
 * retrying the same take forever.
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

    /**
     * `pruned` counts rows actually DELETEd, not rows targeted — identical in
     * practice (every id taken is deleted or the loop breaks), but the
     * semantics shifted from "doomed set size" to "delete result" when this
     * moved off a single unbounded pluck+delete.
     *
     * @return array{pruned: int, spared_owned: int, spared_referenced: int, spared_recent: int}
     */
    public function run(bool $dryRun = false, int $limit = 0): array
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

        // A closure, not a materialised query, because the doomed set must be
        // re-read fresh on each iteration below — each take is a look at a
        // set the previous take already shrank.
        $doomed = fn () => (clone $expired)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('content.item_media')
                ->whereColumn('content.item_media.asset_id', 'content.media_assets.id'));

        if ($dryRun) {
            // COUNT, never a pluck — the whole point of chunking the write
            // side is to avoid materialising an unbounded id list, and the
            // dry-run path is the one most likely to be run against the full,
            // un-limited backlog to see how big it is.
            $result['pruned'] = (int) $doomed()->count();

            return $result;
        }

        $chunk = (int) config('partna.media.borrowed_prune_chunk', self::CHUNK);

        while ($limit === 0 || $result['pruned'] < $limit) {
            $take = $limit > 0 ? min($chunk, $limit - $result['pruned']) : $chunk;
            $ids = $doomed()->limit($take)->pluck('id')->all();
            if ($ids === []) {
                break;
            }

            $deleted = DB::connection('pgsql')->table('content.media_assets')
                ->whereIn('id', $ids)->delete();

            if ($deleted === 0) {
                // Never spin: another process pruned the same rows first.
                Log::warning('content.prune_borrowed.no_progress', ['batch' => count($ids)]);
                break;
            }

            $result['pruned'] += $deleted;
            Log::info('content.prune_borrowed.batch', ['deleted' => $deleted, 'running_total' => $result['pruned']]);
        }

        return $result;
    }
}
