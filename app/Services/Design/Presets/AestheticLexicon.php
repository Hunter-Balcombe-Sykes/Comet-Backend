<?php

namespace App\Services\Design\Presets;

/**
 * The soft ↔ bold aesthetic-expression lexicons + the lean-of-a-text reading,
 * extracted so AestheticExpressionFactor (band 64) and RecipeSignals (the
 * LaunchRecipe trigger deriver, band 70) share ONE copy rather than duplicating
 * the word lists. Pure text scoring — no state, no side effects, nothing
 * sensitive persisted (the caller reads a soft/neutral/bold token and discards
 * the text).
 *
 * SAFETY: this reasons ONLY about an aesthetic DIRECTION (soft / neutral / bold).
 * It never computes, names, or returns anything demographic. The soft/bold word
 * lists describe presentation *style*, not people.
 */
final class AestheticLexicon
{
    public const SOFT = 'soft';

    public const NEUTRAL = 'neutral';

    public const BOLD = 'bold';

    /** Substrings that lean an aesthetic soft (rounded, gentle, delicate presentation). */
    public const SOFT_LEXICON = [
        'beauty', 'cosmetic', 'skincare', 'skin care', 'lash', 'brow', 'nail',
        'floral', 'flower', 'bloom', 'pastel', 'soft', 'gentle', 'delicate',
        'dreamy', 'boutique', 'bridal', 'wellness', 'yoga', 'spa', 'glow',
        'petal', 'blush', 'aesthetic', 'elegant',
    ];

    /** Substrings that lean an aesthetic bold (high-contrast, chunky, hard-edged presentation). */
    public const BOLD_LEXICON = [
        'fitness', 'gym', 'strength', 'iron', 'barber', 'tattoo', 'ink',
        'street', 'streetwear', 'bold', 'raw', 'grit', 'power', 'beast',
        'combat', 'boxing', 'mma', 'crossfit', 'automotive', 'garage',
        'moto', 'forge', 'heavy', 'hardcore',
    ];

    /**
     * The aesthetic lean of a text: SOFT/BOLD if its lexicon clearly dominates,
     * NEUTRAL if the text is present but leans neither way, null if empty. Ties
     * (equal soft and bold hits) resolve to NEUTRAL — the text is mixed, not
     * confidently either.
     */
    public static function leanOf(string $text): ?string
    {
        $lower = strtolower(trim($text));
        if ($lower === '') {
            return null;
        }

        $soft = self::hits($lower, self::SOFT_LEXICON);
        $bold = self::hits($lower, self::BOLD_LEXICON);

        if ($soft > $bold) {
            return self::SOFT;
        }
        if ($bold > $soft) {
            return self::BOLD;
        }

        return self::NEUTRAL;
    }

    /** Count of lexicon substrings present in $text (already lower-cased). */
    private static function hits(string $text, array $lexicon): int
    {
        $n = 0;
        foreach ($lexicon as $term) {
            if (str_contains($text, $term)) {
                $n++;
            }
        }

        return $n;
    }
}
