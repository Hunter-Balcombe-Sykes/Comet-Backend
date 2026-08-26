<?php

namespace App\Services\Design;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\WebsiteScan\AccentQuality;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;

/**
 * Design-kit autopilot (plan §13): the two new derivation sources beside
 * `fromSector`, and the one fill-if-empty persister they share.
 *
 * `fromBrandPalette` reads the PERSISTED logo palette (the pipeline extracts
 * and stores it — nothing is fetched here) and tone-maps it into the accent
 * column: the accent role from the most accent-able palette colour, checked
 * with real WCAG 4.5:1 contrast math against the theme background it will sit
 * on. A neutral black/white wordmark is a graceful NO-OP with a named reason —
 * the sector seed stays in charge and the UI gets honest empty-state copy,
 * never a fabricated accent.
 *
 * It used to propose a neutral room (theme_mode, column removed 2026-08-27) from the palette's warmth
 * too. That went with the 2026-08-06 simplification: one palette survives, so
 * there is no room to choose. (The proposal was also quietly broken — it
 * emitted 'warm', a mode the sitepage renderer has never known, so those
 * sites silently fell back to the default anyway.)
 *
 * `fromWebsiteEvidence` is the scan-time half: the theme-colour accent
 * already ships (SiteAccentResolver); this adds the font keyword classifier
 * §13 ordered built as real code.
 *
 * Persisted auto-writes stay FILL-IF-EMPTY (§13). A non-null kit column —
 * even one an earlier applier wrote, which carries no provenance — is
 * treated as manual and never clobbered; the "Restyle from brand" flow is
 * the sanctioned upgrade path, and it is never silent.
 */
class DesignKitAutopilot
{
    /** Proposal reason when the logo is a neutral wordmark. */
    public const REASON_NEUTRAL_WORDMARK = 'neutral_wordmark';

    /** Proposal reason when the GALLERY-sourced palette (the logo-less
     *  fallback tier) held nothing accent-able — the wordmark copy would be
     *  a lie for a site that has no wordmark at all (plan-02 critic find). */
    public const REASON_NEUTRAL_PALETTE = 'neutral_palette';

    /** Proposal reason when no processed logo palette exists yet. */
    public const REASON_NO_PALETTE = 'no_palette';

    /** Proposal reason when scanned CSS holds too little shape evidence. */
    public const REASON_NO_CORNER_EVIDENCE = 'no_corner_evidence';

    /** Proposal reason when scanned CSS holds too little density evidence. */
    public const REASON_NO_SPACING_EVIDENCE = 'no_spacing_evidence';

    /**
     * The only design_kits columns autopilot may ever write. Proposal arrays
     * reach a column-named UPDATE, so the boundary is a closed list here
     * rather than trust in every future caller.
     *
     * Widened 2026-08-27 (plan 02 step 5): scanned-website CSS genuinely
     * speaks to corners (the site's own border-radius usage IS the brand's
     * shape language) and, coarsely, to spacing (measured padding density).
     * text_size and typography_uppercase stay OUT — no honest evidence
     * source proposes either (the taste map's honesty rule).
     *
     * @var list<string>
     */
    public const WRITABLE = ['color_accent', 'typography_font_family', 'corners', 'spacing'];

