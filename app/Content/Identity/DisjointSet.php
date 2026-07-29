<?php

namespace App\Content\Identity;

/**
 * Union-find with support for SEPARATION — the part a textbook disjoint set
 * does not have, and which the user's "these are different" ruling requires.
 *
 * Separation is handled by recording forbidden pairs and detaching one side
 * of the pair, rather than trying to undo a union in place. Note this is
 * NOT order-independent: the cut is applied by detaching the non-root side,
 * so anything joined only through it follows the detached element — which
 * one of a third, transitively-joined element ends up with depends on which
 * side won the union that joined it. See FU-1 (2026-07-29).
 */
class DisjointSet
{
    /** @var array<string, string> */
    private array $parent = [];

    /** @var list<array{0: string, 1: string}> */
    private array $separations = [];

    /** @param list<string> $elements */
    public function __construct(array $elements)
    {
        foreach ($elements as $element) {
            $this->parent[$element] = $element;
        }
    }

    public function find(string $element): string
    {
        if (! isset($this->parent[$element])) {
            $this->parent[$element] = $element;
        }
        while ($this->parent[$element] !== $element) {
            $this->parent[$element] = $this->parent[$this->parent[$element]];
            $element = $this->parent[$element];
        }

        return $element;
    }

    public function union(string $a, string $b): void
    {
        if ($this->isSeparated($a, $b)) {
            return;
        }
        $rootA = $this->find($a);
        $rootB = $this->find($b);
        if ($rootA !== $rootB) {
            $this->parent[$rootB] = $rootA;
        }
    }

    /**
     * Record that these two must never share a group, and split them apart if
     * they already do.
     */
    public function separate(string $a, string $b): void
    {
        $this->separations[] = [$a, $b];

        if (! isset($this->parent[$a]) || ! isset($this->parent[$b])) {
            return;
        }

        if ($this->find($a) !== $this->find($b)) {
            return;
        }

        // Re-root whichever argument is NOT the group root. Re-rooting the
        // root is a no-op, and always re-rooting $b (the old bug) silently
        // lost the cut whenever $b's side had won the union — an accident of
        // argument order no caller controls (stored decisions arrive with
        // coords sorted). Anything transitively joined only through the
        // detached element follows it.
        $detach = $this->find($b) === $b ? $a : $b;
        $this->parent[$detach] = $detach;

        // If one argument's path ran through the other, one detach pulled
        // both out together — split the pair itself.
        if ($this->find($a) === $this->find($b)) {
            $other = $detach === $b ? $a : $b;
            $this->parent[$other] = $other;
        }
    }

    private function isSeparated(string $a, string $b): bool
    {
        foreach ($this->separations as [$left, $right]) {
            // A cut naming an element this run never saw is inert — and
            // resolving it would auto-vivify the unknown element into
            // $parent (find(), above), leaking a phantom singleton out of
            // groups().
            if (! isset($this->parent[$left]) || ! isset($this->parent[$right])) {
                continue;
            }
            $rootLeft = $this->find($left);
            $rootRight = $this->find($right);
            if (($this->find($a) === $rootLeft && $this->find($b) === $rootRight)
                || ($this->find($a) === $rootRight && $this->find($b) === $rootLeft)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<list<string>> */
    public function groups(): array
    {
        $groups = [];
        foreach (array_keys($this->parent) as $element) {
            $groups[$this->find($element)][] = $element;
        }

        return array_values(array_map('array_values', $groups));
    }
}
