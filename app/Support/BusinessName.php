<?php

namespace App\Support;

use Illuminate\Support\Str;

// Shared 15-char cap for business/workplace names. Manual entry is rejected
// outright by validation (UpsertWorkplaceRequest max:15); a name AUTO-ADOPTED
// from an external source (Google Business) can't be rejected mid-sync, so
// it's silently word-trimmed here instead — used by IdentitySync's 'name'
// candidate and GoogleBusinessController::maybeAdoptGoogleName.
final class BusinessName
{
    /**
     * Trim $name to at most $max characters, keeping whole words — never
     * cuts mid-word. Accumulates words left-to-right while the joined string
     * still fits; always keeps at least the first word (hard mb_substr-cut
     * when that single word alone is over $max); then drops any trailing
     * token carrying no letter/digit (unicode-aware) so a cut never ends
     * mid-punctuation (e.g. a dangling "&" or "-"). Never returns an empty
     * string for a non-empty input.
     */
    public static function wordTrim(string $name, int $max = 15): string
    {
        $squished = Str::squish($name);
        if ($squished === '') {
            return '';
        }

        $words = explode(' ', $squished);
        $first = $words[0];
        if (mb_strlen($first) > $max) {
            // Same "never end mid-punctuation" contract as the multi-word
            // path below: strip trailing non-letter/digit chars off the hard
            // cut, unless the token is ALL punctuation (degenerate — keep the
            // cut rather than return empty).
            $cut = mb_substr($first, 0, $max);
            $stripped = preg_replace('/[^\pL\pN]+$/u', '', $cut) ?? $cut;

            return $stripped !== '' ? $stripped : $cut;
        }

        $kept = [$first];
        $length = mb_strlen($first);
        for ($i = 1, $count = count($words); $i < $count; $i++) {
            $next = $length + 1 + mb_strlen($words[$i]);
            if ($next > $max) {
                break;
            }
            $kept[] = $words[$i];
            $length = $next;
        }

        // Drop trailing punctuation-only tokens left dangling by the cut
        // above. The first word is never popped, so this can't empty $kept.
        while (count($kept) > 1 && preg_match('/[\pL\pN]/u', $kept[array_key_last($kept)]) !== 1) {
            array_pop($kept);
        }

        $result = implode(' ', $kept);

        return $result !== '' ? $result : mb_substr($squished, 0, $max);
    }
}
