<?php

namespace App\Support;

/**
 * Folds Instagram's "fancy font" characters back to plain letters.
 *
 * Those names are not a font — there is no styling involved. The author picked
 * characters out of Unicode's Mathematical Alphanumeric Symbols block, so
 * "𝐓𝐡𝐞 𝐁𝐥𝐨𝐨𝐦 𝐑𝐨𝐨𝐦" is literally MATHEMATICAL BOLD CAPITAL T followed by
 * MATHEMATICAL BOLD SMALL H, and so on. A screen reader announces them one
 * codepoint at a time or skips them; a font stack has no glyphs for most of
 * them and falls back mid-word; and every downstream consumer that lowercases,
 * parses or matches the name sees characters that are not letters.
 *
 * Found live 2026-08-30 on thebloomroommalvern, whose site rendered its name
 * in raw math-bold — the largest text on the page. One account in 161 built so
 * far, so: rare, cheap to fix, and it lands on the single most prominent
 * string a person's site has.
 *
 * Folded PER CHARACTER, not by running NFKC over the whole string, and that is
 * the point. Whole-string NFKC would also rewrite '™' to 'TM', '№' to 'No' and
 * '½' to '1⁄2' — plausible characters in a real business name, and not ours to
 * change. Only the styled-letter ranges are touched; everything else, emoji and
 * accents included, is returned byte-for-byte.
 */
final class StyledUnicodeText
{
    /**
     * Styled LETTER/DIGIT ranges only:
     *   1D400–1D7FF  Mathematical Alphanumeric Symbols (bold/italic/script/…)
     *   FF01–FF5E    Fullwidth forms (ＡＢＣ)
     *   2460–24FF    Enclosed Alphanumerics (①, Ⓐ)
     *   1F130–1F189  Enclosed Alphanumeric Supplement (🄰, 🅰)
     */
    private const STYLED = '/[\x{1D400}-\x{1D7FF}\x{FF01}-\x{FF5E}\x{2460}-\x{24FF}\x{1F130}-\x{1F189}]/u';

    public static function fold(?string $text): ?string
    {
        if ($text === null || $text === '' || preg_match(self::STYLED, $text) !== 1) {
            return $text;
        }

        // ext-intl is not a declared dependency (composer.json requires no
        // ext-intl, and nothing else in the app uses PHP's own Normalizer), so
        // this degrades to the original string rather than fatalling if an
        // environment ever lacks it. Present on the dev server, checked.
        if (! class_exists(\Normalizer::class)) {
            return $text;
        }

        $folded = preg_replace_callback(
            self::STYLED,
            static function (array $m): string {
                $plain = \Normalizer::normalize($m[0], \Normalizer::FORM_KC);

                // A styled char with no compatibility decomposition (some of
                // the enclosed forms) normalises to itself — keep it rather
                // than emit an empty string.
                return is_string($plain) && $plain !== '' ? $plain : $m[0];
            },
            $text,
        );

        // Collapse the double spaces a fullwidth-space fold can leave behind.
        return is_string($folded) ? trim(preg_replace('/\s{2,}/u', ' ', $folded) ?? $folded) : $text;
    }
}
