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
 * and stores it — nothing is fetched here) and tone-maps it into kit columns:
 * the accent role from the most accent-able palette colour, checked with real
 * WCAG 4.5:1 contrast math against the theme background it will sit on, and
 * the neutral room (theme_mode) from the palette's own temperature. A neutral
 * black/white wordmark is a graceful NO-OP with a named reason — the sector
 * seed stays in charge and the UI gets honest empty-state copy, never a
 * fabricated accent.
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

    /** Proposal reason when no processed logo palette exists yet. */
    public const REASON_NO_PALETTE = 'no_palette';

    /**
     * The only design_kits columns autopilot may ever write. Proposal arrays
     * reach a column-named UPDATE, so the boundary is a closed list here
     * rather than trust in every future caller.
     *
     * @var list<string>
     */
    public const WRITABLE = ['color_accent', 'theme_mode', 'typography_font_family'];

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
        $palette = $this->persistedLogoPalette($siteId);
        if ($palette === null) {
            return ['proposals' => [], 'reason' => self::REASON_NO_PALETTE];
        }

        // The neutral room first: a warm palette reads on the warm cream,
        // everything else stays on the default light room. Dark rooms are
        // never auto-proposed — they are the strongest look in the kit and
        // §13 reserves that decision for the sector seed or the user.
        $proposals = [];
        $mode = ! empty($palette['warm']) ? 'warm' : ThemeModePalettes::DEFAULT_MODE;
        $background = ThemeModePalettes::anchorsFor($mode)['bg'];

        $accent = $this->accentFrom($palette, $background);
        if ($accent === null) {
            // Every candidate was near-white/near-black/monochrome: a neutral
            // wordmark. Falling back to the sector seed happens by ABSENCE —
            // proposing nothing is the fallback.
            return ['proposals' => [], 'reason' => self::REASON_NEUTRAL_WORDMARK];
        }

        $proposals['color_accent'] = $accent;
        if ($mode !== ThemeModePalettes::DEFAULT_MODE) {
            $proposals['theme_mode'] = $mode;
        }

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
        $font = $this->fonts->classify($html);

        return [
            'proposals' => $font === null ? [] : ['typography_font_family' => $font],
            'reason' => $font === null ? 'no_font_evidence' : null,
        ];
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
                $current = $existing?->{$column} ?? null;
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
     * The persisted palette of the site's processed logo — logo_full
     * preferred over logo_square, same preference (and same reason) as
     * SiteAccentResolver: the fuller mark is the more representative one.
     *
     * @return array<string, mixed>|null
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

            if (is_string($palette)) {
                $palette = json_decode($palette, true);
            }
            if (is_array($palette)) {
                return $palette;
            }
        }

        return null;
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
