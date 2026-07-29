<?php

namespace App\Content\Identity;

/**
 * Union-find with support for SEPARATION — the part a textbook disjoint set
 * does not have, and which the user's "these are different" ruling requires.
 *
 * Separation is a CONSTRAINT ON THE FINAL GROUPING, not a mutation whose
 * effect depends on when it arrived. To keep that true, this class records the
 * union edges it is given and recomputes the grouping from them whenever a cut
 * or a new edge lands, skipping any edge that would join a forbidden pair. An
 * in-place "undo the union" cannot be correct: by the time a cut arrives, the
 * information about which edges built the group is gone, so the split has to
 * guess — and the old implementation's guess was whichever argument happened
 * not to be the union root, an accident of arrival order no caller controls.
 *
 * THE TIE-BREAK IS A RULING, NOT AN ACCIDENT (FU-1, settled 2026-07-29). When
 * a cut splits a group, an element joined to both sides could go either way.
 * It stays with the LEXICOGRAPHICALLY-SMALLER coordinate. That falls out of
 * replaying edges in canonical (sorted) order rather than arrival order, which
 * is also what makes the grouping a pure function of the SET of unions and
 * cuts — replay the same decisions shuffled and the answer is identical.
 * Stored decisions already arrive with coords sorted, so this matches what the
 * data mostly did anyway; the point is that it no longer DEPENDS on that.
 *
 * Rebuilds are deferred to the next read, so a batch of unions costs one
 * rebuild rather than one per edge.
 */
class DisjointSet
{
    /** @var array<string, string> */
    private array $parent = [];

    /** @var list<array{0: string, 1: string}> */
    private array $separations = [];

    /**
     * Every union this set has been given, each normalised to [smaller,
     * larger] and deduplicated. This is the input a rebuild replays; without
     * it a cut would have nothing to recompute from.
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $unions = [];

    private bool $dirty = false;

    /** @param list<string> $elements */
    public function __construct(array $elements)
    {
        foreach ($elements as $element) {
            $this->parent[$element] = $element;
        }
    }

    public function find(string $element): string
    {
        $this->settle();

        if (! isset($this->parent[$element])) {
            $this->parent[$element] = $element;
        }

        return $this->root($element);
    }

    public function union(string $a, string $b): void
    {
        // Vivify both sides: being named by a union is what makes an element a
        // member of this run. A SEPARATION does not vivify — see
        // wouldViolateCut().
        $this->find($a);
        $this->find($b);

        $edge = $a < $b ? [$a, $b] : [$b, $a];

        if (! in_array($edge, $this->unions, true)) {
            $this->unions[] = $edge;
            $this->dirty = true;
        }
    }

    /**
     * Record that these two must never share a group. Takes effect on the next
     * read, whether or not they are currently joined and whether or not either
     * is known yet — a cut given before its elements exist still binds if they
     * later turn up.
     */
    public function separate(string $a, string $b): void
    {
        $this->separations[] = $a < $b ? [$a, $b] : [$b, $a];
        $this->dirty = true;
    }

    /** @return list<list<string>> */
    public function groups(): array
    {
        $this->settle();

        $groups = [];
        foreach (array_keys($this->parent) as $element) {
            $groups[$this->root($element)][] = $element;
        }

        return array_values(array_map('array_values', $groups));
    }

    /**
     * Recompute the grouping from the recorded edges, honouring every cut.
     *
     * Edges are replayed in canonical order (sorted, each already normalised
     * to [smaller, larger]) rather than arrival order. That IS the tie-break:
     * the smaller coordinate's edges are applied first, so it wins any
     * contested element, and the outcome stops depending on the sequence the
     * caller happened to use.
     */
    private function settle(): void
    {
        if (! $this->dirty) {
            return;
        }

        // Cleared first: root() and the helpers below must not recurse back
        // into settle() while it is running.
        $this->dirty = false;

        foreach (array_keys($this->parent) as $element) {
            $this->parent[$element] = $element;
        }

        $edges = $this->unions;
        sort($edges);

        foreach ($edges as [$a, $b]) {
            if ($this->wouldViolateCut($a, $b)) {
                continue;
            }

            $rootA = $this->root($a);
            $rootB = $this->root($b);

            if ($rootA !== $rootB) {
                $this->parent[$rootB] = $rootA;
            }
        }
    }

    /**
     * Would joining these two components put some separated pair together?
     *
     * Deliberately does NOT vivify: a cut naming a coordinate this run never
     * saw must be ignored, not resurrect that coordinate as a phantom
     * singleton in groups(). That was a real defect — the old isSeparated()
     * called find() on stored decisions and auto-created whatever they named.
     */
    private function wouldViolateCut(string $a, string $b): bool
    {
        $rootA = $this->root($a);
        $rootB = $this->root($b);

        foreach ($this->separations as [$left, $right]) {
            if (! isset($this->parent[$left]) || ! isset($this->parent[$right])) {
                continue;
            }

            $rootLeft = $this->root($left);
            $rootRight = $this->root($right);

            if (($rootLeft === $rootA && $rootRight === $rootB)
                || ($rootLeft === $rootB && $rootRight === $rootA)) {
                return true;
            }
        }

        return false;
    }

    /** Root lookup with path compression, free of the dirty check find() does. */
    private function root(string $element): string
    {
        while ($this->parent[$element] !== $element) {
            $this->parent[$element] = $this->parent[$this->parent[$element]];
            $element = $this->parent[$element];
        }

        return $element;
    }
}
