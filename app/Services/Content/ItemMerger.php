<?php

namespace App\Services\Content;

use App\Content\Identity\Resolver;
use App\Models\Content\Item;
use App\Models\Core\User\User;
use App\Site\Documents\BuildState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists a human's ruling on a possible duplicate (plan §5).
 *
 * {@see Resolver} is pure and re-runnable: it takes
 * source items plus DECISIONS and returns groups. So a ruling is not "apply a
 * merge" — it is "write down what the user said, in the vocabulary the
 * resolver reads", and then make the current tables agree with that.
 *
 * Decisions are keyed on COORDS, not item ids, because item ids are the
 * resolver's OUTPUT. If a ruling were recorded against ids it would evaporate
 * the moment identity was recomputed. Coords survive reconnects, so the ruling
 * does too.
 *
 * A `different` verdict must cut EVERY pair across the two items, not one
 * representative: a single cut would leave the groups joined by whichever key
 * merged them in the first place. `same` only strictly needs one pair, but is
 * written the same way so the two verdicts stay symmetrical and reversible.
 */
final class ItemMerger
{
    /**
     * Cross-product bound. Two items with more coords than this between them
     * are not a duplicate pair a person can meaningfully rule on — that is a
     * mis-shaped projection, and quietly writing thousands of decision rows
     * would bury it.
     */
    private const MAX_DECISION_PAIRS = 100;

