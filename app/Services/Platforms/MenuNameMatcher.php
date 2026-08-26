<?php

namespace App\Services\Platforms;

/**
 * Cross-source menu item matching beyond exact normalized names (menu
 * deep-links plan B3 + backend-fixes item 2, 2026-08-26). Every failure
 * pattern here was observed live on ollies' website-scan vs Uber Eats menus;
 * the confirmed misses are pinned as fixtures in MenuNameMatcherTest.
 *
 * Three passes, applied by callers in order:
 *   1. exact normalized name (normalizeName — existing behaviour, callers
 *      already do this via the coord / index key);
 *   2. variantKey(): normalized with parentheticals stripped, generic
 *      wrappers unwrapped ("Cold Brew Bags. (Italo Concentrate 1.2l)" →
 *      the parenthetical is the identity when the outer name is generic),
 *      and units normalized ("1.2L" == "1.2l", "7 Sachets" == "7sachets");
 *   3. brandLineMatch(): retail-vs-marketplace naming of one product
 *      ("Orthodox House Espresso Blend" vs "Orthodox Whole Beans") — a
 *      shared distinctive brand prefix whose remainders are ONLY generic
 *      product-form words merges; a SIZE mismatch (a unit token on one side
 *      only, or two different unit tokens) downgrades to a flag, per the
 *      "never merge across genuinely different sizes" guardrail.
 *
 * Price is deliberately ABSENT from every pass: Uber applies markups and
 * promo discounts, so equal price can corroborate but unequal price must
 * never veto (doc rule 4) — and a matcher that never reads price cannot
 * break that rule.
 *
 * The same-source guardrail ("Almighty" vs "Almighty Soda" stays two dishes)
 * is the CALLER's: both integrations only match a candidate against dishes
 * from OTHER sources, never within one batch.
 */
class MenuNameMatcher
{
    use NormalizesMenuItemNames;

    /**
     * Product-form vocabulary: words that describe WHAT FORM a product takes
     * rather than WHICH product it is. Used to unwrap generic wrappers and to
     * decide whether two names' non-brand remainders describe the same
     * underlying product line.
     *
     * @var list<string>
     */
    private const GENERIC_WORDS = [
        'cold', 'brew', 'bags', 'bag', 'can', 'cans', 'bottle', 'concentrate',
        'espresso', 'coffee', 'blend', 'beans', 'whole', 'ground', 'drip',
        'house', 'filter', 'capsules', 'pods', 'pack', 'box', 'italian',
        'iced', 'hot', 'large', 'small', 'regular',
    ];

    /** Unit tokens: number + unit, or counted packs ("7 sachets", "2pk"). */
    private const UNIT_PATTERN = '/^\d+(?:[.,]\d+)?\s*(?:l|ml|g|kg|pk|pack|packs|sachets?|pcs?|pieces?|x)$/i';

    /**
     * Pass-2 key. Empty string when nothing distinctive remains (callers
     * treat that as "no key" — a name that is ONLY a parenthetical or ONLY
     * generic words cannot safely match anything).
     */
    public function variantKey(string $name): string
    {
        $outer = trim((string) preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)/', ' ', $name) ?? ''));
        $inner = preg_match('/\(([^)]+)\)/', $name, $m) === 1 ? trim($m[1]) : null;

        // Generic-wrapper unwrap: when everything OUTSIDE the parenthetical
        // is product-form vocabulary, the parenthetical is the identity.
        $base = $outer;
        if ($inner !== null && $outer !== '' && $this->allGeneric($outer)) {
            $base = $inner;
        } elseif ($outer === '' && $inner !== null) {
            $base = $inner;
        }

        return $this->unitNormalize($this->normalizeName($base));
    }

