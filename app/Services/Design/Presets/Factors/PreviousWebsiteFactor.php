<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\Presets\SiteDesignFactor;
use App\Services\Design\Presets\StyleTiers;

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
    public function detect(User $user, Site $site): array
    {
        $workplace = Workplace::query()->find((string) $site->id);
        $url = trim((string) ($workplace?->previous_website ?? ''));
        $analysis = $workplace?->previous_website_analysis;

        if ($url === ''
            || ! is_array($analysis)
            || ($analysis['url'] ?? null) !== $url
            || ($analysis['ok'] ?? false) !== true) {
            return [];
        }

        $out = StyleTiers::columnsFromTiers(is_array($analysis['tiers'] ?? null) ? $analysis['tiers'] : []);

        $accent = $analysis['accent'] ?? null;
        if (is_string($accent) && preg_match('/^#[0-9a-f]{6}$/i', $accent)) {
            $out['color_accent'] = strtolower($accent);
        }

        return $out;
    }
}
