<?php

namespace App\Services\Platforms;

use App\Models\Core\User\User;

/**
 * Fuzzy-matches a user's own name against a scraped Fresha team so the
 * dashboard's team-member picker can pre-highlight "you" instead of opening
 * blank (2026-07-25, Phase 11 / Decision 1).
 *
 * Deliberately a SUGGESTION, not a write. Persisting a real `selection` here
 * would need the per-employee services fetch that FreshaController::
 * saveSelection() does, and would flip two documented invariants: FreshaFetch
 * 304s any row with no `selection` (team mode never sets one), and
 * connectStatus() discriminates team-vs-storewide on teamMenu's array-ness.
 * So the match rides INSIDE the teamMenu snapshot — an existing key, already
 * spread into connectStatus()'s team response — and the user's explicit pick
 * through saveSelection() stays the only thing that writes a selection. Per
 * Decision 1, no match simply means the picker opens unselected as before.
 */
final class FreshaStaffMatcher
{
    /** Exact full-name match. */
    private const SCORE_EXACT = 3;

    /** Both first- AND last-name tokens present, any order. */
    private const SCORE_BOTH_TOKENS = 2;

    /** Last-name token only — accepted solely when it is unique in the team. */
    private const SCORE_LAST_ONLY = 1;

    /** Score → reportable tier label, for the auto-selection audit trail. */
    private const TIER_LABELS = [
        self::SCORE_EXACT => 'exact',
        self::SCORE_BOTH_TOKENS => 'both-tokens',
        self::SCORE_LAST_ONLY => 'last-only',
    ];

    /**
     * The employeeId of the single team member confidently identifiable as
     * $user, or null when the name is blank, nothing matches, or the best tier
     * is ambiguous. A first-name-only hit never matches: "Sarah" in a salon of
     * three Sarahs is exactly the wrong person to pre-select.
     *
     * Unchanged behaviour — delegates to matchWithTier() so there is one algorithm.
     *
     * @param  list<array<string,mixed>>  $team  FreshaScraper::extractTeam() output
     */
    public function match(User $user, array $team): ?string
    {
        return $this->matchWithTier($user, $team)['employeeId'];
    }

    /**
     * As match(), but also reports WHICH tier fired. The auto-selection path
     * records this: the tier distribution is the only measurement that lets the
     * "no tier restriction" decision be revisited on evidence rather than intuition.
     *
     * @param  list<array<string,mixed>>  $team  FreshaScraper::extractTeam() output
     * @return array{employeeId: ?string, tier: ?string}
     */
    public function matchWithTier(User $user, array $team): array
    {
        if ($team === []) {
            return ['employeeId' => null, 'tier' => null];
        }

        $fullName = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));
        if ($fullName === '') {
            return ['employeeId' => null, 'tier' => null];
        }

        $nameLower = mb_strtolower($fullName);
        // array_values because array_filter preserves keys — a double space in
        // the stored name would otherwise leave $tokens[0] unset.
        $tokens = array_values(array_filter(explode(' ', $nameLower), static fn (string $t) => $t !== ''));
        $firstName = $tokens[0] ?? '';
        $lastName = count($tokens) > 1 ? (string) end($tokens) : '';

        $candidates = [];

        foreach ($team as $employee) {
            $empName = mb_strtolower((string) ($employee['displayName'] ?? ''));
            $empId = (string) ($employee['employeeId'] ?? '');
            if ($empName === '' || $empId === '') {
                continue;
            }

            if ($empName === $nameLower) {
                $candidates[self::SCORE_EXACT][] = $empId;

                continue;
            }

            if ($firstName !== '' && $lastName !== '' && $lastName !== $firstName
                && str_contains($empName, $firstName)
                && str_contains($empName, $lastName)) {
                $candidates[self::SCORE_BOTH_TOKENS][] = $empId;

                continue;
            }

            if ($lastName !== '' && str_contains($empName, $lastName)) {
                $candidates[self::SCORE_LAST_ONLY][] = $empId;
            }
        }

        if ($candidates === []) {
            return ['employeeId' => null, 'tier' => null];
        }

        // Best tier wins; a tie inside that tier is ambiguous at EVERY tier,
        // including exact (two "Jane Doe"s on one team — pick neither).
        krsort($candidates);
        $bestScore = (int) array_key_first($candidates);
        $best = $candidates[$bestScore];

        return count($best) === 1
            ? ['employeeId' => $best[0], 'tier' => self::TIER_LABELS[$bestScore]]
            : ['employeeId' => null, 'tier' => null];
    }
}
