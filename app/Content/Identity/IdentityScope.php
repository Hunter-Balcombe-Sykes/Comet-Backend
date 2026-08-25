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
 * signature, or when the user ruled them 'same'. The component is seeded from
 * the touched coords PLUS every coord a live 'same' ruling names (§A.4 —
 * a manual source has no connection_id, so IdentityDecisionController's
 * reprojection join can silently dispatch nothing for a ruling on two
 * hand-added items; seeding from the ruling itself closes that hole at the
 * invariant instead of at one controller's query), taken to FIXPOINT.
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
 * TWO SEPARATE GUARDS, not one (corrected 2026-08-25 — the original draft's
 * poisoning-needs-two-hops story was self-refuting: a signature can only
 * poison a coord's outcome if that coord itself carries it, which makes every
 * carrier, poisoning sibling included, ONE hop away).
 *
 * Guard 1 — FIXPOINT TRANSITIVITY is for GROUP MEMBERSHIP. The resolver is a
 * union-find: A-B on one signature and B-C on a DIFFERENT signature put all
 * three in one group, and bindGroup() binds the whole group to one item id. C
 * shares nothing directly with A, so a one-hop (or fixed-hop) closure would
 * leave C bound elsewhere, splitting a group the full resolve keeps whole.
 * Pinned by the 'follows the chain transitively to fixpoint' test (a-b-c-d).
 *
 * Guard 2 — UNFILTERED BREADTH is for POISONING. A key too weak for
 * keyIndex() to union on (short, wrong tier, wrong kind) can still poison a
 * signature in poisonedKeys(), so the closure must index it too, or the
 * poisoning sibling — one hop away, but only findable via an unfiltered edge
 * — would be missing from the scoped set. Pinned by the 'expands a shared
 * signature to every member' and 'includes weak keys' tests.
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
        if ($items === []) {
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

        // Seed from the touched coords AND every coord a live 'same' ruling
        // names (§A.4). A manual source has no connection_id, so
        // IdentityDecisionController's reprojection query — an INNER JOIN on
        // ingest.sources.connection_id — dispatches no reprojection for a
        // ruling on two hand-added items, and neither coord is ever
        // "touched" as a result. Seeding the ruling's own coords means the
        // owner's "these are the same" verdict still takes effect the next
        // time anything in this kind resolves. 'different' is NOT seeded
        // here: a cut only ever suppresses a union, so it can only matter
        // between coords that already share a signature — already reachable
        // once either side is touched — and seeding from it would drag in
        // unrelated groups for no benefit (§A.4).
        $seen = [];
        $queue = [];
        foreach ([...$touched, ...array_keys($sameEdges)] as $coord) {
            if (isset($known[$coord]) && ! isset($seen[$coord])) {
                $seen[$coord] = true;
                $queue[] = $coord;
            }
        }

        if ($queue === []) {
            return ['coords' => [], 'capped' => false];
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
