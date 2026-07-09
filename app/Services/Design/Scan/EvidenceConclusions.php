<?php

namespace App\Services\Design\Scan;

/**
 * ALL brand-scan policy lives here: collector evidence + pixel summary in,
 * per-signal conclusions with CONFIDENCE out. The collector and the sampler
 * only collect; this class decides — which keeps every judgement unit-testable
 * without a browser.
 *
 * Confidence contract: factors use a signal iff confidence >= MIN_CONFIDENCE.
 * Below-threshold signals are OMITTED from the output entirely, so a weak read
 * degrades to "no contribution" (the system-wide safe default), never to a
 * wrong value. Accent additionally requires intentional evidence — the v1
 * frequency-statistics fallback (which shipped a Shopify app widget's Material
 * green as a brand colour) has no successor here by design.
 */
class EvidenceConclusions
{
    /** Fallback default for config('partna.brand_scan.confidence.min_confidence'). */
    private const MIN_CONFIDENCE = 0.5;

    /**
     * Colours within this RGB euclidean distance corroborate each other.
     * Fallback default for config('partna.brand_scan.confidence.agree_dist').
     */
    private const AGREE_DIST = 40.0;

    /**
     * Accent candidates within this distance merge into one cluster.
     * Fallback default for config('partna.brand_scan.confidence.cluster_dist').
     */
    private const CLUSTER_DIST = 30.0;

    /**
     * Confidence floor a signal/accent must clear to survive in conclude()'s
     * output. Public + static so PreviousWebsiteFactor/OutsideWebsitesFactor
     * can apply the SAME bar when re-checking a stored v2 analysis document —
     * keeping this the single source of truth for the threshold.
     */
    public static function minConfidence(): float
    {
        return (float) config('partna.brand_scan.confidence.min_confidence', self::MIN_CONFIDENCE);
    }

    private static function agreeDist(): float
    {
        return (float) config('partna.brand_scan.confidence.agree_dist', self::AGREE_DIST);
    }

    private static function clusterDist(): float
    {
        return (float) config('partna.brand_scan.confidence.cluster_dist', self::CLUSTER_DIST);
    }

    /**
     * @param  array<string, mixed>  $evidence  collector stash (decoded)
     * @param  array{bg: ?string, accent: ?string}  $pixels  ScreenshotSampler summary
     * @return array{signals: array<string, array{tier: string, confidence: float, evidence: list<string>}>, accent: ?array{hex: string, confidence: float, evidence: list<string>}, logo: array{candidates: list<array<string, mixed>>}, notes: list<string>}
     */
    public function conclude(array $evidence, array $pixels): array
    {
        $notes = [];
        $signals = [];

        $page = is_array($evidence['page'] ?? null) ? $evidence['page'] : [];
        $copy = is_array($evidence['copy'] ?? null) ? $evidence['copy'] : [];
        $buttons = is_array($evidence['buttons'] ?? null) ? $evidence['buttons'] : [];
        $sections = is_array($evidence['sections'] ?? null) ? $evidence['sections'] : [];

        $bg = $this->bgSignal($page, $pixels);
        if ($bg !== null) {
            $signals['bg'] = $bg;
        }

        $font = $this->fontSignal($page, $copy, is_array($evidence['headings'] ?? null) ? $evidence['headings'] : [], $notes);
        if ($font !== null) {
            $signals['font'] = $font;
        }

        foreach ([
            'weight' => $this->weightSignal($page, $copy),
            'text' => $this->textSignal($page, $copy),
            'radius' => $this->radiusSignal($buttons),
            'space' => $this->spaceSignal($sections),
            'motion' => $this->motionSignal($buttons, $sections),
        ] as $name => $signal) {
            if ($signal !== null) {
                $signals[$name] = $signal;
            }
        }

        // Below-threshold conclusions are dropped, not stored — an absent key
        // IS the "no confident conclusion" representation.
        $signals = array_filter($signals, fn (array $s): bool => $s['confidence'] >= self::minConfidence());

        return [
            'signals' => $signals,
            'accent' => $this->accentSignal($evidence, $page, $buttons, $pixels, $signals['bg']['hex'] ?? null),
            'logo' => ['candidates' => $this->logoCandidates($evidence)],
            'notes' => array_slice($notes, 0, 8),
        ];
    }

