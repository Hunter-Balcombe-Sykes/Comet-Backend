<?php

namespace App\Services\Design\Presets;

/**
 * Shared cuisine derivation: a Google-Business category display string
 * (Places primaryTypeDisplayName — "Italian restaurant", "Coffee shop",
 * "Fast food restaurant") → a coarse cuisine HINT token, or null.
 *
 * The category string is the only cuisine signal the platform stores today (no
 * structured servesCuisine / types[] — verified). Both CuisineFactor (band 44)
 * and LaunchRecipeFactor (band 70, the fine-dining-editorial trigger) read the
 * hint through IdentityEvidence::cuisineHint(), so the keyword list lives here
 * ONCE rather than being duplicated across factors.
 *
 * Hint tokens are a small closed set:
 *   - fine_dining  — upscale / degustation signalling (→ editorial look)
 *   - cafe         — coffee / brunch / bakery (→ warm, rounded, friendly)
 *   - fast_casual  — takeaway / fast food / food truck (→ bold, bright)
 *   - a specific-cuisine token (italian, japanese, french, …) — restrained palettes
 * A non-food or unrecognised string yields null (the caller abstains).
 */
final class CuisineLexicon
{
    public const FINE_DINING = 'fine_dining';

    public const CAFE = 'cafe';

    public const FAST_CASUAL = 'fast_casual';

    /**
     * Ordered keyword => hint. FIRST substring match wins, so the list is
     * ordered specific-before-generic:
     *   - fine-dining cues before the generic 'restaurant'
     *   - cafe/fast-casual cues before specific cuisines (a "Coffee shop" is a
     *     cafe regardless of any cuisine word), and before 'restaurant'
     *   - specific cuisines before the bare 'restaurant' fallback
     * 'restaurant' alone stays null (a generic restaurant has no cuisine lean to
     * act on — the category/hospitality recipes cover it).
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        // Fine dining — upscale signalling.
        'fine dining' => self::FINE_DINING,
        'fine-dining' => self::FINE_DINING,
        'degustation' => self::FINE_DINING,
        'tasting menu' => self::FINE_DINING,
        'french restaurant' => self::FINE_DINING, // French dining reads editorial by convention
        'steakhouse' => self::FINE_DINING,

        // Cafe / brunch / bakery — warm and friendly.
        'coffee' => self::CAFE,
        'cafe' => self::CAFE,
        'café' => self::CAFE,
        'espresso' => self::CAFE,
        'tea house' => self::CAFE,
        'tea room' => self::CAFE,
        'bakery' => self::CAFE,
        'patisserie' => self::CAFE,
        'brunch' => self::CAFE,
        'breakfast' => self::CAFE,

        // Fast-casual / takeaway — bold and bright.
        'fast food' => self::FAST_CASUAL,
        'fast-food' => self::FAST_CASUAL,
        'takeaway' => self::FAST_CASUAL,
        'takeout' => self::FAST_CASUAL,
        'food truck' => self::FAST_CASUAL,
        'burger' => self::FAST_CASUAL,
        'pizza' => self::FAST_CASUAL,
        'fried chicken' => self::FAST_CASUAL,
        'sandwich' => self::FAST_CASUAL,

        // Specific cuisines — restrained palettes (the token IS the cuisine name).
        'italian' => 'italian',
        'japanese' => 'japanese',
        'sushi' => 'japanese',
        'ramen' => 'japanese',
        'thai' => 'thai',
        'chinese' => 'chinese',
        'indian' => 'indian',
        'mexican' => 'mexican',
        'korean' => 'korean',
        'vietnamese' => 'vietnamese',
        'greek' => 'greek',
        'mediterranean' => 'mediterranean',
        'spanish' => 'spanish',
        'french' => 'french',
    ];

    /**
     * The cuisine hint for a raw Google-Business category string, or null when it
     * isn't a food business or carries no recognisable cuisine lean.
     */
    public static function hintFor(string $category): ?string
    {
        $lower = strtolower(trim($category));
        if ($lower === '' || $lower === 'none') {
            return null;
        }

        foreach (self::KEYWORDS as $keyword => $hint) {
            if (str_contains($lower, $keyword)) {
                return $hint;
            }
        }

        return null;
    }

    /** Is this hint the fine-dining signal? (Used by the fine-dining-editorial recipe trigger.) */
    public static function isFineDining(?string $hint): bool
    {
        return $hint === self::FINE_DINING;
    }
}
