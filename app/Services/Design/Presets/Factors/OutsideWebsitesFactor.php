<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\SiteDesignFactor;
use App\Services\Design\Presets\StyleTiers;
use App\Services\Design\WebsiteStyleAnalyzer;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Collection;

// Bottom-of-hierarchy factor: the "outside connected websites" — every shop
// brand's store URL + every custom link the user attached, each analyzed once
// at add time (AnalyzeConnectionWebsitesJob stores a styleAnalysis — in the
// connection's payload for custom links, in the brand's own row for shop
// brands post-FOUND-25). Per signal, the MODE across all analyzed sites wins
// (e.g. 4 dark backgrounds + 1 light → dark); a tie means no confident
// conclusion → no contribution for that column. No accent and no logo
// grabbing here — snapped tiers only. Recomputed on every resolve, so
// adding/removing any website reapplies the aggregate — filtered from the
// resolver-supplied active connections, no query of its own: DesignPresetResolver
// eager-loads shopBrands on that SAME collection, so reading it here is a plain
// in-memory relation access, not a per-connection lazy load.
class OutsideWebsitesFactor implements SiteDesignFactor
{
    public const SOURCE = 'outside-websites:styles';

    /** Platforms whose connections carry outside-website URLs. */
    public const SOURCE_PLATFORMS = [Platform::Custom->value, 'shop'];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'outside-websites';
    }

    public function priority(): int
    {
        // As low as possible — below Instagram (30): third-party sites the
        // user merely links to are the weakest identity signal.
        return 10;
    }

    /** @return array<string, string> */
    public function detect(User $user, Site $site, Collection $activeConnections): array
    {
        $analyses = $this->collectAnalyses($activeConnections);
        if ($analyses === []) {
            return [];
        }

        // Confidence-gated tiers per analysis (v2 documents only), then the
        // per-signal mode across sites.
        $tierSets = array_map(PreviousWebsiteFactor::confidentTiers(...), $analyses);

        $winners = [];
        foreach (array_keys(StyleTiers::SIGNAL_COLUMNS) as $signal) {
            $votes = [];
            foreach ($tierSets as $tiers) {
                if (isset($tiers[$signal])) {
                    $votes[] = $tiers[$signal];
                }
            }
            $mode = $this->strictMode($votes);
            if ($mode !== null) {
                $winners[$signal] = $mode;
            }
        }

        return StyleTiers::columnsFromTiers($winners);
    }

    /**
     * Successful styleAnalysis payloads across all active custom links + shop
     * brands.
     *
     * @param  Collection<int, IntegrationConnection>  $connections  resolver-supplied active connections (superset); filtered in memory
     * @return list<array<string, mixed>>
     */
    private function collectAnalyses(Collection $connections): array
    {
        $connections = $connections->whereIn('platform', self::SOURCE_PLATFORMS);

        $analyses = [];
        foreach ($connections as $connection) {
            if ($connection->platform === Platform::Custom->value) {
                $payload = is_array($connection->payload) ? $connection->payload : [];
                $analysis = $payload['styleAnalysis'] ?? null;
                if ($this->usable($analysis)) {
                    $analyses[] = $analysis;
                }

                continue;
            }

            // shop: each connected brand's styleAnalysis lives in its own
            // site.shop_brands row now (FOUND-25), not a payload map — the
            // connection's payload is just the static relational marker.
            foreach ($connection->shopBrands as $brand) {
                if ($this->usable($brand->style_analysis)) {
                    $analyses[] = $brand->style_analysis;
                }
            }
        }

        return $analyses;
    }

    /** Successful CURRENT-version analysis? (v1 reads contribute nothing.) */
    private function usable(mixed $analysis): bool
    {
        return is_array($analysis)
            && ($analysis['ok'] ?? false) === true
            && ($analysis['v'] ?? null) === WebsiteStyleAnalyzer::VERSION;
    }

    /** Most frequent value, or null on empty input / a tie for first place. */
    private function strictMode(array $votes): ?string
    {
        if ($votes === []) {
            return null;
        }
        $counts = array_count_values($votes);
        arsort($counts);
        $values = array_values($counts);
        if (count($values) > 1 && $values[0] === $values[1]) {
            return null; // tied — no confident conclusion
        }

        return (string) array_key_first($counts);
    }
}
