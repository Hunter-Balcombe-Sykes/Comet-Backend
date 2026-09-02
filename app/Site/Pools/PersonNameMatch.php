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
 *   - matchesText() first: a LONE name token.
 *
 * Both matchesText() tiers require the needle to appear as a PROPER NOUN.
 * The 2026-09-01 fix put that guard on the lone-token tier only, and its
 * residual note then claimed the guard bounded the whole class of descriptors
 * the lexicon misses — "limits it to sentence-start collisions rather than
 * every review". That was true of a one-word needle and FALSE of a two-word
 * one: the full tier matched case-insensitively anywhere, so a display name
 * like "Lime Tree" published every review saying "under the lime tree". The
 * note pointed the next reader at the safe half of a half-guarded rule, which
 * is worse than no note. Corrected 2026-09-01 (second pass): the guard is on
 * both tiers, EVERY word of the match must be capitalised, and the residual
 * that actually survives is narrower — a descriptor the lexicon misses can
 * still be reached by prose that capitalises all of it, i.e. sentence-start
 * for a lone token and a Title Case run for a full name.
 *
 * The cost is stated plainly because it is chosen: a review typed entirely in
 * lower case, and a name in a caseless script, cannot clear the guard and so
 * fail closed. Publishing a stranger's words under someone's name is the harm
 * this class exists to prevent; withholding a review is not.
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
     *
     * SUPERSET of NameShapeGate::DESCRIPTORS since 2026-09-01, pinned by "it
     * refuses every word the write-side name gate already calls a descriptor".
     * The two lists were assembled from the same 84 accounts a day apart and
     * still disagreed about 29 words, and each disagreement is a hole with a
     * direction: the gate declines to WRITE "academy" as a surname, this class
     * then READS it out of display_name and goes hunting for it in review
     * prose. The relation is one-way — this list is properly larger, because
     * refusing costs the gate a real person's name column and costs this class
     * only a review it publishes on the venue instead.
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
        // 2026-09-02, in step with NameShapeGate::DESCRIPTORS.
        'specialist', 'specialists', 'expert', 'experts', 'pro', 'educator', 'mentor',
        'weddings', 'wellness', 'yoga',

        // Trades the write-side gate already knew and this list did not. Every
        // one arrived from NameShapeGate::DESCRIPTORS when the two were
        // reconciled; "academy" is the one that had a live carrier
        // (jay.ink.academy), the rest are the same class caught early.
        'academy', 'cake', 'celebrant', 'coach', 'coaching', 'decorator', 'dog',
        'driving', 'edit', 'editions', 'esthetician', 'groomer', 'grooming',
        'musician', 'nutrition', 'nutritionist', 'osteo', 'physio',
        'physiotherapy', 'pt', 'school', 'stylist', 'tension', 'tutor',

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
        // Same reconciliation. "York" is a real surname and loses its
        // text-mention tier here, on the settled rule stated above for West.
        'chicago', 'hobart', 'york',

        // Generic qualifiers, likewise from the gate's list.
        'best', 'unlimited',

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
            //
            // `> 1`, not `>= 1`, and the difference is the whole tier system:
            // a one-token `full` entry is a one-word needle wearing the strong
            // tier's name, and it would ALSO skip the 3-character floor two
            // lines down (array_diff() then deletes it from `first`, so "K"
            // would become a matchable name). Pinned by "keeps a single-token
            // display name out of the full tier".
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
     * A needle — lone token or full sequence — counts only where it appears
     * as a PROPER NOUN, because that is what a person's name is in prose. A
     * needle that only ever shows up lower-case ("fresh lime cordial" on an
     * account whose first_name is "Lime", "sat under the lime tree" on one
     * whose display_name is "Lime Tree") is prose colliding with the column,
     * not an attribution.
     *
     * The 2026-09-01 fix guarded only the lone-token tier, on the reasoning
     * that a multi-token sequence is a strong claim by itself. It is not
     * strong enough: NOT_A_NAME is a list of the failure classes we have SEEN,
     * so the tier that admits an unrecognised descriptor unguarded is the tier
     * that repeats the incident — and it repeats it with the stronger claim.
     * Both tiers now go through named(), so there is no half of this rule left
     * to drift.
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
            if (self::named(self::boundary($sequence), $text)) {
                return true;
            }
        }

        foreach ($names['first'] as $name) {
            if (self::named(self::boundary(preg_quote($name, '/')), $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does $pattern occur in $text at least once written as a proper noun?
     *
     * Every occurrence is examined, not the first: "the lime cordial, poured
     * by Lime" is an attribution and "poured under the lime tree" is not, and
     * only reading them all can tell the two apart.
     */
    private static function named(string $pattern, string $text): bool
    {
        if (preg_match_all($pattern, $text, $hits) < 1) {
            return false;
        }

        foreach ($hits[0] as $hit) {
            if (self::isProperNoun($hit)) {
                return true;
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

    /**
     * Is EVERY word of a matched run capitalised?
     *
     * Every word, not just the first: "Sunny days at the beach" would clear a
     * first-word-only check for a display_name of "Sunny Days" purely because
     * the sentence started there, which is the sentence-start collision the
     * lone-token tier already has to live with — and a two-word name has no
     * reason to inherit it, since a real mention writes both words.
     *
     * Split on the separators the needle itself is joined by (whitespace and
     * hyphens) so an apostrophe stays inside its word: "O'Brien" is one word
     * with one capital, not "O" plus a lower-case "brien".
     *
     * A caseless script answers false — mb_strtoupper() and mb_strtolower()
     * agree there, so "is this capitalised" has no answer, and no answer is
     * suppression.
     */
    private static function isProperNoun(string $hit): bool
    {
        $words = array_filter(
            preg_split('/[\s\-]+/u', $hit) ?: [],
            static fn (string $word): bool => $word !== '',
        );
        if ($words === []) {
            return false;
        }

        foreach ($words as $word) {
            $head = mb_substr($word, 0, 1);
            if (mb_strtoupper($head) !== $head || mb_strtolower($head) === $head) {
                return false;
            }
        }

        return true;
    }
}
