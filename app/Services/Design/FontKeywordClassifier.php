<?php

namespace App\Services\Design;

/**
 * The font keyword classifier §13 orders built as real code — it had been
 * documented in the scraper/design-kit rework plan and never implemented
 * (the theme-colour accent half of "website evidence" shipped; this half
 * did not).
 *
 * What it does: reads the FONT NAMES a scanned website declares (Google
 * Fonts links, font-family declarations in inline CSS) and maps the
 * dominant register onto the sitepage's live font roster. It never imports
 * a font — the roster is closed — it answers "which of OUR faces carries
 * the same register as THEIR site?".
 *
 * Register classes mirror how SectorStylePresets deploys the roster. The
 * roster narrowed to four faces on 2026-08-06, so several registers now
 * share a target: poster/condensed AND humanist/rounded → monument-grotesk;
 * Swiss/editorial, UI-modern AND polished contemporary → helvetica-neue;
 * geometric display → forma-djr. Serifs map to helvetica-neue (the roster's
 * editorial register — it holds no serif, and pretending otherwise would
 * propose a font we cannot render).
 *
 * nb-architekt is deliberately unreachable from here: nothing on the open
 * web shares its register, so no keyword could honestly vote for it.
 */
final class FontKeywordClassifier
{
    /**
     * keyword (matched case-insensitively against declared family names)
     * => roster slug. Order matters only for documentation; scoring counts
     * every hit.
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        // Poster / condensed / display grotesques.
        'oswald' => 'monument-grotesk',
        'bebas' => 'monument-grotesk',
        'anton' => 'monument-grotesk',
        'druk' => 'monument-grotesk',
        'impact' => 'monument-grotesk',
        'barlow condensed' => 'monument-grotesk',
        'archivo black' => 'monument-grotesk',

        // Swiss classics + editorial serifs → the fashion-editorial register.
        'helvetica' => 'helvetica-neue',
        'arial' => 'helvetica-neue',
        'akzidenz' => 'helvetica-neue',
        'playfair' => 'helvetica-neue',
        'garamond' => 'helvetica-neue',
        'baskerville' => 'helvetica-neue',
        'cormorant' => 'helvetica-neue',
        'didot' => 'helvetica-neue',
        'bodoni' => 'helvetica-neue',
        'georgia' => 'helvetica-neue',
        'times' => 'helvetica-neue',
        'lora' => 'helvetica-neue',
        'merriweather' => 'helvetica-neue',
        'crimson' => 'helvetica-neue',

        // Humanist / rounded / warm sans.
        'open sans' => 'monument-grotesk',
        'lato' => 'monument-grotesk',
        'nunito' => 'monument-grotesk',
        'quicksand' => 'monument-grotesk',
        'source sans' => 'monument-grotesk',
        'pt sans' => 'monument-grotesk',
        'karla' => 'monument-grotesk',
        'mulish' => 'monument-grotesk',
        'cabin' => 'monument-grotesk',

        // UI-modern.
        'inter' => 'helvetica-neue',
        'roboto' => 'helvetica-neue',
        'segoe' => 'helvetica-neue',
        'system-ui' => 'helvetica-neue',
        'sf pro' => 'helvetica-neue',
        'geist' => 'helvetica-neue',
        'manrope' => 'helvetica-neue',
        'plus jakarta' => 'helvetica-neue',
        'dm sans' => 'helvetica-neue',
        'figtree' => 'helvetica-neue',

        // Geometric display.
        'futura' => 'forma-djr',
        'montserrat' => 'forma-djr',
        'poppins' => 'forma-djr',
        'gotham' => 'forma-djr',
        'proxima' => 'forma-djr',
        'avenir' => 'forma-djr',
        'century gothic' => 'forma-djr',
        'raleway' => 'forma-djr',
        'josefin' => 'forma-djr',

        // Polished contemporary grotesques.
        'work sans' => 'helvetica-neue',
        'archivo' => 'helvetica-neue',
        'sora' => 'helvetica-neue',
        'space grotesk' => 'helvetica-neue',
        'outfit' => 'helvetica-neue',
        'urbanist' => 'helvetica-neue',
    ];

    /**
     * The roster font whose register best matches the site's declared fonts,
     * or null when the evidence is absent or ambiguous (a tie proposes
     * nothing — a wrong font is worse than the sector default).
     */
    public function classify(string $html): ?string
    {
        $families = $this->declaredFamilies($html);
        if ($families === []) {
            return null;
        }

        $scores = [];
        foreach ($families as $family) {
            foreach (self::KEYWORDS as $keyword => $slug) {
                if (str_contains($family, $keyword)) {
                    $scores[$slug] = ($scores[$slug] ?? 0) + 1;
                    break; // A family name votes once — its most specific match.
                }
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $slugs = array_keys($scores);
        $counts = array_values($scores);

        // Ambiguity refuses: two registers tied for the top means the site
        // has no dominant voice this classifier can honestly claim.
        if (isset($counts[1]) && $counts[1] === $counts[0]) {
            return null;
        }

        return $slugs[0];
    }

    /**
     * Every font family the page declares, lowercased: Google Fonts link
     * hrefs (css and css2 forms) + font-family declarations in inline
     * <style> blocks and style attributes.
     *
     * @return list<string>
     */
    private function declaredFamilies(string $html): array
    {
        $families = [];

        // Google Fonts: family=Open+Sans:wght@400|Roboto → "open sans", "roboto".
        if (preg_match_all('~fonts\.googleapis\.com/css2?\?([^"\'>\s]+)~i', $html, $links)) {
            foreach ($links[1] as $query) {
                if (preg_match_all('~family=([^&:|]+)~i', urldecode($query), $names)) {
                    foreach ($names[1] as $name) {
                        $families[] = strtolower(trim(str_replace('+', ' ', $name)));
                    }
                }
            }
        }

        // font-family: "Open Sans", sans-serif — first (most specific) family
        // per declaration; generic fallbacks carry no register information.
        if (preg_match_all('~font-family\s*:\s*([^;}<]+)~i', $html, $declarations)) {
            foreach ($declarations[1] as $stack) {
                $first = trim(explode(',', $stack)[0], " \t\n\r\0\x0B\"'");
                if ($first !== '' && ! in_array(strtolower($first), ['sans-serif', 'serif', 'monospace', 'inherit', 'initial', 'unset'], true) && ! str_starts_with(strtolower($first), 'var(')) {
                    $families[] = strtolower($first);
                }
            }
        }

        return array_values(array_unique($families));
    }
}