    public function __construct(
        private readonly FontKeywordClassifier $fonts,
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    /**
     * Kit columns derived from the persisted logo palette.
     *
     * @return array{proposals: array<string, string>, reason: ?string}
     */
    public function fromBrandPalette(string $siteId): array
    {
        $found = $this->persistedLogoPalette($siteId);
        if ($found === null) {
            return ['proposals' => [], 'reason' => self::REASON_NO_PALETTE];
        }
        [$palette, $source] = $found;

        // One palette survives the 2026-08-06 simplification, so the accent is
        // contrast-checked against the only background it can ever sit on.
        $proposals = [];
        $background = ThemeModePalettes::anchorsFor(null)['bg'];

        $accent = $this->accentFrom($palette, $background);
        if ($accent === null) {
            // Every candidate was near-white/near-black/monochrome. Falling
            // back to the sector seed happens by ABSENCE — proposing nothing
            // is the fallback. The reason names the actual source: a logo
            // palette is a neutral WORDMARK; a gallery palette (the
            // logo-less tier) is just a neutral photo.
            return ['proposals' => [], 'reason' => $source === 'gallery'
                ? self::REASON_NEUTRAL_PALETTE
                : self::REASON_NEUTRAL_WORDMARK];
        }

        $proposals['color_accent'] = $accent;

        return ['proposals' => $proposals, 'reason' => null];
    }

    /**
     * Kit columns derived from a scanned website's own markup. The accent
     * half of website evidence lives in SiteAccentResolver (unchanged); this
     * contributes the font register.
     *
     * @return array{proposals: array<string, string>, reason: ?string}
     */
    public function fromWebsiteEvidence(string $html): array
    {
        $proposals = [];
        $reasons = [];

        $font = $this->fonts->classify($html);
        if ($font !== null) {
            $proposals['typography_font_family'] = $font;
        } else {
            $reasons[] = 'no_font_evidence';
        }

        // Corners: the scanned site's own border-radius usage is real
        // evidence of the brand's shape language (plan 02 step 5). Median
        // declared radius snapped to the NEAREST kit rung (0 / 5.6 / 16px
        // → boundaries 2.8 and 10.8); 'default' is a real proposal, not a
        // no-op — evidence beats the sector look for this user.
        $corners = $this->cornersFromCss($html);
        if ($corners !== null) {
            $proposals['corners'] = $corners;
        } else {
            $reasons[] = self::REASON_NO_CORNER_EVIDENCE;
        }

        // Spacing: coarse 2-bucket density read of the scanned CSS's own
        // padding/margin values — airy sites pad generously.
        $spacing = $this->spacingFromCss($html);
        if ($spacing !== null) {
            $proposals['spacing'] = $spacing;
        } else {
            $reasons[] = self::REASON_NO_SPACING_EVIDENCE;
        }

        return [
            'proposals' => $proposals,
            'reason' => $proposals === [] ? implode(',', $reasons) : null,
        ];
    }

    /**
     * The scanned site's shape language, as a corners selection — or null
     * below the evidence floor (fewer than 3 usable declarations).
     */
    private function cornersFromCss(string $html): ?string
    {
        $values = $this->cssPxValues($html, 'border-radius');
        // Pill/circle radii (50%, 999px…) are component shapes, not the
        // brand's box language — cssPxValues drops percentages already;
        // drop the huge sentinels here.
        $values = array_values(array_filter($values, static fn (float $v): bool => $v < 100.0));
        if (count($values) < 3) {
            return null;
        }

        $median = self::median($values);
        if ($median < 2.8) {
            return 'sharp';
        }

        return $median < 10.8 ? 'default' : 'rounded';
    }

    /**
     * The scanned site's density, as a spacing selection — or null below
     * the evidence floor (fewer than 5 usable declarations).
     */
    private function spacingFromCss(string $html): ?string
    {
        $values = [...$this->cssPxValues($html, 'padding'), ...$this->cssPxValues($html, 'margin')];
        if (count($values) < 5) {
            return null;
        }

        return self::median($values) >= 24.0 ? 'spacious' : 'default';
    }

    /**
     * Every px-comparable length declared for a property family in the
     * page's CSS (style attributes + <style> blocks arrive as one HTML
     * string). rem converts at 16; percentages and keywords are skipped —
     * they say nothing absolute.
     *
     * @return list<float>
     */
    private function cssPxValues(string $html, string $property): array
    {
        $out = [];
        if (preg_match_all(
            // (?<![a-z-]) anchors the property to a declaration start:
            // without it, scroll-padding / scroll-margin / -webkit-padding
            // (scroll-snap resets are everywhere) polluted the density
            // evidence behind a PERMANENT fill-if-empty write (plan-02
            // critic find, 2026-08-27). padding-top etc. still match via
            // the trailing [a-z-]*.
            '/(?<![a-z-])'.preg_quote($property, '/').'[a-z-]*\s*:\s*([^;"}]+)/i',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $valueList) {
                if (preg_match_all('/(\d*\.?\d+)(px|rem|em)\b/i', $valueList, $lengths, PREG_SET_ORDER)) {
                    foreach ($lengths as $length) {
                        $n = (float) $length[1];
                        $out[] = strtolower($length[2]) === 'px' ? $n : $n * 16.0;
                    }
                }
            }
        }

        return $out;
    }

    /** @param  non-empty-list<float>  $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    /**
     * Persist proposals fill-if-empty: each column is written only when it is
     * currently NULL, under the same locked transaction shape
     * DesignKitAccentApplier uses — two concurrent appliers must not both
     * read blank and both win.
     *
     * @param  array<string, string>  $proposals
     * @return list<string> the columns actually written
     */
    public function persistFillIfEmpty(string $siteId, array $proposals): array
    {
        $proposals = array_intersect_key($proposals, array_flip(self::WRITABLE));
        if ($proposals === []) {
            return [];
        }

        $wrote = [];

        DB::connection('pgsql')->transaction(function () use ($siteId, $proposals, &$wrote) {
            $existing = DB::connection('pgsql')->table('site.design_kits')
                ->where('site_id', $siteId)->lockForUpdate()->first();

            $values = [];
            foreach ($proposals as $column => $value) {
                $current = $existing?->{$column};
                if ($current === null) {
                    $values[$column] = $value;
                }
            }

            if ($values === []) {
                return;
            }

            DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(
                ['site_id' => $siteId],
                [...$values, 'updated_at' => now()],
            );
            $wrote = array_keys($values);
        });

        if ($wrote !== []) {
            BuildState::bump($siteId);
            $this->invalidator->touchSite(fn () => Site::find($siteId), 'design-autopilot', ['site_id' => $siteId]);
        }

        return $wrote;
    }

    /**
     * The persisted palette of the site's brand imagery — logo_full over
     * logo_square (the fuller mark is the more representative one, same
     * preference as SiteAccentResolver), then the OLDEST ready gallery
     * image as the last resort (plan 02 step 5 / decision 3: a site with
     * no logo at all still has its own imagery to speak for it; the
     * earliest upload is the one the owner led with). The plan's stub
     * named "avatar" between logo and gallery — no avatar concept exists
     * anywhere in the platform (no column, no purpose, no upload), so the
     * chain goes straight to gallery; logged in the run log.
     *
     * @return array{0: array<string, mixed>, 1: string}|null [palette, source: 'logo'|'gallery']
     */
    private function persistedLogoPalette(string $siteId): ?array
    {
        foreach ([SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE] as $purpose) {
            $palette = SiteMedia::query()
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', $purpose)
                ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                ->whereNotNull('palette')
                ->value('palette');

            $decoded = self::decodedPalette($palette);
            if ($decoded !== null) {
                return [$decoded, 'logo'];
            }
        }

        // POOL_GALLERY ONLY — not GALLERY_POOLS: POOL_CONTENT holds
        // platform-synced imagery, and Instagram imagery is explicitly
        // EXCLUDED as an accent source (owner decision; plan-02 critic
        // note upgraded to a fix). The curated gallery is the tier
        // decision 3 actually named.
        $galleryPalette = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->whereNotNull('palette')
            ->orderBy('created_at')
            ->value('palette');

        $decoded = self::decodedPalette($galleryPalette);

        return $decoded === null ? null : [$decoded, 'gallery'];
    }

    /** @return array<string, mixed>|null */
    private static function decodedPalette(mixed $palette): ?array
    {
        if (is_string($palette)) {
            $palette = json_decode($palette, true);
        }

        return is_array($palette) ? $palette : null;
    }

    /**
     * The accent role from the palette: dominant first, then the stored
     * colour list, each gated by AccentQuality (the "is this an accent at
     * all?" band) and then tone-mapped to real WCAG AA against the room it
     * will sit in. Tone mapping — not rejection — is the §13 difference: a
     * brand red that is slightly too light gets darkened until it reads,
     * instead of losing to the sector default.
     */
    private function accentFrom(array $palette, string $background): ?string
    {
        $candidates = [];
        if (is_string($palette['dominant'] ?? null)) {
            $candidates[] = $palette['dominant'];
        }
        foreach ((array) ($palette['colors'] ?? []) as $color) {
            if (is_string($color)) {
                $candidates[] = $color;
            }
        }

        foreach ($candidates as $candidate) {
            $hex = AccentQuality::normalizeHex($candidate);
            if ($hex === null || ! AccentQuality::qualifies($hex)) {
                continue;
            }

            $toned = WcagContrast::toneToAa($hex, $background);
            if ($toned !== null) {
                return $toned;
            }
        }

        return null;
    }
}
