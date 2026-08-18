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
        //
        //    The isset() below is a PRE-FILTER, not the load-bearing guard.
        //    DisjointSet::isSeparated()'s own unknown-element skip is what
        //    actually prevents a stale decision auto-vivifying a phantom
        //    singleton, and it is pinned by DisjointSetTest. Deleting this
        //    line turns nothing red — correctly, because it causes no defect.
        //    Deleting the one in DisjointSet DOES turn red. Keep that
        //    asymmetry in mind before "simplifying" either: the guard with no
        //    test is the redundant one, and the tested one is load-bearing.
        $cuts = [];
        foreach ($decisions as $decision) {
            if ($decision->verdict === 'different' && isset($byCoord[$decision->left], $byCoord[$decision->right])) {
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
        // TitleRelease exception (overnight 2026-08-18, W5): a music catalogue
        // routinely lists ONE song twice within a source — album cut and
        // single, both "Dracula | Tame Impala". That is not unreliable data,
        // it is two editions of one recording, and poisoning the key on it
        // blocked nearly every Apple↔Spotify union. When every same-source
        // duplicate of a title_release value also agrees on duration (±2s,
        // via its title_duration key), the value still identifies one song
        // and stays live; duplicates with DIFFERENT durations (a demo and the
        // song, an intro and the track) poison as before.
        $durationsBySourceKey = [];
        foreach ($items as $item) {
            $durations = [];
            foreach ($item->keys as $key) {
                if ($key->class === KeyClass::TitleDuration && preg_match('/\|(\d+)$/', $key->value, $m)) {
                    $durations[] = (int) $m[1];
                }
            }
            foreach ($item->keys as $key) {
                // Canonicalise before signing, matching keyIndex() below —
                // otherwise the same value spelled two different ways (raw)
                // would sign as two different signatures and dodge the
                // duplicate-in-one-source poison check entirely (#SEM-5).
                $signature = $key->class->value.'|'.$key->class->canonicalise($key->value);
                // Same-source duplicates poison a value only WITHIN a kind:
                // Spotify lists "Dracula" the single (release) and "Dracula"
                // the song (track) — the same title|artist value on two kinds
                // that mayUnion() would never merge anyway, so it must not
                // poison the release↔release merge with Apple (listen
                // restructure 2026-08-18, caught live).
                $sourceKey = $signature.'|'.$item->sourceId.'|'.$item->kind;
                if (isset($seen[$sourceKey])) {
                    if (! ($key->class === KeyClass::TitleRelease && $this->sameEdition($durationsBySourceKey[$sourceKey] ?? [], $durations))) {
                        $poisoned[$signature] = true;
                    }
                }
                $seen[$sourceKey] = true;
                if ($key->class === KeyClass::TitleRelease) {
                    $durationsBySourceKey[$sourceKey] = array_merge($durationsBySourceKey[$sourceKey] ?? [], $durations);
                }
            }
        }

        // Deliberately no tier()/minLength()/appliesTo() filter here, unlike
        // keyIndex() below — this poisons more broadly than the index
        // matches, which can only SUPPRESS merges, never create a false one.
        // Do not "symmetrise" this into a weaker guard.
        return $poisoned;
    }

    /** Both records carry a duration and they agree within 2 seconds. */
    private function sameEdition(array $seenDurations, array $durations): bool
    {
        if ($seenDurations === [] || $durations === []) {
            return false;
        }
        foreach ($seenDurations as $a) {
            foreach ($durations as $b) {
                if (abs($a - $b) <= 2) {
                    return true;
                }
            }
        }

        return false;
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
