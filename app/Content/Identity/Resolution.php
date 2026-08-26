<?php

namespace App\Content\Identity;

/** What the resolver concluded: groups of coords, plus what it would not decide. */
final readonly class Resolution
{
    /**
     * $cappedKeys is the resolver's own account of what it did NOT offer
     * (#SCALE-10/#CACHE-6): one entry per evidential key value whose pairing
     * hit a cap, keyed by the key signature. It exists because a silent cap
     * means duplicates quietly stop being surfaced for merge — the resolver is
     * pure and cannot log, so it REPORTS instead and ProjectionWriter logs.
     * Defaulted so it is additive: every existing `new Resolution(...)` is
     * still valid, and a resolution with nothing capped carries [].
     *
     * @param  list<list<string>>  $groups  each group is one item
     * @param  list<Candidate>  $candidates
     * @param  array<string, array{members_seen: int, members_paired: int, candidates_appended: int, member_cap_hit: bool, candidate_cap_hit: bool}>  $cappedKeys
     */
    public function __construct(
        public array $groups,
        public array $candidates = [],
        public array $cappedKeys = [],
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
