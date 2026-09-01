<?php

namespace App\Support;

use Illuminate\Support\Str;

// Shared cap for business/workplace names. Raised 15 → 80 (owner, 2026-08-27,
// issue 10): storage keeps the business's real name — "Oxbridge Barbershop
// Kensington" must not become "Oxbridge" — and any tight-space truncation is a
// render-side concern in the sitepage/dashboard, not a storage rule. 80 stays
// as a sanity bound on auto-adopted strings. Manual entry is rejected outright
// by validation (UpsertWorkplaceRequest max:80); a name AUTO-ADOPTED from an
// external source (Google Business) can't be rejected mid-sync, so it's
// silently word-trimmed here instead — used by IdentitySync's 'name'
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
    public static function wordTrim(string $name, int $max = 80): string
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

    /**
     * Item 1b (2026-09-01, owner-approved): trim non-name words off a
     * business's listing name, using evidence we actually hold — the
     * listing's OWN suburb. Google appends the suburb to distinguish
     * multi-location businesses ("The Famished Wolf Kensington"); the
     * sitepage wants the brand. Two deterministic rules, both suffix-only
     * and both conservative — when in doubt the name comes back untouched:
     *
     *  locality-suffix   the name ends with the listing's own suburb →
     *                    strip it (plus any joining comma/dash/pipe).
     *                    Leading position is never touched: "Kensington
     *                    Street Social" keeps its identity.
     *  delimited-suffix  a trailing |/–/— segment whose words are ALL
     *                    locality tokens and/or generic sector words
     *                    ("AKRO STUDIO | ELSTERNWICK BARBERSHOP") → strip
     *                    the segment. A segment carrying any brand-ish word
     *                    survives whole.
     *
     * The output is always a subsequence of the input — trimming only,
     * never rewriting — and never empty. Callers log the decision as
     * name_trim {from, to, rule}.
     *
     * @return array{name: string, rule: ?string}
     */
    public static function trimLocality(string $name, ?string $suburb): array
    {
        $squished = Str::squish($name);
        if ($squished === '') {
            return ['name' => $name, 'rule' => null];
        }

        $suburb = is_string($suburb) ? Str::squish($suburb) : '';

        // delimited-suffix first: it can carry the locality-suffix inside it.
        $segments = preg_split('/\s*[|–—]\s*/u', $squished) ?: [$squished];
        if (count($segments) > 1) {
            $last = (string) end($segments);
            $generic = '/^(barbershop|barbers?|salon|studio|hair|beauty|nails?|spa|clinic|cafe|restaurant|bar|shop|store|tattoo|fitness|gym|co)$/iu';
            $allDisposable = true;
            foreach (preg_split('/\s+/u', $last) ?: [] as $word) {
                $isLocality = $suburb !== '' && mb_stripos($suburb, $word) !== false;
                if (! $isLocality && preg_match($generic, $word) !== 1) {
                    $allDisposable = false;
                    break;
                }
            }
            if ($allDisposable) {
                $trimmed = Str::squish(implode(' ', array_slice($segments, 0, -1)));
                if ($trimmed !== '') {
                    return ['name' => $trimmed, 'rule' => 'delimited-suffix'];
                }
            }
        }

        if ($suburb !== '' && preg_match(
            '/^(.+?)[\s,\-–—|]+'.preg_quote($suburb, '/').'$/iu',
            $squished,
            $m
        ) === 1) {
            $trimmed = Str::squish($m[1]);
            if ($trimmed !== '' && preg_match('/[\pL\pN]/u', $trimmed) === 1) {
                return ['name' => $trimmed, 'rule' => 'locality-suffix'];
            }
        }

        return ['name' => $squished, 'rule' => null];
    }
}