    // ── background ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $page */
    private function bgSignal(array $page, array $pixels): ?array
    {
        $computedRaw = null;
        foreach (['bodyBg', 'deepBg', 'mainBg'] as $key) {
            $c = $this->parseColor($page[$key] ?? null);
            if ($c !== null && $c['a'] >= 0.5) {
                $computedRaw = $c;
                break;
            }
        }
        $pixel = $this->parseColor($pixels['bg'] ?? null);

        if ($computedRaw === null && $pixel === null) {
            return null;
        }

        $evidenceNotes = [];
        if ($computedRaw !== null) {
            $evidenceNotes[] = 'computed '.$this->hex($computedRaw);
        }
        if ($pixel !== null) {
            $evidenceNotes[] = 'pixels '.$this->hex($pixel);
        }

        if ($computedRaw !== null && $pixel !== null) {
            $confidence = $this->dist($computedRaw, $pixel) <= self::agreeDist() ? 0.95 : 0.25;
        } elseif ($computedRaw !== null) {
            // A background-image anywhere in the paint chain means the colour
            // may never actually be visible — not enough alone.
            $confidence = ($page['bodyBgImage'] ?? false) === true ? 0.45 : 0.6;
        } else {
            $confidence = 0.55;
        }

        $value = $computedRaw ?? $pixel;

        return [
            'tier' => $this->bgTier($value),
            'hex' => $this->hex($value),
            'confidence' => $confidence,
            'evidence' => $evidenceNotes,
        ];
    }

    /** @param array{r:int,g:int,b:int,a:float} $c */
    private function bgTier(array $c): string
    {
        if ($this->lum($c) < 0.32) {
            return 'dark';
        }
        if ($c['r'] - $c['b'] >= 4) {
            return 'warm_light';
        }
        if ($c['b'] - $c['r'] >= 4) {
            return 'cool_light';
        }

        return 'light';
    }

