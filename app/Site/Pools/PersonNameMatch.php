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
 * keeping a copy.
 *
 * REWRITTEN 2026-09-01 after we published other people's reviews on real
 * named individuals' pages. The verbatim-extracted version asked only "is the
 * first word of display_name/first_name at least 3 characters long?" and then
 * hunted that word in review prose. broken-oven's first_name is "The Broken
 * Oven Pizza Bar", so the word was **the** — present in seven of that venue's
 * eleven Google reviews, which is exactly the seven we published on a named
 * individual's page: a 1-star about a cappuccino, praise for a barber called
 * Shuki, praise for a stylist called Sayuri. Three strangers' words under one
 * person's name.
 *
 * The owner rule (2026-08-31) is the design: a review belongs on a person's
 * page only if it MENTIONS THEM BY NAME. So this class has to be able to say
 * "the string on file is not a name" — 37 of 84 partna accounts hold a
 * descriptor, an emoji or a raw handle in display_name, and until that is
 * fixed at the source, trusting the column is the defect. Every uncertainty
 * returns null / false, and null means the caller suppresses.
 *
 * Three tiers, strongest first, and the TEXT half now lives here too rather
 * than in PoolResolver — a second weaker implementation of the same idea is
 * how this drifted in the first place:
 *   - matchesStaffName(): the vendor's own structured attribution.
 *   - matchesText() full: a multi-token name appearing as a sequence.
 *   - matchesText() first: a LONE name token, which must additionally appear
 *     capitalised, because a person's name in prose is a proper noun.
 *
 * words() is shared with FreshaStaffMatcher, whose vanity-name tier already
 * solved "does this person's name appear in this free text" against real
 * scraped data — same tokenizer, same 3-character signal floor, one algorithm.
 *
 * Regression net: tests/Unit/Site/Pools/PersonNameMatchTest.php (the live
 * broken-oven and ollies strings) and tests/Feature/Content/ReviewsPoolTest.php.
 */
class PersonNameMatch
{
    /**
     * Words that mean the string is a DESCRIPTOR, not a person's name. One hit
     * anywhere in the string disqualifies the whole string — "Trae the Barber"
     * is a bio line whether or not Trae is real, and the clean `first_name`
     * column beside it is where the name is read from instead.
     *
     * This is not a dictionary and can never be complete. It is the failure
     * classes actually present in the 84 partna accounts on 2026-09-01:
     * connectives (the "the" that caused the incident), trade nouns, city
     * names, honorifics, corporate suffixes and our own test-account noise.
     * The capitalisation requirement in matchesText() is what bounds the
     * damage of the class we have not thought of yet.
     *
     * Deliberately excludes single letters: "Anthony A" is a real account and
     * a middle initial is not a descriptor.
     */
    private const NOT_A_NAME = [
        // Connectives. "Lower East by" ends in one; "The Broken Oven Pizza
        // Bar" starts with one, and that is the word we published on.
        'the', 'and', 'or', 'of', 'for', 'by', 'at', 'in', 'on', 'to', 'with',
        'from', 'my', 'our', 'your', 'their',

        // Honorifics and stage prefixes — "DJ Ruby" is not evidence that a
        // review saying "ruby" is about this person.
        'dj', 'dr', 'mr', 'mrs', 'ms', 'miss', 'sir', 'prof', 'official', 'real',

        // Trades and premises. Every one of these appears in the reviews we
        // would be matching against, which is precisely why they are not names.
        'aesthetics', 'artist', 'artistry', 'artists', 'bakery', 'bar', 'barber',
        'barbers', 'barbershop', 'beauty', 'bistro', 'boutique', 'brewery',
        'brewing', 'brow', 'brows', 'burger', 'cafe', 'café', 'catering', 'chef',
        'clinic', 'coffee', 'collective', 'colourist', 'cook', 'creative',
        'creator', 'deli', 'dental', 'dentist', 'design', 'designer', 'doctor',
        'espresso', 'events', 'fitness', 'florist', 'flowers', 'gym', 'hair',
        'hairdresser', 'hairdressing', 'health', 'hmua', 'instructor', 'kebab',
        'kitchen', 'lash', 'lashes', 'makeup', 'massage', 'media', 'mua',
        'music', 'nail', 'nails', 'oven', 'pastry', 'patisserie', 'people',
        'photo', 'photographer', 'photography', 'piercing', 'pilates', 'pizza',
        'pizzeria', 'property', 'pub', 'roasters', 'salon', 'shop', 'skin',
        'spa', 'store', 'studio', 'studios', 'tattoo', 'tattooist', 'tattoos',
        'therapies', 'therapist', 'therapy', 'trainer', 'training', 'wedding',
        'weddings', 'wellness', 'yoga',

        // Corporate suffixes.
        'co', 'company', 'group', 'inc', 'llc', 'ltd', 'pty', 'service',
        'services', 'solutions',

        // Where, not who. The fail direction is closed: a real Ms West loses
        // her text-mention tier and keeps her staff-attribution one.
        'adelaide', 'auckland', 'australia', 'australian', 'brisbane', 'canberra',
        'central', 'city', 'coast', 'darwin', 'east', 'geelong', 'gold', 'home',
        'inner', 'local', 'london', 'lower', 'melbourne', 'mobile', 'newcastle',
        'north', 'online', 'perth', 'private', 'south', 'sydney', 'upper',
        'wellington', 'west', 'wollongong',

        // Single ordinary words seen standing alone in a first_name column
        // ("Fine Line Tattoo Artist ✨Elle✨" leaves "Fine"; "Found By The
        // Hound" leaves "Found"; "Body Tune" leaves "Body").
        'body', 'fine', 'found', 'line',

        // Our own fixtures — these accounts exist and would otherwise match.
        'account', 'admin', 'demo', 'fixture', 'sample', 'showcase', 'staff',
        'test', 'user',
    ];

