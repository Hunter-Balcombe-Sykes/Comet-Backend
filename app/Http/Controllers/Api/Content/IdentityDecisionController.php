<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
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

    public function store(Request $request, string $itemId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $data = $request->validate([
            'other' => ['required', 'uuid'],
            'verdict' => ['required', 'in:same,different'],
        ]);

        $left = Item::query()->where('id', $itemId)->where('user_id', $user->id)->whereNull('removed_at')->first();
        $right = Item::query()->where('id', $data['other'])->where('user_id', $user->id)->whereNull('removed_at')->first();
        if ($left === null || $right === null || $left->id === $right->id) {
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

        // Every ingest source feeding either item, reprojected off its landed
        // records: the resolver reads the new decisions and re-binds.
        $sourceIds = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->join('ingest.sources as isrc', 'isrc.connection_id', '=', 'cs.connection_id')
            ->whereIn('si.item_id', [$left->id, $right->id])
            ->whereNull('si.removed_at')
            ->distinct()->pluck('isrc.id')->map(fn ($id) => (string) $id)->all();
        if ($sourceIds !== []) {
            ReprojectSourcesJob::dispatch((string) $user->id, $sourceIds);
        }
        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success([
            'verdict' => $data['verdict'],
            'decisions' => count($rows),
            'reprojecting' => count($sourceIds),
        ], 202);
    }
}
