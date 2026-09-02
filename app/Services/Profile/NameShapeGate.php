<?php

namespace App\Services\Profile;

use Illuminate\Support\Str;

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
        // 2026-09-02 (melbournehairspecialist): the qualifiers that made
        // "MELBOURNE HAIR SPECIALIST" read as a name.
        'specialist', 'specialists', 'expert', 'experts', 'pro', 'educator', 'mentor',
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
     * Whether an Instagram username carries the person's OWN name, and so is
     * decoration around it rather than a brand in its own right.
     *
     * The handle seed turns on this question (2026-09-02, owner-approved after
     * both candidate rules were run over the 120 live IG builds on dev).
     * `ryanfitzsimonshair`/"Ryan Fitzsimons" carries "ryan", so the cleaned
     * name still wins and trims the noise. `themetapunter`/"Joe Osborne"
     * carries neither token — that username is a chosen brand and keeps the
     * handle.
     *
     * FALSE for a name field that is not a person's name at all ("Melbourne
     * Cake decorator", bare "Lucy"): those are the ~30 dev builds whose handle
     * is today a slugged description or a first name the next Lucy cannot have.
     *
     * Fails toward the NAME when there is no username to prefer, so an empty
     * ref can never seed HandleAllocator with 'professional'.
     */
    public static function handleCarriesName(string $username, string $name): bool
    {
        $handle = self::letters($username);
        if ($handle === '') {
            return true;
        }

        if (! self::isPersonShaped($name)) {
            return false;
        }

        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $token) {
            $letters = self::letters($token);
            // 3 is the evidence floor: a two-letter token ("jo", "an") appears
            // inside an unrelated handle by accident far too often to be proof
            // that the person put their own name there.
            if (mb_strlen($letters) >= 3 && str_contains($handle, $letters)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shape of a person's full name: two or three tokens, each at least two
     * letters, none a descriptor. "Lucy" (one token) and "Melbourne Cake
     * decorator" (every token a descriptor) are both rejected, which is the
     * point — neither is a name, and neither should seed a handle.
     */
    private static function isPersonShaped(string $name): bool
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', trim($name)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        if (count($tokens) < 2 || count($tokens) > 3) {
            return false;
        }

        foreach ($tokens as $token) {
            if (mb_strlen(self::letters($token)) < 2 || self::isDescriptor($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lowercase ASCII letters only.
     *
     * Str::ascii, NEVER iconv('UTF-8', 'ASCII//TRANSLIT', …): iconv delegates to
     * the C library, so "Böhmer" folds to `bo"hmer` on macOS and `b?hmer` on
     * Cloud's glibc, and this value is COMPARED. Str::slug folds through the
     * same Str::ascii table, so this predicate and HandleAllocator::base()
     * cannot disagree about an accented name.
     */
    private static function letters(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z]/i', '', Str::ascii($value)));
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

    /**
     * Re-join a letter-spaced string: "S T U D I O  B I D E" → "STUDIO BIDE".
     *
     * A run of 2+ spaces is a hard word break AND so is any token of two or
     * more characters. The first pass had only the former, which is fine for
     * studiobide (double-spaced between words) and mangles everything spaced
     * with single spaces throughout: jay.ink.academy's "J A Y - I N K ACADEMY"
     * became "JAY-INKACADEMY", welding a whole word onto the letters. We were
     * shipping a string worse than the one we set out to rescue, and the plan's
     * fixture asserted isLetterSpaced() on that exact name while never once
     * asserting what the fold did with it.
     */
    public static function foldLetterSpacing(string $name): string
    {
        $words = [];
        foreach (preg_split('/\s{2,}/u', trim($name)) ?: [] as $segment) {
            $run = '';
            foreach (preg_split('/\s+/u', $segment) ?: [] as $token) {
                if ($token === '') {
                    continue;
                }
                if (mb_strlen($token) === 1) {
                    $run .= $token;

                    continue;
                }
                if ($run !== '') {
                    $words[] = $run;
                    $run = '';
                }
                $words[] = $token;
            }
            if ($run !== '') {
                $words[] = $run;
            }
        }

        return implode(' ', $words);
    }

    /**
     * A name with pictographs removed and whitespace re-collapsed.
     *
     * Emoji were rejected out of first/last from the start and left untouched
     * in display_name, which is the column the sitepage renders largest and
     * the column PersonNameMatch reads first — so "Lucy Nguyen ✨" was refused
     * whole by the review matcher for one character, and with first_name empty
     * (which is what this gate writes when it rejects a part) that account
     * published no reviews at all.
     *
     * Symbols and format characters only: \p{So} (✨ 🍓), \p{Sk} (skin-tone
     * modifiers), \p{Cf} (ZWJ) and the variation selectors. Punctuation stays
     * — the pipe in "SIMON DOYLE | Barber & Educator" is a legible separator,
     * and removing it would produce a run-on that reads as a four-word name.
     *
     * Interior whitespace is deliberately NOT collapsed here. It has to be,
     * eventually — removing " ✨ " leaves a two-space hole — but a run of two
     * spaces is the only word boundary a letter-spaced name carries, and
     * collapsing before the fold turned studiobide's "S T U D I O  B I D E"
     * into "STUDIOBIDE". apply() collapses after folding instead.
     */
    public static function stripSymbols(string $name): string
    {
        return trim((string) preg_replace('/[\p{So}\p{Sk}\p{Cf}\x{FE0E}\x{FE0F}]/u', '', $name));
    }

    /**
     * A person's name from a run-together handle, when the handle plainly
     * contains one: "cassandraskinnerpt" → "Cassandra Skinner". Returns null
     * unless BOTH parts are real-looking and neither is a descriptor — a wrong
     * name is worse than the vanity string we already have.
     */
    public static function nameFromHandle(string $handle): ?string
    {
        // The person's OWN word boundary first (2026-09-02): `jordan.dimitriadis`
        // / `jordan_dimitriadis` is two alphabetic parts the person separated
        // themselves and needs no surname dictionary — the scan below is for
        // run-together handles, and its lists miss real names (neither
        // "jordan" nor "dimitriadis" is in them; melbournehairspecialist).
        if (preg_match('/^([a-z]{2,})[._]([a-z]{2,})(?:[._]([a-z]{2,}))?$/i', trim($handle), $m) === 1) {
            $parts = array_map(
                static fn (string $p): string => mb_strtolower($p),
                // No array_values(): $m[1]/$m[2] are [a-z]{2,} so never falsy,
                // leaving only the OPTIONAL third part filterable — and it is
                // trailing, so what survives is already a list.
                array_filter([$m[1], $m[2], $m[3] ?? ''], static fn (string $p): bool => $p !== ''),
            );
            $noise = array_filter($parts, static fn (string $p): bool => self::isDescriptor($p) || in_array($p, self::COMMON_SUFFIXES, true));
            if ($noise === []) {
                return implode(' ', array_map(static fn (string $p): string => mb_convert_case($p, MB_CASE_TITLE, 'UTF-8'), $parts));
            }
        }

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

        // Before isLetterSpaced(), not after: a stray emoji is a token, and a
        // token that is one character wide counts toward the single-letter
        // ratio that decides whether this string is letter-spaced at all.
        $display = self::stripSymbols($display);

        if ($display !== '' && self::isLetterSpaced($display)) {
            $display = self::foldLetterSpacing($display);
            $first = $last = '';
        }

        // Now that the fold has read the double spaces it needed, the holes a
        // stripped emoji left ("Lucy ✨ Nguyen" → "Lucy  Nguyen") close.
        $display = trim((string) preg_replace('/\s+/u', ' ', $display));

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

        // ALL-CAPS person names read as shouting on the page (owner,
        // 2026-09-02): two or three alphabetic tokens, none a descriptor, in
        // caps → title case. Brand strings keep their casing.
        if ($display !== '' && mb_strtoupper($display) === $display
            && preg_match('/^[\p{L}\p{M}\'\-]+(?: [\p{L}\p{M}\'\-]+){1,2}$/u', $display) === 1
            && array_filter(explode(' ', $display), static fn (string $t): bool => self::isDescriptor($t)) === []) {
            $title = static fn (string $v): string => mb_convert_case(mb_strtolower($v), MB_CASE_TITLE, 'UTF-8');
            $display = $title($display);
            $first = $first !== '' ? $title($first) : '';
            $last = $last !== '' ? $title($last) : '';
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