    /**
     * Pass 3. 'merge' when the two names share a distinctive brand prefix and
     * their remainders are only product-form words WITH compatible sizes;
     * 'flag' when the brand matches but a size differs (or only one side
     * names one); null when the brands differ or a remainder carries a
     * distinctive non-brand token.
     *
     * @return 'merge'|'flag'|null
     */
    public function brandLineMatch(string $a, string $b): ?string
    {
        $ka = $this->unitNormalize($this->variantKeyLoose($a));
        $kb = $this->unitNormalize($this->variantKeyLoose($b));
        $ta = explode(' ', $ka);
        $tb = explode(' ', $kb);

        $brandA = $this->brandPrefix($ta);
        $brandB = $this->brandPrefix($tb);
        if ($brandA === [] || $brandB === []) {
            return null;
        }
        // Prefix-subset brand match: "italo" ⊂ "italo disco" is the same
        // distinctive line; disjoint brands are different products.
        $shorter = count($brandA) <= count($brandB) ? $brandA : $brandB;
        $longer = $shorter === $brandA ? $brandB : $brandA;
        if ($shorter !== array_slice($longer, 0, count($shorter))) {
            return null;
        }

        $restA = array_slice($ta, count($brandA));
        $restB = array_slice($tb, count($brandB));

        // Brand-swallowed-product guard: when one side's "brand" runs LONGER
        // than the shared prefix AND that side has no product-form words after
        // it, the extra tokens ARE the product ("Orthodox Chocolate Bar" —
        // chocolate bar is the product, not brand elaboration like "Disco" in
        // "Italo Disco Whole Beans"). Different product → no match.
        $extra = array_slice($longer, count($shorter));
        if ($extra !== []) {
            $longerRest = $longer === $brandA ? $restA : $restB;
            if ($longerRest === []) {
                return null;
            }
        }
        [$unitsA, $wordsA] = $this->splitUnits($restA);
        [$unitsB, $wordsB] = $this->splitUnits($restB);

        // A distinctive non-generic token in a remainder means this is a
        // DIFFERENT product of the same brand, not a renaming — no match.
        foreach ([...$wordsA, ...$wordsB] as $word) {
            if (! $this->isGenericWord($word)) {
                return null;
            }
        }

        // Size guardrail: identical unit sets merge; any disagreement flags.
        if ($unitsA === $unitsB) {
            return 'merge';
        }

        return 'flag';
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** variantKey without the all-generic emptiness rule — pass 3 wants the tokens either way. */
    private function variantKeyLoose(string $name): string
    {
        $outer = trim((string) preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)/', ' ', $name) ?? ''));
        $inner = preg_match('/\(([^)]+)\)/', $name, $m) === 1 ? trim($m[1]) : null;
        $base = $outer !== '' && $inner !== null && $this->allGeneric($outer) ? $inner : ($outer !== '' ? $outer : (string) $inner);

        return $this->normalizeName($base);
    }

    /** Leading tokens up to the first generic/unit word — the distinctive brand/line prefix. */
    private function brandPrefix(array $tokens): array
    {
        $brand = [];
        foreach ($tokens as $token) {
            if ($this->isGenericWord($token) || $this->isUnit($token)) {
                break;
            }
            $brand[] = $token;
        }

        return $brand;
    }

    /** @return array{0: list<string>, 1: list<string>} [units, plain words] */
    private function splitUnits(array $tokens): array
    {
        $units = [];
        $words = [];
        // Join "number unit" pairs the normalizer split ("1 2l" from "1.2L"
        // stays two tokens; "2 l" → "2l").
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && preg_match('/^\d+$/', $token) === 1 && $this->isUnit($token.$next)) {
                $units[] = $token.$next;
                $i++;

                continue;
            }
            if ($this->isUnit($token)) {
                $units[] = $token;
            } else {
                $words[] = $token;
            }
        }
        sort($units);

        return [$units, $words];
    }

    private function isUnit(string $token): bool
    {
        return preg_match(self::UNIT_PATTERN, $token) === 1;
    }

    private function isGenericWord(string $word): bool
    {
        return in_array($word, self::GENERIC_WORDS, true) || preg_match('/^\d+$/', $word) === 1;
    }

    private function allGeneric(string $phrase): bool
    {
        $tokens = explode(' ', $this->normalizeName($phrase));
        foreach ($tokens as $token) {
            if ($token !== '' && ! $this->isGenericWord($token) && ! $this->isUnit($token)) {
                return false;
            }
        }

        return $tokens !== [];
    }

    /** Glue split number+unit pairs so "1.2L", "1.2l" and "1 2 l" key equal. */
    private function unitNormalize(string $normalized): string
    {
        // normalizeName turns "1.2l" into "1 2l" (dot → space) — re-glue the
        // decimal, then any remaining bare number+unit pair.
        $out = (string) preg_replace('/\b(\d+) (\d+(?:l|ml|g|kg|pk))\b/', '$1.$2', $normalized);
        $out = (string) preg_replace('/\b(\d+) (\d+) (l|ml|g|kg|pk|sachets?)\b/', '$1.$2$3', $out);
        $out = (string) preg_replace('/\b(\d+) (l|ml|g|kg|pk|sachets?)\b/', '$1$2', $out);

        return $out;
    }
}
