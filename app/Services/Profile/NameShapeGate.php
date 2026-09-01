<?php

namespace App\Services\Profile;

/**
 * Judges the SHAPE of a derived name, after BioIntelligence's gateNames() has
 * judged its PROVENANCE.
 *
 * The two gates catch different things and both are needed. gateNames() asks
 * "did the model invent a word?" — which is why "Melbourne Cake decorator"
 * passed it on 2026-08-31: every word is the subject's own. This one asks
 * "is this a NAME?", which is the question that was never being asked.
 *
 * Deterministic on purpose (owner decision, 2026-08-31). The AI prompt already
 * instructs "a role/descriptor word is NEVER a surname" and the model returned
 * first_name "Melbourne", last_name "Trainer" anyway — so the guarantee has to
 * live in code that can be tested, not in a prompt that can be ignored.
 *
 * The 2026-09-01 re-audit is the fixture book: 8 of 12 fresh builds shipped a
 * fabricated split ("The"/"Edit", "Tension"/"Music", "Fade"/"Barbershop"), and
 * first_name "The" is the exact poison the review person-scope matcher then
 * drinks — it admits a whole venue's reviews on lead-token "The".
 */
final class NameShapeGate
{
    /** Role, craft and place words that are never a person's given or family name. */
    private const DESCRIPTORS = [
        // roles and crafts
        'artist', 'academy', 'barber', 'barbers', 'barbershop', 'beauty', 'bar', 'cake', 'celebrant',
        'chef', 'clinic', 'coach', 'coaching', 'creator', 'decorator', 'dentist', 'design', 'designer',
        'dj', 'doctor', 'dog', 'driving', 'edit', 'editions', 'esthetician', 'fitness', 'florist',
        'flowers', 'groomer', 'grooming', 'hair', 'hairdresser', 'hmua', 'instructor', 'lashes',
        'makeup', 'massage', 'music', 'musician', 'nail', 'nails', 'nutrition', 'nutritionist', 'osteo',
        'photographer', 'photography', 'physio', 'physiotherapy', 'pilates', 'pt', 'salon', 'school',
        'services', 'shop', 'skin', 'spa', 'store', 'studio', 'stylist', 'tattoo', 'tattooist',
        'tension', 'therapies', 'therapist', 'therapy', 'trainer', 'training', 'tutor', 'wedding',
        'weddings', 'yoga',
        // AU/NZ/UK/US places that turn up as the leading token of a vanity string
        'adelaide', 'auckland', 'brisbane', 'canberra', 'chicago', 'darwin', 'gold', 'hobart', 'london',
        'melbourne', 'newcastle', 'perth', 'sydney', 'wellington', 'york',
        // generic qualifiers
        'best', 'mobile', 'official', 'private', 'the', 'unlimited', 'your',
    ];

    /** Trailing handle noise a run-together handle sheds before splitting. */
    private const COMMON_SUFFIXES = ['pt', 'hair', 'makeup', 'photo', 'photography', 'tattoo', 'official', 'au', 'nz', 'uk'];

    public static function isDescriptor(string $token): bool
    {
        return in_array(mb_strtolower(trim($token, " \t\n\r\0\x0B.,'\"")), self::DESCRIPTORS, true);
    }

    /**
     * A name written as spaced-out single letters ("S T U D I O  B I D E").
     * Same visual defect class as the Unicode-fold bug fixed 2026-08-30, but
     * in plain ASCII, so the fold never saw it.
     */
    public static function isLetterSpaced(string $name): bool
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', trim($name)) ?: [],
            static fn (string $t): bool => $t !== '',
        ));
        if (count($tokens) < 4) {
            return false;
        }
        $singles = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t) === 1 && preg_match('/\p{L}/u', $t)) {
                $singles++;
            }
        }

        return $singles >= (int) ceil(count($tokens) * 0.6);
    }

    /** Re-join a letter-spaced string: "S T U D I O  B I D E" → "STUDIO BIDE". */
    public static function foldLetterSpacing(string $name): string
    {
        $words = preg_split('/\s{2,}/u', trim($name)) ?: [];

        return trim(implode(' ', array_map(
            static fn (string $w): string => (string) preg_replace('/\s+/u', '', $w),
            $words,
        )));
    }

    /**
     * A person's name from a run-together handle, when the handle plainly
     * contains one: "cassandraskinnerpt" → "Cassandra Skinner". Returns null
     * unless BOTH parts are real-looking and neither is a descriptor — a wrong
     * name is worse than the vanity string we already have.
     */
    public static function nameFromHandle(string $handle): ?string
    {
        $h = mb_strtolower((string) preg_replace('/[^a-z]/i', '', $handle));
        foreach (self::COMMON_SUFFIXES as $suffix) {
            if (str_ends_with($h, $suffix) && mb_strlen($h) - mb_strlen($suffix) >= 8) {
                $h = mb_substr($h, 0, mb_strlen($h) - mb_strlen($suffix));
                break;
            }
        }
        if (mb_strlen($h) < 8 || mb_strlen($h) > 24) {
            return null;
        }
        $names = self::nameWords();
        for ($i = 3; $i <= mb_strlen($h) - 3; $i++) {
            $first = mb_substr($h, 0, $i);
            $last = mb_substr($h, $i);
            if (isset($names['first'][$first]) && isset($names['last'][$last])
                && ! self::isDescriptor($first) && ! self::isDescriptor($last)) {
                return ucfirst($first).' '.ucfirst($last);
            }
        }

        return null;
    }

    /**
     * @param  array{displayName: ?string, firstName: ?string, lastName: ?string}  $names
     * @return array{displayName: ?string, firstName: ?string, lastName: ?string}
     */
    public static function apply(array $names, string $handle, string $fullName): array
    {
        $display = trim((string) ($names['displayName'] ?? ''));
        $first = trim((string) ($names['firstName'] ?? ''));
        $last = trim((string) ($names['lastName'] ?? ''));

        if ($display !== '' && self::isLetterSpaced($display)) {
            $display = self::foldLetterSpacing($display);
            $first = $last = '';
        }

        // A part that is a descriptor, an emoji, or a single letter is not a
        // name part. Both go, together: half a parsed name is not a name.
        $bad = static fn (string $part): bool => $part === ''
            || mb_strlen($part) < 2
            || self::isDescriptor($part)
            || preg_match('/[^\p{L}\p{M}\'\- ]/u', $part) === 1;
        if ($bad($first) || $bad($last)) {
            $first = $last = '';
        }

        // The display name is a descriptor phrase (no token that is not one) —
        // prefer a name the handle can give us over a category label.
        if ($display !== '') {
            $tokens = array_values(array_filter(preg_split('/\s+/u', $display) ?: []));
            $real = array_filter($tokens, static fn (string $t): bool => ! self::isDescriptor($t) && mb_strlen($t) > 1);
            if ($real === [] || count($real) < count($tokens) / 2) {
                $fromHandle = self::nameFromHandle($handle);
                if ($fromHandle !== null) {
                    $display = $fromHandle;
                    [$first, $last] = explode(' ', $fromHandle, 2);
                }
            }
        }

        return [
            'displayName' => $display !== '' ? $display : null,
            'firstName' => $first !== '' ? $first : null,
            'lastName' => $last !== '' ? $last : null,
        ];
    }

    /** @return array{first: array<string,true>, last: array<string,true>} */
    private static function nameWords(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $load = static function (string $file): array {
            $path = resource_path("names/{$file}");

            return is_file($path)
                ? array_fill_keys(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), true)
                : [];
        };

        return $cache = ['first' => $load('given.txt'), 'last' => $load('family.txt')];
    }
}