    /**
     * The name forms a partna account can be recognised by: full display
     * name, first_name, and each of their leading tokens. Null when NOTHING
     * usable is on file — which now includes a descriptor, a handle, a string
     * carrying digits or emoji, and a lead token under 3 characters. Null is
     * the fail-closed answer: the caller suppresses everything it cannot
     * attribute.
     *
     * @return array{full: list<string>, first: list<string>}|null
     */
    public static function tokens(?string $displayName, ?string $firstName): ?array
    {
        $full = [];
        $first = [];
        foreach ([[$displayName, true], [$firstName, false]] as [$value, $isFull]) {
            $words = self::nameWords($value);
            if ($words === null) {
                continue;
            }
            $name = implode(' ', $words);
            // Only a MULTI-word display name is a "full name" — a lone token
            // (and the first_name column, which is one by construction) is a
            // first-token match and rides the length floor below.
            if ($isFull && count($words) > 1) {
                $full[] = $name;
            }
            // A 1–2 letter lead token ("dj", initials) matches half the
            // dictionary — too weak to attribute a stranger's words with.
            if (mb_strlen($words[0]) >= 3) {
                $first[] = $words[0];
            }
        }

        $full = array_values(array_unique($full));
        $first = array_values(array_unique(array_diff($first, $full)));

        return $full === [] && $first === [] ? null : ['full' => $full, 'first' => $first];
    }

