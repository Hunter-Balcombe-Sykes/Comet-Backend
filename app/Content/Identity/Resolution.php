<?php

namespace App\Content\Identity;

/** What the resolver concluded: groups of coords, plus what it would not decide. */
final readonly class Resolution
{
    /**
     * @param  list<list<string>>  $groups  each group is one item
     * @param  list<Candidate>  $candidates
     */
    public function __construct(
        public array $groups,
        public array $candidates = [],
    ) {}

    /** The group containing $coord, or null. @return list<string>|null */
    public function groupFor(string $coord): ?array
    {
        foreach ($this->groups as $group) {
            if (in_array($coord, $group, true)) {
                return $group;
            }
        }

        return null;
    }

    public function sameItem(string $a, string $b): bool
    {
        $group = $this->groupFor($a);

        return $group !== null && in_array($b, $group, true);
    }
}
