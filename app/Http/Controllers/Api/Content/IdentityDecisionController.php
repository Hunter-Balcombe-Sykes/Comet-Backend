<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Content\Concerns\ResolvesOwnedItem;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Content\ApplyIdentityDecisionJob;
use App\Jobs\Content\ReprojectSourcesJob;
use App\Models\Content\Item;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The owner overruling the machine on identity (plan W5, done 2026-08-18,
 * task #18): "these two are the same thing" / "these are different". Writes
 * one identity_decision per (left coord, right coord) pair across the two
 * items' live source_items — the Resolver's second/third passes read exactly
 * that (`same` unions, `different` cuts) — dismisses the open candidate, and
 * queues a reprojection of every ingest source that feeds either item so
 * the merge/split lands with its facets, not just its source_items.
 */
class IdentityDecisionController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;
    use ResolvesOwnedItem;

    public function store(Request $request, string $itemId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $data = $request->validate([
            'other' => ['required', 'uuid'],
            'verdict' => ['required', 'in:same,different'],
        ]);

        $left = $this->ownedItemOr404($user, $itemId);
        $right = $this->ownedItemOr404($user, (string) $data['other']);
        if ($left->id === $right->id) {
            abort(404, 'Item not found.');
        }
        if ($left->kind !== $right->kind) {
            return $this->error('Only items of the same kind can be the same thing.', 422);
        }

        $coordsFor = fn (string $id) => DB::table('content.source_items')
            ->where('item_id', $id)->whereNull('removed_at')->pluck('coord')->map(fn ($c) => (string) $c)->all();
        $leftCoords = $coordsFor((string) $left->id);
        $rightCoords = $coordsFor((string) $right->id);
        if ($leftCoords === [] || $rightCoords === []) {
            return $this->error('One of these items has no live source to decide on.', 422);
        }

        $rows = [];
        $seenPairs = [];
        foreach (array_unique($leftCoords) as $l) {
            foreach (array_unique($rightCoords) as $r) {
                [$a, $b] = strcmp($l, $r) <= 0 ? [$l, $r] : [$r, $l];
                if ($a === $b || isset($seenPairs[$a.'|'.$b])) {
                    continue;
                }
                $seenPairs[$a.'|'.$b] = true;
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'verdict' => $data['verdict'],
                    'left_coord' => $a,
                    'right_coord' => $b,
                    'decided_at' => now(),
                    'decided_by' => 'owner',
                ];
            }
        }
        DB::table('content.identity_decisions')->upsert($rows, ['user_id', 'left_coord', 'right_coord'], ['verdict', 'decided_at', 'decided_by']);

        DB::table('content.identity_candidates')
            ->where('user_id', $user->id)
            ->where(fn ($w) => $w
                ->where(fn ($p) => $p->where('left_item_id', $left->id)->where('right_item_id', $right->id))
                ->orWhere(fn ($p) => $p->where('left_item_id', $right->id)->where('right_item_id', $left->id)))
            ->update(['dismissed_at' => now()]);

        // Every live coord of the pair, paired with the ingest source that can
        // replay it — LEFT, not INNER (plan 2026-08-25 §A.4, follow-up 1).
        // A MANUAL content source has no connection_id, and `NULL = NULL` is
        // never true, so it simply does not match; under the old INNER JOIN it
        // was dropped from the result entirely and a ruling on two hand-added
        // items dispatched NOTHING. Keeping the unmatched rows is what lets the
        // two lanes below be partitioned instead of silently truncated.
        $coordSources = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('ingest.sources as isrc', 'isrc.connection_id', '=', 'cs.connection_id')
            ->where('cs.user_id', $user->id)
            ->whereIn('si.item_id', [$left->id, $right->id])
            ->whereNull('si.removed_at')
            ->get(['si.coord', 'isrc.id as ingest_source_id']);

        // Lane 1, unchanged: reproject off landed records, and the resolver
        // reads the new decisions and re-binds as part of that replay.
        $sourceIds = $coordSources->pluck('ingest_source_id')->filter()
            ->map(fn ($id) => (string) $id)->unique()->values()->all();
        if ($sourceIds !== []) {
            ReprojectSourcesJob::dispatch((string) $user->id, $sourceIds);
        }

        // Lane 2: coords no reprojection reaches. There are no landed records to
        // replay for a manual coord, so this resolves the identity spine
        // directly instead.
        //
        // Restricted to the UNMATCHED coords, but NOT because that shrinks the
        // resolve — it does not. IdentityScope::component() seeds from every
        // coord a live `same` ruling names, so for a mixed pair this job walks
        // the identical component whichever coords are handed to it. What the
        // restriction actually avoids is dispatching a SECOND job to redo work
        // `ingest:project` already does under the same per-(user, kind)
        // advisory lock. Stated precisely because the obvious reading — "this
        // narrows the component" — is wrong and would mislead the next reader.
        $unreprojected = $coordSources->whereNull('ingest_source_id')->pluck('coord')
            ->map(fn ($coord) => (string) $coord)->unique()->values()->all();

        // `same` ONLY. A `different` verdict is provably a no-op through this
        // path: it can only PREVENT a future union, never undo one, and both
        // entry points to this ruling require two DISTINCT items — so there is
        // no merge for a cut to reverse, and the cut is read from
        // content.identity_decisions by whatever resolve comes next anyway.
        // The open candidate is dismissed directly above, so nothing about the
        // payload changes either. Dispatching for symmetry looked tidier but
        // bought an owner triaging twenty pairs as "different" twenty pointless
        // resolves and forty CDN purges, for a guaranteed zero-diff outcome.
        if ($unreprojected !== [] && $data['verdict'] === 'same') {
            ApplyIdentityDecisionJob::dispatch((string) $user->id, $left->kind, $unreprojected);
            $resolving = count($unreprojected);
        } else {
            $resolving = 0;
        }

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success([
            'verdict' => $data['verdict'],
            'decisions' => count($rows),
            'reprojecting' => count($sourceIds),
            'resolving' => $resolving,
        ], 202);
    }
}
