<?php

namespace App\Services\Design;

use App\Services\Http\MetadataParser;
use App\Services\Http\ParsedMetadata;
use App\Services\Http\SafeUrlFetcher;

/**
 * Analyzes an external website's DECLARED CSS into brand-signal conclusions
 * for the design-kit preset factors (PreviousWebsiteFactor / OutsideWebsitesFactor).
 *
 * Snap-don't-copy: every signal except accent is classified into a SEMANTIC
 * tier (StyleTiers maps tiers → our curated literals); accent is the one raw
 * value, guarded by a strict multi-signal chain (§4 of the spec). No headless
 * rendering — source CSS only (strong on template sites, degrades to "no
 * signal" on CSS-in-JS SPAs, which is the system-wide safe fallback anyway).
 *
 * All fetching goes through SafeUrlFetcher (SSRF-guarded). Any failure returns
 * the ok:false shape — stored by callers so reconciliation never re-dispatches
 * a permanently broken URL in a loop.
 */
class WebsiteStyleAnalyzer
{
    public const VERSION = 1;

    private const MAX_STYLESHEETS = 5;

    private const MAX_CSS_BYTES = 400_000;   // per stylesheet

    private const MAX_HTML_BYTES = 1_500_000;

    /** Brand-signalling custom-property names, most intentional first. */
    private const BRAND_PROP_PATTERN = '/--(?:brand|primary|accent|theme|main)[a-z0-9_-]*\s*:\s*([^;}]+)[;}]/i';

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $metadata,
    ) {}

    /**
     * @return array{v:int, url:string, ok:bool, analyzedAt:string, accent:?string, tiers:array<string,?string>, logoCandidates:array<string,?string>}
     */
    public function analyze(string $url): array
    {
        $fail = [
            'v' => self::VERSION,
            'url' => $url,
            'ok' => false,
            'analyzedAt' => now()->toIso8601String(),
            'accent' => null,
            'tiers' => [],
            'logoCandidates' => [],
        ];

        $page = $this->fetcher->tryFetch($url);
        if ($page === null || $page['status'] >= 400 || trim($page['body']) === '') {
            return $fail;
        }

        $html = substr($page['body'], 0, self::MAX_HTML_BYTES);
        $finalUrl = $page['finalUrl'];
        $parsed = $this->metadata->parse($html, $finalUrl);
        $css = $this->collectCss($html, $finalUrl);

        return [
            'v' => self::VERSION,
            'url' => $url,
            'ok' => true,
            'analyzedAt' => now()->toIso8601String(),
            'accent' => $this->detectAccent($parsed, $css),
            'tiers' => [
                'bg' => $this->detectBgTier($css),
                'font' => $this->detectFontTier($css),
                'weight' => $this->detectWeightTier($css),
                'text' => $this->detectTextTier($css),
                'radius' => $this->detectRadiusTier($css),
                'space' => $this->detectSpaceTier($css),
                'motion' => $this->detectMotionTier($css),
            ],
            // Absolutized image URLs for the logo auto-grab (previous-website
            // only) — recorded here so the grabber never re-fetches the page.
            'logoCandidates' => [
                'appleTouchIcon' => $parsed->appleTouchIconUrl,
                'favicon' => $parsed->faviconUrl,
                'ogImage' => $this->metadata->absolutize($parsed->og('og:image'), $finalUrl),
            ],
        ];
    }

    /** Inline <style> blocks + up to MAX_STYLESHEETS linked sheets, one corpus. */
    private function collectCss(string $html, string $baseUrl): string
    {
        $parts = [];

        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $m)) {
            foreach ($m[1] as $block) {
                $parts[] = substr($block, 0, self::MAX_CSS_BYTES);
            }
        }

        // <link rel="stylesheet" href> in either attribute order.
        $hrefs = [];
        if (preg_match_all('/<link\b[^>]*>/i', $html, $links)) {
            foreach ($links[0] as $tag) {
                if (! preg_match('/rel\s*=\s*["\']?[^"\'>]*stylesheet/i', $tag)) {
                    continue;
                }
                if (preg_match('/href\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $hm)) {
                    $abs = $this->metadata->absolutize(html_entity_decode($hm[1]), $baseUrl);
                    if ($abs !== null) {
                        $hrefs[] = $abs;
                    }
                }
            }
        }
        $hrefs = array_slice(array_values(array_unique($hrefs)), 0, self::MAX_STYLESHEETS);

        foreach ($this->fetcher->fetchMany($hrefs, ['Accept' => 'text/css,*/*;q=0.5']) as $result) {
            if (is_array($result) && $result['status'] < 400 && $result['body'] !== '') {
                $parts[] = substr($result['body'], 0, self::MAX_CSS_BYTES);
            }
        }

        return implode("\n", $parts);
    }

    // ── Accent (raw value — the one exception to snap-don't-copy) ─────────

    private function detectAccent(ParsedMetadata $parsed, string $css): ?string
    {
        // 1) Owner-declared theme colour.
        $theme = $this->parseColor((string) ($parsed->meta['theme-color'] ?? ''));
        if ($theme !== null && $this->qualifiesAsAccent($theme)) {
            return $theme;
        }

        // 2) Brand-named CSS custom properties.
        if (preg_match_all(self::BRAND_PROP_PATTERN, $css, $m)) {
            $qualified = $this->qualifyColors($m[1]);
            if ($qualified !== []) {
                return $this->mostFrequent($qualified);
            }
        }

        // 3) Semantic elements: primary/CTA button backgrounds + link colour.
        $semantic = [];
        foreach ($this->rules($css) as [$selector, $decls]) {
            if (preg_match('/(^|[\s,>+~])(button|\.btn[\w-]*|\.button[\w-]*|\.cta[\w-]*)/i', $selector)
                && preg_match_all('/background(?:-color)?\s*:\s*([^;}]+)/i', $decls, $bm)) {
                array_push($semantic, ...$bm[1]);
            }
            if (preg_match('/(^|[\s,])a(:[\w-]+)?\s*$/i', trim($selector))
                && preg_match_all('/(?<![\w-])color\s*:\s*([^;}]+)/i', $decls, $cm)) {
                array_push($semantic, ...$cm[1]);
            }
        }
        $qualified = $this->qualifyColors($semantic);
        if ($qualified !== []) {
            return $this->mostFrequent($qualified);
        }

        // 4) Filtered statistics over every declared colour — saturated only
        //    (neutrals are backgrounds/text, never identity colours).
        preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/i', $css, $all);
        $qualified = $this->qualifyColors($all[0]);

        return $qualified !== [] ? $this->mostFrequent($qualified) : null;
    }

    private function qualifiesAsAccent(string $hex): bool
    {
        [$r, $g, $b] = $this->rgb($hex);
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        $max = max($r, $g, $b);
        $sat = $max === 0 ? 0.0 : ($max - min($r, $g, $b)) / $max;

        return $sat >= 0.3 && $lum > 0.08 && $lum < 0.92;
    }

    /**
     * @param  list<string>  $raw
     * @return list<string> normalized hexes that qualify as an accent
     */
    private function qualifyColors(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $hex = $this->parseColor($value);
            if ($hex !== null && $this->qualifiesAsAccent($hex)) {
                $out[] = $hex;
            }
        }

        return $out;
    }

    // ── Snapped signals ────────────────────────────────────────────────────

    private function detectBgTier(string $css): ?string
    {
        $colors = [];
        foreach ($this->rules($css) as [$selector, $decls]) {
            if (! $this->isPageScope($selector)) {
                continue;
            }
            if (preg_match_all('/background(?:-color)?\s*:\s*([^;}]+)/i', $decls, $m)) {
                foreach ($m[1] as $value) {
                    $hex = $this->parseColor($value);
                    if ($hex !== null) {
                        $colors[] = $hex;
                    }
                }
            }
        }
        if ($colors === []) {
            return null;
        }

        [$r, $g, $b] = $this->rgb($this->mostFrequent($colors));
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        if ($lum < 0.35) {
            return 'dark';
        }
        // Undertone: red-vs-blue channel skew. Truly neutral → no conclusion.
        if ($r - $b >= 3) {
            return 'warm_light';
        }
        if ($b - $r >= 3) {
            return 'cool_light';
        }

        return null;
    }

    private function detectFontTier(string $css): ?string
    {
        $families = [];
        foreach ($this->rules($css) as [$selector, $decls]) {
            if (! preg_match_all('/font-family\s*:\s*([^;}]+)/i', $decls, $m)) {
                continue;
            }
            // Page-scope declarations carry double weight — the body font is
            // the identity; component overrides are noise.
            $weight = $this->isPageScope($selector) ? 2 : 1;
            foreach ($m[1] as $stack) {
                $first = strtolower(trim(explode(',', $stack)[0], " \t\"'"));
                if ($first === '' || str_starts_with($first, 'var(')) {
                    continue;
                }
                $slug = $this->matchFontSlug($first);
                if ($slug !== null) {
                    $families[] = ['slug' => $slug, 'weight' => $weight];
                }
            }
        }
        if ($families === []) {
            return null;
        }

        $scores = [];
        foreach ($families as $f) {
            $scores[$f['slug']] = ($scores[$f['slug']] ?? 0) + $f['weight'];
        }
        arsort($scores);

        return array_key_first($scores);
    }

    /** Closest of our four catalog fonts, by family-name keyword. */
    private function matchFontSlug(string $family): ?string
    {
        // Ordered specific-before-generic ('neue haas' before 'helvetica' etc.).
        $map = [
            'neue haas' => 'neue-haas-grotesk', 'haas' => 'neue-haas-grotesk',
            'suisse' => 'neue-haas-grotesk', 'aktiv' => 'neue-haas-grotesk', 'univers' => 'neue-haas-grotesk',
            'mono' => 'nb-architekt', 'courier' => 'nb-architekt', 'consolas' => 'nb-architekt',
            'menlo' => 'nb-architekt', 'architekt' => 'nb-architekt',
            'futura' => 'forma-djr', 'poppins' => 'forma-djr', 'montserrat' => 'forma-djr',
            'nunito' => 'forma-djr', 'quicksand' => 'forma-djr', 'circular' => 'forma-djr',
            'gotham' => 'forma-djr', 'proxima' => 'forma-djr', 'dm sans' => 'forma-djr', 'rubik' => 'forma-djr',
            // Serifs map to our warmest sans (we carry no serif).
            'georgia' => 'forma-djr', 'times' => 'forma-djr', 'playfair' => 'forma-djr',
            'merriweather' => 'forma-djr', 'garamond' => 'forma-djr', 'serif' => 'forma-djr',
            'helvetica' => 'helvetica-neue', 'arial' => 'helvetica-neue', 'inter' => 'helvetica-neue',
            'roboto' => 'helvetica-neue', 'open sans' => 'helvetica-neue', 'lato' => 'helvetica-neue',
            'work sans' => 'helvetica-neue', 'source sans' => 'helvetica-neue',
            'system-ui' => 'helvetica-neue', '-apple-system' => 'helvetica-neue', 'segoe' => 'helvetica-neue',
            'sans-serif' => 'helvetica-neue',
        ];
        foreach ($map as $keyword => $slug) {
            if (str_contains($family, $keyword)) {
                return $slug;
            }
        }

        return null;
    }

    private function detectWeightTier(string $css): ?string
    {
        $weights = [];
        foreach ($this->rules($css) as [$selector, $decls]) {
            if (! $this->isPageScope($selector)) {
                continue;
            }
            if (preg_match_all('/font-weight\s*:\s*([\w]+)/i', $decls, $m)) {
                foreach ($m[1] as $value) {
                    $value = strtolower($value);
                    $n = match (true) {
                        $value === 'normal' => 400,
                        $value === 'bold' => 700,
                        ctype_digit($value) => (int) $value,
                        default => null,
                    };
                    if ($n !== null && $n >= 100 && $n <= 900) {
                        $weights[] = $n;
                    }
                }
            }
        }
        if ($weights === []) {
            return null;
        }

        $mode = $this->mostFrequent($weights);

        return match (true) {
            $mode <= 300 => 'light',
            $mode < 500 => 'regular',
            default => 'medium',
        };
    }

    private function detectTextTier(string $css): ?string
    {
        $sizes = [];
        foreach ($this->rules($css) as [$selector, $decls]) {
            if (! $this->isPageScope($selector)) {
                continue;
            }
            if (preg_match_all('/font-size\s*:\s*(\d+(?:\.\d+)?)(px|rem|em|pt|%)/i', $decls, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $px = $this->toPx((float) $match[1], strtolower($match[2]));
                    if ($px !== null && $px >= 10 && $px <= 24) {
                        $sizes[] = $px;
                    }
                }
            }
        }
        if ($sizes === []) {
            return null;
        }

        $body = $this->median($sizes);

        return match (true) {
            $body <= 14.5 => 'small',
            $body < 17.5 => 'regular',
            default => 'large',
        };
    }

    private function detectRadiusTier(string $css): ?string
    {
        $radii = [];
        if (preg_match_all('/border-radius\s*:\s*([^;}]+)/i', $css, $m)) {
            foreach ($m[1] as $value) {
                // First length token; skip pill/circle idioms (50%, 999px+) —
                // they're avatars and chips, not the site's corner language.
                if (! preg_match('/(\d+(?:\.\d+)?)(px|rem|em|%)/i', $value, $vm)) {
                    continue;
                }
                if (strtolower($vm[2]) === '%') {
                    continue;
                }
                $px = $this->toPx((float) $vm[1], strtolower($vm[2]));
                if ($px !== null && $px < 200) {
                    $radii[] = $px;
                }
            }
        }
        if ($radii === []) {
            return null;
        }

        $mid = $this->median($radii);

        return match (true) {
            $mid < 4 => 'sharp',
            $mid < 12 => 'moderate',
            $mid < 24 => 'rounded',
            default => 'very_rounded',
        };
    }

    private function detectSpaceTier(string $css): ?string
    {
        $values = [];
        foreach ($this->rules($css) as [$selector, $decls]) {
            // Layout-ish contexts only — component padding is noise.
            if (! preg_match('/section|container|wrapper|main|card|content/i', $selector)) {
                continue;
            }
            if (preg_match_all('/(?:^|;)\s*(?:padding|gap)\s*:\s*(\d+(?:\.\d+)?)(px|rem|em)/i', $decls, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $px = $this->toPx((float) $match[1], strtolower($match[2]));
                    if ($px !== null && $px >= 4 && $px <= 80) {
                        $values[] = $px;
                    }
                }
            }
        }
        // Under 3 samples the median is anecdote, not signal.
        if (count($values) < 3) {
            return null;
        }

        $mid = $this->median($values);

        return match (true) {
            $mid < 14 => 'compact',
            $mid <= 22 => 'regular',
            default => 'generous',
        };
    }

    private function detectMotionTier(string $css): ?string
    {
        $durations = [];
        if (preg_match_all('/(?:transition|animation)(?:-duration)?\s*:\s*[^;}]*?(\d+(?:\.\d+)?)(ms|s)\b/i', $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $ms = strtolower($match[2]) === 's' ? (float) $match[1] * 1000 : (float) $match[1];
                if ($ms >= 50 && $ms <= 5000) {
                    $durations[] = $ms;
                }
            }
        }
        if ($durations === []) {
            return null;
        }

        $mid = $this->median($durations);

        return match (true) {
            $mid < 200 => 'fast',
            $mid <= 400 => 'normal',
            default => 'slow',
        };
    }

    // ── Shared helpers ─────────────────────────────────────────────────────

    /**
     * Flat "selector { decls }" pairs. The pattern only matches brace-free
     * spans, so nested wrappers (@media/@supports preludes) are skipped and
     * their inner rules matched directly.
     *
     * @return list<array{0:string,1:string}>
     */
    private function rules(string $css): array
    {
        if (! preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn (array $match): array => [trim($match[1]), $match[2]], $m);
    }

    /** Does this selector target the page base (html/body/:root/p)? */
    private function isPageScope(string $selector): bool
    {
        return (bool) preg_match('/(^|[,\s])(html|body|p|:root)(?![\w-])/i', $selector);
    }

    /** Normalize a CSS colour value to #rrggbb; null when unparseable. */
    private function parseColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})\b/', $value, $m)) {
            $hex = $m[1];
            if (strlen($hex) <= 4) {
                // #rgb(a) → #rrggbb (alpha nibble dropped)
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            return '#'.substr($hex, 0, 6);
        }

        if (preg_match('/rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})/', $value, $m)) {
            $clamp = fn (string $n): int => min(255, (int) $n);

            return sprintf('#%02x%02x%02x', $clamp($m[1]), $clamp($m[2]), $clamp($m[3]));
        }

        $named = [
            'white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000', 'blue' => '#0000ff',
            'green' => '#008000', 'orange' => '#ffa500', 'yellow' => '#ffff00', 'purple' => '#800080',
            'pink' => '#ffc0cb', 'teal' => '#008080', 'navy' => '#000080', 'gray' => '#808080', 'grey' => '#808080',
        ];

        return $named[preg_replace('/\s.*$/', '', $value)] ?? null;
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $n = hexdec(substr($hex, 1));

        return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
    }

    private function toPx(float $value, string $unit): ?float
    {
        return match ($unit) {
            'px' => $value,
            'rem', 'em' => $value * 16,
            'pt' => $value * 4 / 3,
            '%' => $value * 16 / 100,
            default => null,
        };
    }

    /**
     * @template T of int|string|float
     *
     * @param  non-empty-list<T>  $values
     * @return T
     */
    private function mostFrequent(array $values): mixed
    {
        $counts = [];
        foreach ($values as $v) {
            $key = (string) $v;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $winner = array_key_first($counts);
        foreach ($values as $v) {
            if ((string) $v === $winner) {
                return $v;
            }
        }

        return $values[0];
    }

    /** @param non-empty-list<float|int> $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }
}