    /**
     * Record `same`: write the decisions, fold the discarded item into the
     * kept one, and log the merge.
     *
     * @return array{keptItemId: string, discardedItemId: string, decisionsWritten: int, warnings: list<string>}
     */
    public function merge(User $user, Item $left, Item $right, string $reason = 'user ruling'): array
    {
        [$kept, $discarded] = $this->survivorFirst($left, $right);

        $pairs = $this->coordPairs($left, $right);
        $warnings = $this->coordWarnings($left, $right);

        return DB::transaction(function () use ($user, $kept, $discarded, $pairs, $reason, $warnings): array {
            $written = $this->recordDecisions($user, $pairs, 'same');
            $moved = $this->foldInto($kept, $discarded);

            DB::table('content.item_merges')->insert([
                'user_id' => $user->id,
                'kept_item_id' => $kept->id,
                'discarded_item_id' => $discarded->id,
                'reason' => $reason,
                'detail' => json_encode([
                    'decisionPairs' => count($pairs),
                    'moved' => $moved,
                    'survivorRule' => 'older first_seen_at',
                ]),
                'merged_at' => now(),
            ]);

            // Facet rows are DERIVED from source items, so they are not moved
            // by hand: repointing the source items is the merge, and the next
            // projection rewrites the facets under the surviving item. Moving
            // them here would duplicate the projector's conflict resolution
            // and the two would eventually disagree.
            DB::table('content.items')->where('id', $discarded->id)->delete();

            $this->bumpSites($user);

            return [
                'keptItemId' => (string) $kept->id,
                'discardedItemId' => (string) $discarded->id,
                'decisionsWritten' => $written,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Record `different`: a CUT the resolver applies after every union, so it
     * survives a shared key that says otherwise (C8).
     *
     * KNOWN GAP (owned by app/Content/Identity/DisjointSet.php, not here):
     * `separate()` re-roots its SECOND argument, so a cut only bites when that
     * argument is the union child. The coords stored here are sorted, and
     * which of them won the union is not knowable at write time — so a
     * `different` ruling currently fails to split about half the time. Writing
     * the row in both directions would hide the bug and have to be un-done the
     * day it is fixed. Pinned by the characterisation test in
     * tests/Feature/Content/IdentityQueueTest.php.
     *
     * @return array{decisionsWritten: int, warnings: list<string>}
     */
    public function separate(User $user, Item $left, Item $right): array
    {
        $pairs = $this->coordPairs($left, $right);
        $warnings = $this->coordWarnings($left, $right);

        $written = DB::transaction(fn (): int => $this->recordDecisions($user, $pairs, 'different'));

        return ['decisionsWritten' => $written, 'warnings' => $warnings];
    }

    /** True when the pair carries more coords than a person can rule on. */
    public function exceedsPairBound(Item $left, Item $right): bool
    {
        return count($this->coordsFor($left)) * count($this->coordsFor($right)) > self::MAX_DECISION_PAIRS;
    }

    public static function pairBound(): int
    {
        return self::MAX_DECISION_PAIRS;
    }

    /**
     * The survivor is the item first seen — the plan's stated rule, and the
     * only one that is stable under re-running: a merge must pick the same
     * winner every time or the pins and overrides attached to it move around.
     *
     * @return array{0: Item, 1: Item}
     */
    private function survivorFirst(Item $left, Item $right): array
    {
        $leftFirst = $left->first_seen_at->getTimestamp();
        $rightFirst = $right->first_seen_at->getTimestamp();

        if ($leftFirst !== $rightFirst) {
            return $leftFirst < $rightFirst ? [$left, $right] : [$right, $left];
        }

        // Same instant: fall back to id order so the answer is deterministic
        // rather than dependent on which row the query happened to return.
        return ((string) $left->id) < ((string) $right->id) ? [$left, $right] : [$right, $left];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function coordPairs(Item $left, Item $right): array
    {
        $pairs = [];

        foreach ($this->coordsFor($left) as $leftCoord) {
            foreach ($this->coordsFor($right) as $rightCoord) {
                if ($leftCoord === $rightCoord) {
                    continue;
                }
                // Normalised order: the unique index is (user, left, right),
                // so an unsorted pair would let the same ruling be stored
                // twice in mirror image and read back as two decisions.
                $pairs[] = $leftCoord < $rightCoord
                    ? [$leftCoord, $rightCoord]
                    : [$rightCoord, $leftCoord];
            }
        }

        return array_values(array_unique($pairs, SORT_REGULAR));
    }

    /** @return list<string> */
    private function coordsFor(Item $item): array
    {
        return array_values(array_unique(
            DB::table('content.source_items')
                ->where('item_id', $item->id)
                ->pluck('coord')
                ->all()
        ));
    }

    /** @return list<string> */
    private function coordWarnings(Item $left, Item $right): array
    {
        $warnings = [];

        foreach ([$left, $right] as $item) {
            if ($this->coordsFor($item) === []) {
                $warnings[] = 'Item '.$item->id.' has no source records, so this ruling cannot be '
                    .'recorded for the identity resolver — it applies to the current tables only.';
            }
        }

        return $warnings;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function recordDecisions(User $user, array $pairs, string $verdict): int
    {
        if ($pairs === []) {
            return 0;
        }

        $rows = array_map(fn (array $pair): array => [
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'verdict' => $verdict,
            'left_coord' => $pair[0],
            'right_coord' => $pair[1],
            'decided_at' => now(),
            'decided_by' => 'user',
        ], $pairs);

        // A re-ruling must REPLACE the old verdict, not be dropped by the
        // unique index — the whole point of the queue is changing your mind.
        DB::table('content.identity_decisions')
            ->where('user_id', $user->id)
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn ($q) => $q->where('left_coord', $pair[0])->where('right_coord', $pair[1]));
                }
            })
            ->delete();

        DB::table('content.identity_decisions')->insert($rows);

        return count($rows);
    }

    /**
     * Move everything anchored to the discarded item onto the survivor.
     *
     * @return array<string, int> what moved, for the merge log
     */
    private function foldInto(Item $kept, Item $discarded): array
    {
        return [
            'sourceItems' => DB::table('content.source_items')
                ->where('item_id', $discarded->id)
                ->update(['item_id' => $kept->id]),

            // Anchors keep coords resolvable after the discarded row is gone;
            // the PK is (user_id, coord), so repointing never collides.
            'anchors' => DB::table('content.item_anchors')
                ->where('item_id', $discarded->id)
                ->update(['item_id' => $kept->id, 'superseded_by' => $kept->id]),

            'overrides' => $this->moveWithoutClobbering(
                'content.manual_overrides',
                $discarded->id,
                $kept->id,
                fn ($query) => $query->select('facet', 'column_name'),
                ['facet', 'column_name'],
            ),

            // A pin on the discarded item becomes a pin on the survivor — the
            // user pinned a thing, not a row id.
            'pins' => $this->moveWithoutClobbering(
                'site.section_items',
                $discarded->id,
                $kept->id,
                fn ($query) => $query->select('section_id'),
                ['section_id'],
            ),

            'slugs' => $this->moveSlugs($kept, $discarded),

            'collections' => $this->moveWithoutClobbering(
                'content.collection_items',
                $discarded->id,
                $kept->id,
                fn ($query) => $query->select('collection_id'),
                ['collection_id'],
            ),

            // An open candidate against the discarded item is answered by this
            // merge; leaving it would re-ask a settled question.
            'candidates' => DB::table('content.identity_candidates')
                ->where(fn ($query) => $query->where('left_item_id', $discarded->id)->orWhere('right_item_id', $discarded->id))
                ->whereNull('dismissed_at')
                ->update(['dismissed_at' => now()]),
        ];
    }

    /**
     * Repoint rows onto the survivor, dropping any that would collide with a
     * row the survivor already has. The survivor's row wins because it belongs
     * to the older item — the same rule the plan states for same-source facet
     * collisions.
     *
     * @param  \Closure(Builder): Builder  $keyColumns
     * @param  list<string>  $keys
     */
    private function moveWithoutClobbering(string $table, string $discardedId, string $keptId, \Closure $keyColumns, array $keys): int
    {
        $existing = $keyColumns(DB::table($table)->where('item_id', $keptId))
            ->get()
            ->map(fn (object $row): string => implode('|', array_map(fn (string $k): string => (string) $row->{$k}, $keys)))
            ->flip();

        $moved = 0;

        foreach (DB::table($table)->where('item_id', $discardedId)->get() as $row) {
            $signature = implode('|', array_map(fn (string $k): string => (string) $row->{$k}, $keys));

            if ($existing->has($signature)) {
                continue;
            }

            DB::table($table)
                ->where('item_id', $discardedId)
                ->where(function ($query) use ($keys, $row) {
                    foreach ($keys as $key) {
                        $query->where($key, $row->{$key});
                    }
                })
                ->update(['item_id' => $keptId]);

            $moved++;
        }

        return $moved;
    }

    /**
     * Slugs are never dropped — an old URL must keep resolving. They all move
     * to the survivor; only the survivor's own current slug stays current, so
     * the item does not end up with two "current" names.
     */
    private function moveSlugs(Item $kept, Item $discarded): int
    {
        $keptHasCurrent = DB::table('content.item_slugs')
            ->where('item_id', $kept->id)
            ->where('is_current', true)
            ->exists();

        $promote = $keptHasCurrent
            ? null
            : DB::table('content.item_slugs')
                ->where('item_id', $discarded->id)
                ->where('is_current', true)
                ->orderByDesc('created_at')
                ->value('id');

        $moved = DB::table('content.item_slugs')
            ->where('item_id', $discarded->id)
            ->update(['item_id' => $kept->id, 'is_current' => false]);

        if ($promote !== null) {
            DB::table('content.item_slugs')->where('id', $promote)->update(['is_current' => true]);
        }

        return $moved;
    }

    /** Every curation write makes the built document stale (plan §9). */
    private function bumpSites(User $user): void
    {
        foreach (DB::table('site.sites')->where('user_id', $user->id)->pluck('id') as $siteId) {
            BuildState::bump((string) $siteId);
        }
    }
}
