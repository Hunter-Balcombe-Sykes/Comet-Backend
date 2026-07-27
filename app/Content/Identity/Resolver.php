<?php

namespace App\Content\Identity;

/**
 * The identity resolver (plan §5, C7). PURE: it takes source items with their
 * keys plus the user's own decisions, and returns groups. It reads no
 * database, writes nothing, and asks no vendor anything — which is what makes
 * it re-runnable, so identity can be RECOMPUTED from scratch after a rule
 * change instead of being an accumulated history nobody can audit.
 *
 * Order is load-bearing:
 *   1. union on joining keys           (a shared ISRC is identity)
 *   2. union on the user's `same`      (the user outranks a key)
 *   3. CUT on the user's `different`   (the user outranks a GTIN — C8)
 *   4. union on corroborating keys     (only cross-source and unambiguous)
 *   5. everything else becomes a candidate for a human, never a merge
 *
 * Step 3 sitting between the two union passes is the whole point: a user
 * saying "these are different" must survive a joining key that says
 * otherwise, and must not be undone by a later corroborating union.
 */
class Resolver
{
    /**
     * @param  list<SourceItem>  $items
     * @param  list<Decision>  $decisions
     */
    public function resolve(array $items, array $decisions = []): Resolution
    {
        $groups = new DisjointSet(array_map(fn (SourceItem $i) => $i->coord, $items));
        $byCoord = [];
        foreach ($items as $item) {
            $byCoord[$item->coord] = $item;
        }

        $poisoned = $this->poisonedKeys($items);

        // 1. Joining keys.
        foreach ($this->keyIndex($items, KeyTier::Joining, $poisoned) as $members) {
            $this->unionAll($groups, $byCoord, $members);
        }

        // 2. The user says same.
        foreach ($decisions as $decision) {
            if ($decision->verdict === 'same' && isset($byCoord[$decision->left], $byCoord[$decision->right])) {
                $groups->union($decision->left, $decision->right);
            }
        }

        // 3. The user says different — a CUT, applied after every union so far
        //    and recorded so later passes cannot re-merge the pair.
        $cuts = [];
        foreach ($decisions as $decision) {
            if ($decision->verdict === 'different') {
                $cuts[] = [$decision->left, $decision->right];
                $groups->separate($decision->left, $decision->right);
            }
        }

        // 4. Corroborating keys — cross-source only, and never across a cut.
        foreach ($this->keyIndex($items, KeyTier::Corroborating, $poisoned) as $members) {
            $this->unionAll($groups, $byCoord, $members, $cuts, requireCrossSource: true);
        }

        // 5. Evidential keys never merge; they surface as candidates.
        $candidates = [];
        foreach ($this->keyIndex($items, KeyTier::Evidential, $poisoned) as $keyValue => $members) {
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $a = $members[$i];
                    $b = $members[$j];
                    if ($groups->find($a) !== $groups->find($b) && ! $this->isCut($cuts, $a, $b)) {
                        $candidates[] = new Candidate($a, $b, $keyValue);
                    }
                }
            }
        }

        return new Resolution($groups->groups(), $candidates);
    }

    /**
     * A key value is POISONED for this run when a single source contributes
     * two or more records carrying it: that means the value does not identify
     * anything within that source, so it cannot identify anything across
     * sources either. (A platform listing the same ISRC on two tracks tells us
     * their ISRC data is unreliable, not that the tracks are the same.)
     *
     * @param  list<SourceItem>  $items
     * @return array<string, true>
     */
    private function poisonedKeys(array $items): array
    {
        $seen = [];
        $poisoned = [];

        foreach ($items as $item) {
            foreach ($item->keys as $key) {
                $signature = $key->class->value.'|'.$key->value;
                $sourceKey = $signature.'|'.$item->sourceId;
                if (isset($seen[$sourceKey])) {
                    $poisoned[$signature] = true;
                }
                $seen[$sourceKey] = true;
            }
        }

        return $poisoned;
    }

    /**
     * @param  list<SourceItem>  $items
     * @param  array<string, true>  $poisoned
     * @return array<string, list<string>> key signature => coords
     */
    private function keyIndex(array $items, KeyTier $tier, array $poisoned): array
    {
        $index = [];

        foreach ($items as $item) {
            foreach ($item->keys as $key) {
                if ($key->class->tier() !== $tier) {
                    continue;
                }
                $canonical = $key->class->canonicalise($key->value);
                if (mb_strlen($canonical) < $key->class->minLength()) {
                    continue;
                }
                if (! $key->class->appliesTo($item->kind)) {
                    continue;
                }
                $signature = $key->class->value.'|'.$canonical;
                if (isset($poisoned[$signature])) {
                    continue;
                }
                $index[$signature][] = $item->coord;
            }
        }

        return array_filter($index, fn (array $members) => count($members) > 1);
    }

    /**
     * @param  array<string, SourceItem>  $byCoord
     * @param  list<string>  $members
     * @param  list<array{0: string, 1: string}>  $cuts
     */
    private function unionAll(DisjointSet $groups, array $byCoord, array $members, array $cuts = [], bool $requireCrossSource = false): void
    {
        for ($i = 0; $i < count($members); $i++) {
            for ($j = $i + 1; $j < count($members); $j++) {
                $a = $byCoord[$members[$i]] ?? null;
                $b = $byCoord[$members[$j]] ?? null;
                if ($a === null || $b === null) {
                    continue;
                }
                // Kind gate: a key never unions across item kinds, except a
                // CanonicalUrl where one side is a bare `link` (a pasted URL
                // later identified as a track folds into it).
                if (! $this->mayUnion($a, $b)) {
                    continue;
                }
                if ($requireCrossSource && $a->sourceId === $b->sourceId) {
                    continue;
                }
                if ($this->isCut($cuts, $a->coord, $b->coord)) {
                    continue;
                }
                $groups->union($a->coord, $b->coord);
            }
        }
    }

    private function mayUnion(SourceItem $a, SourceItem $b): bool
    {
        if ($a->kind === $b->kind) {
            return true;
        }

        // The single sanctioned cross-kind case, replacing KindFamily: a link
        // is an unidentified thing, so anything may absorb it.
        return $a->kind === 'link' || $b->kind === 'link';
    }

    /** @param list<array{0: string, 1: string}> $cuts */
    private function isCut(array $cuts, string $a, string $b): bool
    {
        foreach ($cuts as [$left, $right]) {
            if (($left === $a && $right === $b) || ($left === $b && $right === $a)) {
                return true;
            }
        }

        return false;
    }
}