    /**
     * Does a review's structured staff attribution name this person?
     *
     * Null $names (nothing usable on file) is false, not true: an attribution
     * we cannot verify as the account holder's is a colleague's.
     *
     * @param  array{full: list<string>, first: list<string>}|null  $names
     */
    public static function matchesStaffName(?string $staffName, ?array $names): bool
    {
        if ($names === null) {
            return false;
        }

        $staff = self::splitName((string) $staffName);
        if ($staff === []) {
            return false;
        }

        $joined = implode(' ', $staff);
        $lead = $staff[0];
        foreach ($names['full'] as $name) {
            // Lead-vs-lead because vendor team lists carry "Simon" and
            // "Simon D." for a Simon Doyle.
            if ($joined === $name || $lead === explode(' ', $name)[0]) {
                return true;
            }
        }
        foreach ($names['first'] as $name) {
            if ($joined === $name || $lead === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the review's own prose name this person?
     *
     * A multi-token full name is a strong claim on its own and matches
     * case-insensitively. A LONE token is not: it is one ordinary word away
     * from matching every review a venue has, which is the incident. So a
     * single-token needle must appear capitalised — a name in prose is a
     * proper noun, and a needle that only ever shows up lower-case ("fresh
     * lime cordial" on an account whose first_name is "Lime") is prose
     * colliding with the column, not an attribution. The cost is a review
     * typed entirely in lower case, which fails closed; the benefit is that
     * the next descriptor NOT_A_NAME fails to recognise cannot repeat this.
     *
     * @param  array{full: list<string>, first: list<string>}|null  $names
     */
    public static function matchesText(?string $text, ?array $names): bool
    {
        $text = (string) $text;
        if ($text === '' || $names === null) {
            return false;
        }

        foreach ($names['full'] as $name) {
            $sequence = implode('[\s\-]+', array_map(
                static fn (string $word): string => preg_quote($word, '/'),
                explode(' ', $name),
            ));
            if (preg_match(self::boundary($sequence), $text) === 1) {
                return true;
            }
        }

        foreach ($names['first'] as $name) {
            if (preg_match_all(self::boundary(preg_quote($name, '/')), $text, $hits) < 1) {
                continue;
            }
            foreach ($hits[0] as $hit) {
                if (self::startsCapitalised($hit)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lowercase word tokens of a free-text string, punctuation and emoji
     * treated as separators. Shared with FreshaStaffMatcher's vanity-name
     * tier — same tokenizer on both sides of "does this person's name appear
     * in this text", so the two cannot drift apart the way the pool and the
     * DSAR export nearly did.
     *
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value)) ?: [];

        return array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
    }

    /**
     * The lowercase word tokens of a string IF that string is a person's
     * name, and null if it is not. This is the judgement the old matcher
     * never made.
     *
     * @return list<string>|null
     */
    private static function nameWords(?string $value): ?array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
        if ($raw === '') {
            return null;
        }

        // A name is letters, spaces, apostrophes and hyphens. A digit, an
        // emoji, a pipe, an ampersand, a dot or an @ means we are holding a
        // handle, an email local part or an Instagram bio line — "ST. ALi
        // Coffee", "SIMON DOYLE | Barber & Educator", "tobiasindarwin+fableqa2".
        // The clean first_name column beside it is read separately.
        if (preg_match("/[^\p{L}\p{M} '’\-]/u", $raw) === 1) {
            return null;
        }

        // One hyphen-joined run with no spaces is a URL slug, not a
        // double-barrelled name: "camilla-reynolds", "user-ot9fss".
        if (! str_contains($raw, ' ') && str_contains($raw, '-')) {
            return null;
        }

        $words = self::splitName($raw);
        if ($words === []) {
            return null;
        }

        foreach ($words as $word) {
            if (in_array($word, self::NOT_A_NAME, true)) {
                return null;
            }
        }

        return $words;
    }

    /**
     * A name split into lowercase tokens on whitespace and hyphens, keeping
     * apostrophes inside the word so "O'Brien" stays one token.
     *
     * @return list<string>
     */
    private static function splitName(string $value): array
    {
        $parts = preg_split("/[^\p{L}\p{N}'’]+/u", mb_strtolower(trim($value))) ?: [];
        $parts = array_map(static fn (string $p): string => trim($p, "'’"), $parts);

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** Word-boundary by letters/digits, not \b — names are unicode. */
    private static function boundary(string $pattern): string
    {
        return '/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu';
    }

    private static function startsCapitalised(string $hit): bool
    {
        $head = mb_substr($hit, 0, 1);

        return mb_strtoupper($head) === $head && mb_strtolower($head) !== $head;
    }
}
