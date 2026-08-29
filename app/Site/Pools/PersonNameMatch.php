<?php

namespace App\Site\Pools;

/**
 * The one name matcher for "is this review attributable to THIS person?".
 *
 * Extracted VERBATIM from PoolResolver (2026-08-29) because a second caller
 * arrived: the GDPR export has to decide whether `content.f_review.staff_name`
 * names the requester or a co-worker before it can lawfully disclose it
 * (#W1-PRIV-2 / #W2-DINT-1). Two independently-drifting name matchers is a
 * known hazard in this repo, so PoolResolver delegates here rather than
 * keeping a copy — the pool's TEXT-mention half stays in PoolResolver, since
 * only the staff-attribution half is shared.
 *
 * Behaviour-preserving: tests/Feature/Content/ReviewsPoolTest.php is the
 * regression net (match, non-match, and the 2-letter lead-token floor).
 */
class PersonNameMatch
{
    /**
     * The name forms a partna account can be recognised by: full display
     * name, first_name, and each of their leading tokens. Null when nothing
     * usable is on file.
     *
     * @return array{full: list<string>, first: list<string>}|null
     */
    public static function tokens(?string $displayName, ?string $firstName): ?array
    {
        $full = [];
        $first = [];
        // Pair-list rather than PoolResolver's original column-keyed map. Purely
        // cosmetic — the original keyed on the column NAMES, which are distinct
        // constants, so nothing could ever collapse. Behaviour is identical:
        // same order, same flags, same short-circuit.
        foreach ([[$displayName, true], [$firstName, false]] as [$value, $isFull]) {
            $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? ''));
            if ($name === '') {
                continue;
            }
            // Only a MULTI-word display name is a "full name" — a lone token
            // (and the first_name column, which is one by construction) is a
            // first-token match and rides the length floor below.
            if ($isFull && str_contains($name, ' ')) {
                $full[] = $name;
            }
            $lead = explode(' ', $name)[0];
            // A 1–2 letter lead token ("dj", initials) matches half the
            // dictionary — too weak to attribute a stranger's words with.
            if (mb_strlen($lead) >= 3) {
                $first[] = $lead;
            }
        }

        $full = array_values(array_unique($full));
        $first = array_values(array_unique(array_diff($first, $full)));

        return $full === [] && $first === [] ? null : ['full' => $full, 'first' => $first];
    }

    /**
     * Does a review's structured staff attribution name this person?
     *
     * @param  array{full: list<string>, first: list<string>}  $names
     */
    public static function matchesStaffName(?string $staffName, array $names): bool
    {
        $staff = mb_strtolower(trim((string) $staffName));
        if ($staff === '') {
            return false;
        }

        $staffLead = explode(' ', preg_replace('/\s+/u', ' ', $staff) ?? $staff)[0];
        foreach ($names['full'] as $name) {
            if ($staff === $name || $staffLead === explode(' ', $name)[0]) {
                return true;
            }
        }
        foreach ($names['first'] as $name) {
            if ($staff === $name || $staffLead === $name) {
                return true;
            }
        }

        return false;
    }
}
