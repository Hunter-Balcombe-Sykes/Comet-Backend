<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\SiteDesignFactor;
use App\Services\Design\Presets\StyleTiers;

// Bottom-of-hierarchy factor: the "outside connected websites" — every shop
// brand's store URL + every custom link the user attached, each analyzed once
// at add time (AnalyzeConnectionWebsitesJob stores a styleAnalysis in the
// payload). Per signal, the MODE across all analyzed sites wins (e.g. 4 dark
// backgrounds + 1 light → dark); a tie means no confident conclusion → no
// contribution for that column. No accent and no logo grabbing here — snapped
// tiers only. Recomputed on every resolve, so adding/removing any website
// reapplies the aggregate.
class OutsideWebsitesFactor implements SiteDesignFactor
{
    public const SOURCE = 'outside-websites:styles';

    /** Platforms whose connections carry outside-website URLs. */
    public const SOURCE_PLATFORMS = ['custom', 'shop'];

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
    public function detect(User $user, Site $site): array
    {
        $analyses = $this->collectAnalyses($user);
        if ($analyses === []) {
            return [];
        }

        $winners = [];
        foreach (array_keys(StyleTiers::SIGNAL_COLUMNS) as $signal) {
            $votes = [];
            foreach ($analyses as $analysis) {
                $tier = $analysis['tiers'][$signal] ?? null;
                if (is_string($tier) && $tier !== '') {
                    $votes[] = $tier;
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
     * @return list<array<string, mixed>>
     */
    private function collectAnalyses(User $user): array
    {
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('platform', self::SOURCE_PLATFORMS)
            ->get();

        $analyses = [];
        foreach ($connections as $connection) {
            $payload = is_array($connection->payload) ? $connection->payload : [];

            if ($connection->platform === 'custom') {
                $analysis = $payload['styleAnalysis'] ?? null;
                if (is_array($analysis) && ($analysis['ok'] ?? false) === true) {
                    $analyses[] = $analysis;
                }

                continue;
            }

            // shop: payload is a brand-keyed map; each brand entry may carry
            // its own styleAnalysis.
            foreach ($payload as $brand) {
                $analysis = is_array($brand) ? ($brand['styleAnalysis'] ?? null) : null;
                if (is_array($analysis) && ($analysis['ok'] ?? false) === true) {
                    $analyses[] = $analysis;
                }
            }
        }

        return $analyses;
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
