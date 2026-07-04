<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\Presets\SiteDesignFactor;
use App\Services\Design\Presets\StyleTiers;
use App\Services\Design\Scan\EvidenceConclusions;
use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Support\Collection;

// Top-of-hierarchy factor: the user's PREVIOUS WEBSITE (site.workplaces.
// previous_website — written manually in settings or auto-filled by Google
// Business) is analyzed once per URL (AnalyzePreviousWebsiteJob) and its
// conclusions styled onto the sitepage. Accent is the raw detected brand
// colour (the one snap-don't-copy exception); everything else maps through
// StyleTiers. A stale analysis (URL since changed) or a failed one
// contributes nothing.
class PreviousWebsiteFactor implements SiteDesignFactor
{
    public const SOURCE = 'previous-website:styles';

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'previous-website';
    }

    public function priority(): int
    {
        // Above Google Business (50) and Instagram (30) — their OWN old site
        // is the strongest identity signal we have.
        return 100;
    }

    /** @return array<string, string> */
    public function detect(User $user, Site $site, Collection $activeConnections): array
    {
        // $activeConnections unused: this factor reads Workplace, not connections.
        $workplace = Workplace::query()->find((string) $site->id);
        $url = trim((string) ($workplace?->previous_website ?? ''));
        $analysis = $workplace?->previous_website_analysis;

        // v2 docs only — pre-rebuild (v1) analyses were exactly the unreliable
        // reads this scanner replaced; they contribute nothing until re-scanned.
        if ($url === ''
            || ! is_array($analysis)
            || ($analysis['url'] ?? null) !== $url
            || ($analysis['v'] ?? null) !== WebsiteStyleAnalyzer::VERSION
            || ($analysis['ok'] ?? false) !== true) {
            return [];
        }

        $out = StyleTiers::columnsFromTiers(self::confidentTiers($analysis));

        $accent = $analysis['accent'] ?? null;
        $hex = is_array($accent) ? ($accent['hex'] ?? null) : null;
        if (is_string($hex)
            && preg_match('/^#[0-9a-f]{6}$/i', $hex)
            && (float) ($accent['confidence'] ?? 0) >= EvidenceConclusions::minConfidence()) {
            $out['color_accent'] = strtolower($hex);
        }

        return $out;
    }

    /**
     * Signal tiers meeting the confidence bar, from a v2 analysis document.
     * Shared by OutsideWebsitesFactor so both factors read one contract.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, string>
     */
    public static function confidentTiers(array $analysis): array
    {
        $tiers = [];
        foreach (is_array($analysis['signals'] ?? null) ? $analysis['signals'] : [] as $signal => $data) {
            if (is_array($data)
                && is_string($data['tier'] ?? null)
                && (float) ($data['confidence'] ?? 0) >= EvidenceConclusions::minConfidence()) {
                $tiers[$signal] = $data['tier'];
            }
        }

        return $tiers;
    }
}
