<?php

namespace App\Content\Identity;

/**
 * Which coords can possibly resolve differently when a given set changed.
 *
 * PURE, exactly like Resolver: no database, no clock, no vendor. That is what
 * lets the differential test (tests/Postgres/ProjectionWriterScopedResolveTest)
 * assert "scoped == whole-kind" rather than trust it.
 *
 * The rule: two coords are adjacent when they share ANY canonicalised key
 * signature, or when the user ruled them 'same'. The component containing the
 * touched coords, taken to FIXPOINT, is the answer.
 *
 * THE BREADTH IS DELIBERATE and mirrors Resolver::poisonedKeys(): no tier(),
 * no minLength(), no appliesTo() filter. poisonedKeys() disables a signature
 * when ONE SOURCE carries it twice, and it deliberately poisons more broadly
 * than keyIndex() matches. A closure narrower than the poison scope would omit
 * the sibling that does the poisoning, so a key that the full resolve treats
 * as unreliable would look clean here — and the pair would MERGE. bindGroup()
 * -> mergeInto() hard-deletes the loser, so that is data loss, not a missed
 * optimisation. Do not "tidy" the missing filters in: they are the guard.
 *
 * ONE HOP IS NOT ENOUGH, for the same reason. The poisoning sibling is two
 * hops from the touched coord in the common case (touched -> match -> the
 * match's same-source duplicate). Pinned by IdentityScopeTest.
 *
 * A 'different' ruling is NOT an edge. Cuts only ever SEPARATE, and
 * DisjointSet::wouldViolateCut() cannot block a union between two in-component
 * coords using an out-of-component coord (that coord would have to share a
 * component with one of them, which would put it in the component). See the
 * plan's §A.1 proof.
 */
final class IdentityScope
{
    /**
     * Far above any real catalogue, far below a pathological one. When it
     * bites, the CALLER resolves whole-kind — this never truncates, because a
     * truncated component is the false-merge path above.
     */
    public const MAX_COMPONENT = 2000;

    /**
     * @param  list<SourceItem>  $items
     * @param  list<Decision>  $decisions
     * @param  list<string>  $touched
     * @return array{coords: list<string>, capped: bool}
     */
    public function component(array $items, array $decisions, array $touched, int $max = self::MAX_COMPONENT): array
    {
        if ($touched === [] || $items === []) {
            return ['coords' => [], 'capped' => false];
        }

        $known = [];
        foreach ($items as $item) {
            $known[$item->coord] = true;
        }

        // Adjacency, built once: signature => coords, and coord => signatures.
        $coordsBySignature = [];
        $signaturesByCoord = [];
        foreach ($items as $item) {
            foreach ($item->keys as $key) {
                $signature = $key->class->value.'|'.$key->class->canonicalise($key->value);
                $coordsBySignature[$signature][] = $item->coord;
                $signaturesByCoord[$item->coord][] = $signature;
            }
        }

        // A 'same' ruling joins two coords carrying no common key at all, so it
        // is a first-class edge. Only rulings naming coords present this run
        // count — a stale one must not vivify a coord that no longer exists.
        $sameEdges = [];
        foreach ($decisions as $decision) {
            if ($decision->verdict !== 'same') {
                continue;
            }
            if (! isset($known[$decision->left], $known[$decision->right])) {
                continue;
            }
            $sameEdges[$decision->left][] = $decision->right;
            $sameEdges[$decision->right][] = $decision->left;
        }

        $seen = [];
        $queue = [];
        foreach ($touched as $coord) {
            if (isset($known[$coord]) && ! isset($seen[$coord])) {
                $seen[$coord] = true;
                $queue[] = $coord;
            }
        }

        // Breadth-first to fixpoint. Each signature is expanded at most once —
        // without that, a signature shared by k coords costs O(k^2) walks.
        $expanded = [];
        for ($head = 0; $head < count($queue); $head++) {
            $coord = $queue[$head];

            foreach ($signaturesByCoord[$coord] ?? [] as $signature) {
                if (isset($expanded[$signature])) {
                    continue;
                }
                $expanded[$signature] = true;

                foreach ($coordsBySignature[$signature] as $neighbour) {
                    if (! isset($seen[$neighbour])) {
                        $seen[$neighbour] = true;
                        $queue[] = $neighbour;
                    }
                }
            }

            foreach ($sameEdges[$coord] ?? [] as $neighbour) {
                if (! isset($seen[$neighbour])) {
                    $seen[$neighbour] = true;
                    $queue[] = $neighbour;
                }
            }

            if (count($queue) > $max) {
                return ['coords' => array_keys($known), 'capped' => true];
            }
        }

        return ['coords' => $queue, 'capped' => false];
    }
}