    // ── typography ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $copy
     * @param  list<array<string, mixed>>  $headings
     * @param  list<string>  $notes
     */
    private function fontSignal(array $page, array $copy, array $headings, array &$notes): ?array
    {
        $bodyFamily = $this->firstFamily((string) ($page['bodyFont'] ?? ''));
        $bodySlug = $bodyFamily === null ? null : $this->fontSlug($bodyFamily);

        if ($bodySlug !== null) {
            // Consensus: does the visible body copy actually use the same catalog font?
            $known = 0;
            $agree = 0;
            foreach ($copy as $c) {
                $slug = $this->fontSlug((string) ($this->firstFamily((string) ($c['family'] ?? '')) ?? ''));
                if ($slug !== null) {
                    $known++;
                    if ($slug === $bodySlug) {
                        $agree++;
                    }
                }
            }
            $confidence = ($known < 2 || $agree / max(1, $known) >= 0.7) ? 0.85 : 0.6;

            return ['tier' => $bodySlug, 'confidence' => $confidence, 'evidence' => ['body: '.$bodyFamily]];
        }

        if ($bodyFamily !== null) {
            $notes[] = 'unknown font family: '.$bodyFamily;
        }

        // Body font unrecognized (custom/obfuscated webfont) — a recognizable
        // display face is weaker but real evidence.
        foreach ($headings as $h) {
            $family = $this->firstFamily((string) ($h['family'] ?? ''));
            $slug = $family === null ? null : $this->fontSlug($family);
            if ($slug !== null) {
                return ['tier' => $slug, 'confidence' => 0.55, 'evidence' => [($h['tag'] ?? 'h1').': '.$family]];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $copy
     */
    private function weightSignal(array $page, array $copy): ?array
    {
        $samples = [];
        foreach ($copy as $c) {
            $w = $this->numericWeight($c['weight'] ?? null);
            if ($w !== null) {
                $samples[] = $w;
            }
        }
        $bodyWeight = $this->numericWeight($page['bodyFontWeight'] ?? null);
        if ($bodyWeight !== null) {
            $samples[] = $bodyWeight;
        }
        if (count($samples) < 2) {
            return null;
        }

        $counts = array_count_values($samples);
        arsort($counts);
        $mode = (int) array_key_first($counts);
        if ($counts[$mode] / count($samples) < 0.6) {
            return null; // mixed weights — no identity conclusion
        }

        $tier = match (true) {
            $mode <= 300 => 'light',
            $mode < 500 => 'regular',
            default => 'medium',
        };

        return [
            'tier' => $tier,
            'confidence' => count($samples) >= 4 ? 0.8 : 0.65,
            'evidence' => ['weight mode '.$mode.' of '.count($samples)],
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $copy
     */
    private function textSignal(array $page, array $copy): ?array
    {
        $sizes = [];
        foreach ($copy as $c) {
            $s = $c['size'] ?? null;
            if (is_numeric($s) && $s >= 8 && $s <= 28) {
                $sizes[] = (float) $s;
            }
        }
        $bodySize = $page['bodyFontSize'] ?? null;
        if (is_numeric($bodySize) && $bodySize >= 8 && $bodySize <= 28) {
            $sizes[] = (float) $bodySize;
        }
        if ($sizes === []) {
            return null;
        }

        $median = $this->median($sizes);
        $tier = match (true) {
            $median <= 14.5 => 'small',
            $median < 17.5 => 'regular',
            default => 'large',
        };
        $n = count($sizes);

        return [
            'tier' => $tier,
            'confidence' => $n >= 5 ? 0.8 : ($n >= 3 ? 0.6 : 0.55),
            'evidence' => ['median '.round($median, 1).'px of '.$n],
        ];
    }

    // ── shape / rhythm / motion ────────────────────────────────────────────

    /** @param list<array<string, mixed>> $buttons */
    private function radiusSignal(array $buttons): ?array
    {
        $radii = [];
        $pills = 0;
        foreach ($buttons as $b) {
            $radius = $b['radius'] ?? null;
            $height = $b['height'] ?? null;
            if (! is_numeric($radius)) {
                continue;
            }
            $r = (float) $radius;
            if (is_numeric($height) && $height > 0) {
                // 9999px pill idiom: the EFFECTIVE radius is half the height.
                if ($r >= 0.45 * (float) $height) {
                    $pills++;
                }
                $r = min($r, (float) $height / 2);
            }
            $radii[] = $r;
        }
        if ($radii === []) {
            return null;
        }

        // A pill-dominant button language is very_rounded regardless of median.
        if ($pills >= max(2, (int) ceil(count($radii) / 2))) {
            $tier = 'very_rounded';
        } else {
            $median = $this->median($radii);
            $tier = match (true) {
                $median < 4 => 'sharp',
                $median < 12 => 'moderate',
                $median < 24 => 'rounded',
                default => 'very_rounded',
            };
        }

        return [
            'tier' => $tier,
            'confidence' => count($radii) >= 3 ? 0.8 : 0.55,
            'evidence' => ['radii of '.count($radii).' buttons, '.$pills.' pills'],
        ];
    }

    /** @param list<array<string, mixed>> $sections */
    private function spaceSignal(array $sections): ?array
    {
        $values = [];
        foreach ($sections as $s) {
            foreach (['padT', 'padB', 'padL', 'padR', 'gap'] as $key) {
                $v = $s[$key] ?? null;
                if (is_numeric($v) && $v >= 4 && $v <= 160) {
                    $values[] = (float) $v;
                }
            }
        }
        if (count($values) < 4) {
            return null; // under 4 samples the median is anecdote, not rhythm
        }

        $median = $this->median($values);
        $tier = match (true) {
            $median < 24 => 'compact',
            $median <= 56 => 'regular',
            default => 'generous',
        };

        return ['tier' => $tier, 'confidence' => 0.6, 'evidence' => ['pad median '.round($median).'px of '.count($values)]];
    }

    /**
     * @param  list<array<string, mixed>>  $buttons
     * @param  list<array<string, mixed>>  $sections
     */
    private function motionSignal(array $buttons, array $sections): ?array
    {
        $durations = [];
        foreach ([...$buttons, ...$sections] as $el) {
            $t = $el['transition'] ?? null;
            if (is_numeric($t) && $t >= 40 && $t <= 5000) {
                $durations[] = (float) $t;
            }
        }
        if (count($durations) < 3) {
            return null; // no motion language declared → abstain, not "fast"
        }

        $median = $this->median($durations);
        $tier = match (true) {
            $median < 200 => 'fast',
            $median <= 400 => 'normal',
            default => 'slow',
        };

        return ['tier' => $tier, 'confidence' => 0.7, 'evidence' => ['transition median '.round($median).'ms of '.count($durations)]];
    }

    // ── accent ─────────────────────────────────────────────────────────────

    /**
     * Accent needs INTENTIONAL evidence: brand-named custom properties, CTA
     * backgrounds, or link colours — clustered, then quorum-scored. Monochrome
     * brands produce no qualifying candidate and correctly get null.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $buttons
     */
    private function accentSignal(array $evidence, array $page, array $buttons, array $pixels, ?string $bgHex): ?array
    {
        $candidates = [];

        $rootVars = is_array($evidence['rootVars'] ?? null) ? $evidence['rootVars'] : [];
        foreach ($rootVars as $name => $value) {
            if (! preg_match('/accent|primary|brand/i', (string) $name)
                || preg_match('/background|foreground/i', (string) $name)) {
                continue;
            }
            $c = $this->parseColor($value);
            if ($c !== null && $this->qualifiesAsAccent($c)) {
                $candidates[] = ['c' => $c, 'source' => 'brand-var'];
            }
        }

        foreach ($buttons as $b) {
            $c = $this->parseColor($b['bg'] ?? null);
            if ($c !== null && $c['a'] >= 0.9 && $this->qualifiesAsAccent($c)) {
                $candidates[] = ['c' => $c, 'source' => 'cta'];
            }
        }

        $bodyColor = $this->parseColor($page['bodyColor'] ?? null);
        foreach (is_array($evidence['links'] ?? null) ? $evidence['links'] : [] as $link) {
            $c = $this->parseColor($link['color'] ?? null);
            if ($c !== null && $this->qualifiesAsAccent($c)
                && ($bodyColor === null || $this->dist($c, $bodyColor) > 60)) {
                $candidates[] = ['c' => $c, 'source' => 'link'];
            }
        }

        $themeColor = $this->parseColor($evidence['meta']['themeColor'] ?? null);
        if ($themeColor !== null && ($themeColor['a'] ?? 1) >= 0.9 && $this->qualifiesAsAccent($themeColor)) {
            $candidates[] = ['c' => $themeColor, 'source' => 'theme-color'];
        }

        if ($candidates === []) {
            return null;
        }

        // Greedy proximity clustering; a cluster's strength = how many DISTINCT
        // intentional sources back it, then raw votes.
        $clusters = [];
        foreach ($candidates as $cand) {
            foreach ($clusters as &$cluster) {
                if ($this->dist($cand['c'], $cluster['rep']) <= self::clusterDist()) {
                    $cluster['votes']++;
                    $cluster['sources'][$cand['source']] = true;

                    continue 2;
                }
            }
            unset($cluster);
            $clusters[] = ['rep' => $cand['c'], 'votes' => 1, 'sources' => [$cand['source'] => true]];
        }

        usort($clusters, function (array $a, array $b): int {
            return [count($b['sources']), $b['votes']] <=> [count($a['sources']), $a['votes']];
        });

        $bg = $this->parseColor($bgHex);
        foreach ($clusters as $cluster) {
            // Sanity: an "accent" indistinguishable from the page bg or body
            // text is a mis-read, not an identity colour.
            if ($bg !== null && $this->dist($cluster['rep'], $bg) < 50) {
                continue;
            }
            if ($bodyColor !== null && $this->dist($cluster['rep'], $bodyColor) < 40) {
                continue;
            }

            $sources = array_keys($cluster['sources']);
            $intentional = array_values(array_diff($sources, ['theme-color']));

            $confidence = match (true) {
                count($intentional) >= 2 => 0.9,
                count($intentional) === 1 && $cluster['votes'] >= 2 => 0.7,
                count($intentional) === 1 => 0.55,
                default => 0.35, // theme-color alone never clears the threshold
            };

            // Pixel corroboration: the candidate visibly present on the fold.
            $pixelAccent = $this->parseColor($pixels['accent'] ?? null);
            if ($pixelAccent !== null && $this->dist($cluster['rep'], $pixelAccent) <= self::agreeDist()) {
                $confidence = min(0.95, $confidence + 0.05);
                $sources[] = 'pixels';
            }

            if ($confidence < self::minConfidence()) {
                return null;
            }

            return [
                'hex' => $this->hex($cluster['rep']),
                'confidence' => $confidence,
                'evidence' => [implode('+', $sources).' x'.$cluster['votes']],
            ];
        }

        return null;
    }

    // ── logo candidate assembly (scored + downloaded by LogoAutoGrabber) ──

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function logoCandidates(array $evidence): array
    {
        $out = [];

        foreach (is_array($evidence['logos'] ?? null) ? $evidence['logos'] : [] as $logo) {
            if (! is_array($logo)) {
                continue;
            }
            if (($logo['kind'] ?? '') === 'img' && is_string($logo['src'] ?? null)) {
                $out[] = [
                    'kind' => 'header-img',
                    'url' => $logo['src'],
                    'natW' => $logo['natW'] ?? null,
                    'natH' => $logo['natH'] ?? null,
                    'alt' => $logo['alt'] ?? '',
                    'hint' => (bool) ($logo['hint'] ?? false),
                    'inHeader' => (bool) ($logo['inHeader'] ?? false),
                ];
            } elseif (($logo['kind'] ?? '') === 'inline-svg' && is_string($logo['svg'] ?? null)) {
                $out[] = [
                    'kind' => 'inline-svg',
                    'svg' => $logo['svg'],
                    'w' => $logo['w'] ?? null,
                    'h' => $logo['h'] ?? null,
                    'viewBox' => $logo['viewBox'] ?? null,
                    'hint' => (bool) ($logo['hint'] ?? false),
                    'inHeader' => (bool) ($logo['inHeader'] ?? false),
                ];
            }
        }

        $meta = is_array($evidence['meta'] ?? null) ? $evidence['meta'] : [];
        foreach (is_array($meta['icons'] ?? null) ? $meta['icons'] : [] as $icon) {
            if (! is_array($icon) || ! is_string($icon['href'] ?? null)) {
                continue;
            }
            $rel = strtolower((string) ($icon['rel'] ?? ''));
            $out[] = [
                'kind' => str_contains($rel, 'apple-touch') ? 'apple-touch' : 'icon',
                'url' => $icon['href'],
                'sizes' => $icon['sizes'] ?? null,
                'type' => $icon['type'] ?? null,
            ];
        }
        if (is_string($meta['manifest'] ?? null)) {
            $out[] = ['kind' => 'manifest', 'url' => $meta['manifest']];
        }
        foreach (['ogImage' => 'og-image', 'twitterImage' => 'twitter-image'] as $key => $kind) {
            if (is_string($meta[$key] ?? null)) {
                $out[] = ['kind' => $kind, 'url' => $meta[$key]];
            }
        }

        return array_slice($out, 0, 16);
    }

    // ── colour + math helpers ──────────────────────────────────────────────

    /**
     * Parse a computed/browser colour: #hex, rgb()/rgba() (comma, space, or
     * slash-alpha syntax), and bare "r g b" / "r,g,b" custom-property triplets.
     *
     * @return array{r:int, g:int, b:int, a:float}|null
     */
    private function parseColor(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'transparent' || $value === 'none') {
            return null;
        }

        if (preg_match('/#([0-9a-f]{6})\b/', $value, $m)) {
            $n = hexdec($m[1]);

            return ['r' => ($n >> 16) & 255, 'g' => ($n >> 8) & 255, 'b' => $n & 255, 'a' => 1.0];
        }
        if (preg_match('/#([0-9a-f]{3})\b/', $value, $m)) {
            $h = $m[1];

            return [
                'r' => hexdec($h[0].$h[0]), 'g' => hexdec($h[1].$h[1]), 'b' => hexdec($h[2].$h[2]), 'a' => 1.0,
            ];
        }

        // rgb(a)(…) or a bare triplet ("255 255 255 / 1.0", "18, 18, 18").
        if (preg_match('/^(?:rgba?\(\s*)?(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*(?:[,\/]\s*([\d.]+%?))?\s*\)?$/', $value, $m)) {
            $alpha = 1.0;
            if (isset($m[4]) && $m[4] !== '') {
                $alpha = str_ends_with($m[4], '%') ? ((float) $m[4]) / 100 : (float) $m[4];
            }

            return [
                'r' => min(255, (int) $m[1]),
                'g' => min(255, (int) $m[2]),
                'b' => min(255, (int) $m[3]),
                'a' => max(0.0, min(1.0, $alpha)),
            ];
        }

        return null;
    }

    /** @param array{r:int,g:int,b:int} $c */
    private function qualifiesAsAccent(array $c): bool
    {
        $max = max($c['r'], $c['g'], $c['b']);
        $sat = $max === 0 ? 0.0 : ($max - min($c['r'], $c['g'], $c['b'])) / $max;
        $lum = $this->lum($c);

        return $sat >= 0.3 && $lum > 0.08 && $lum < 0.92;
    }

    /** @param array{r:int,g:int,b:int} $c */
    private function lum(array $c): float
    {
        return (0.2126 * $c['r'] + 0.7152 * $c['g'] + 0.0722 * $c['b']) / 255;
    }

    /**
     * @param  array{r:int,g:int,b:int}  $a
     * @param  array{r:int,g:int,b:int}  $b
     */
    private function dist(array $a, array $b): float
    {
        return sqrt((($a['r'] - $b['r']) ** 2) + (($a['g'] - $b['g']) ** 2) + (($a['b'] - $b['b']) ** 2));
    }

    /** @param array{r:int,g:int,b:int} $c */
    private function hex(array $c): string
    {
        return sprintf('#%02x%02x%02x', $c['r'], $c['g'], $c['b']);
    }

    private function firstFamily(string $stack): ?string
    {
        $first = trim(explode(',', $stack)[0] ?? '', " \t\"'");
        $first = strtolower(str_replace(['_', '-'], ' ', $first));

        return $first === '' ? null : $first;
    }

    /**
     * Closest of our 17 catalog fonts, by family-name keyword
     * (docs/design/font-icon-knowledge.md §4 — keep both in sync).
     *
     * Ordered specific-before-generic. Two disambiguation guards run first
     * ('cooper hewitt' before the bare 'cooper' that young-serif claims;
     * 'cormorant garamond' before the bare 'cormorant' that playfair-display
     * claims) — every other keyword in the doc's own row order is already
     * conflict-free. 'serif' / 'sans serif' are the generic catch-alls and
     * stay last. Keywords are written space-separated (never hyphenated):
     * firstFamily() already turns '-apple-system' / 'system-ui' into
     * 'apple system' / 'system ui' before this method ever sees the string.
     */
    private function fontSlug(string $family): ?string
    {
        $map = [
            // Disambiguation guards — must run before their generic substrings.
            'cooper hewitt' => 'geist',
            'cormorant garamond' => 'origin',

            // Monospace.
            'office code' => 'geist', 'source code' => 'geist',
            'fira code' => 'geist', 'fira mono' => 'geist',
            'jetbrains' => 'geist', 'roboto mono' => 'geist',
            'ibm plex mono' => 'geist', 'space mono' => 'geist',
            'courier' => 'geist', 'consolas' => 'geist',
            'menlo' => 'geist', 'monaco' => 'geist',
            'mono' => 'geist', 'monospace' => 'geist',

            // Didone / high-contrast display serif.
            'playfair' => 'origin', 'didot' => 'origin',
            'bodoni' => 'origin', 'abril' => 'origin',
            'prata' => 'origin', 'cormorant' => 'origin',

            // Old-style (Garalde) serif.
            'garamond' => 'origin', 'sabon' => 'origin',
            'minion' => 'origin', 'bembo' => 'origin', 'caslon' => 'origin',

            // Transitional serif / generic serif fallback.
            'baskerville' => 'origin', 'georgia' => 'origin',
            'times' => 'origin', 'merriweather' => 'origin',
            'lora' => 'origin', 'pt serif' => 'origin',
            'source serif' => 'origin', 'crimson' => 'origin',
            'charter' => 'origin', 'literata' => 'origin',

            // Chunky old-style serif.
            'young serif' => 'young-serif', 'recoleta' => 'young-serif',
            'windsor' => 'young-serif', 'clearface' => 'young-serif',
            'souvenir' => 'young-serif', 'cooper' => 'young-serif',

            // Slab serif.
            'zilla' => 'origin', 'rockwell' => 'origin', 'roboto slab' => 'origin',
            'arvo' => 'origin', 'bitter' => 'origin', 'museo slab' => 'origin',
            'josefin slab' => 'origin', 'clarendon' => 'origin', 'slab' => 'origin',

            // Condensed gothic display.
            'oswald' => 'oswald', 'bebas' => 'oswald', 'anton' => 'oswald',
            'league gothic' => 'oswald', 'impact' => 'oswald', 'haettenschweiler' => 'oswald',
            'tungsten' => 'oswald', 'din condensed' => 'oswald',
            'barlow condensed' => 'oswald', 'roboto condensed' => 'oswald',

            // Deco display sans.
            'ostrich' => 'ostrich-sans', 'josefin' => 'ostrich-sans',

            // Brutalist geometric sans.
            'reglo' => 'reglo', 'druk' => 'reglo', 'eurostile' => 'reglo',
            'microgramma' => 'reglo', 'agency' => 'reglo',

            // Handwritten script.
            'caveat' => 'melodrama', 'dancing script' => 'melodrama', 'pacifico' => 'melodrama',
            'satisfy' => 'melodrama', 'kalam' => 'melodrama', 'indie flower' => 'melodrama',
            'shadows into light' => 'melodrama', 'amatic' => 'melodrama', 'permanent marker' => 'melodrama',
            'lobster' => 'melodrama', 'great vibes' => 'melodrama', 'sacramento' => 'melodrama', 'cursive' => 'melodrama',

            // Rounded geometric sans.
            'quicksand' => 'geist', 'nunito' => 'geist', 'comfortaa' => 'geist',
            'varela' => 'geist', 'baloo' => 'geist', 'fredoka' => 'geist',
            'rubik' => 'geist', 'vag' => 'geist',

            // Contemporary geometric sans.
            'gotham' => 'geist', 'montserrat' => 'geist', 'proxima' => 'geist',
            'futura' => 'geist', 'avenir' => 'geist', 'poppins' => 'geist',
            'dm sans' => 'geist', 'raleway' => 'geist', 'manrope' => 'geist',
            'jost' => 'geist', 'urbanist' => 'geist', 'sora' => 'geist',
            'circular' => 'geist',

            // Techno-humanist sans.
            'm plus' => 'geist', 'mplus' => 'geist', 'zen kaku' => 'geist',
            'noto sans jp' => 'geist', 'din' => 'geist',

            // Grotesque sans (American gothic lineage).
            'archivo' => 'geist', 'franklin' => 'geist', 'trade gothic' => 'geist',
            'barlow' => 'geist', 'public sans' => 'geist', 'libre franklin' => 'geist',
            'oscine' => 'geist',

            // Helvetica-clone grotesque.
            'helvetica' => 'geist', 'neue haas' => 'geist', 'haas' => 'geist',
            'nimbus' => 'geist', 'arial' => 'geist', 'univers' => 'geist',
            'aktiv' => 'geist', 'suisse' => 'geist', 'swiss 721' => 'geist',
            'akzidenz' => 'geist',

            // Neo-grotesque / system UI sans.
            'roboto' => 'geist', 'noto sans' => 'geist', 'inter' => 'geist',
            'san francisco' => 'geist', 'sf pro' => 'geist', 'apple system' => 'geist',
            'system ui' => 'geist', 'segoe' => 'geist', 'ubuntu' => 'geist', 'ibm plex sans' => 'geist',

            // Humanist grotesque / generic sans fallback.
            'work sans' => 'geist', 'open sans' => 'geist', 'lato' => 'geist',
            'source sans' => 'geist', 'pt sans' => 'geist', 'karla' => 'geist',
            'mulish' => 'geist', 'assistant' => 'geist', 'figtree' => 'geist',

            // Generic catch-alls — must stay last.
            'serif' => 'origin',
            'sans serif' => 'geist',
        ];
        foreach ($map as $keyword => $slug) {
            if (str_contains($family, $keyword)) {
                return $slug;
            }
        }

        return null;
    }

    private function numericWeight(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $n = (int) $value;

            return ($n >= 100 && $n <= 900) ? $n : null;
        }

        return match (strtolower(trim((string) $value))) {
            'normal' => 400,
            'bold' => 700,
            default => null,
        };
    }

    /** @param non-empty-list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
