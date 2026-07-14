<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;
use Illuminate\Support\Facades\Cache;

/**
 * Refiner factor (band D, 58): the median product price of a connected store is
 * a real "how premium is this" signal that no category recipe captures — a
 * luxury boutique and a casual accessories shop are both "retail" but should not
 * look alike. Median (not mean) so one outlier product can't skew the read;
 * currency roughly normalised to AUD. Abstains under 4 products (spec §2 row 10).
 *
 * Band → sparse targets (space + border + motion + surface; colours/font
 * still come from the category recipe underneath):
 *   luxury  (median ≳ A$150) → airy space, hairline borders, slow motion, outline surface
 *   mid                       → neutral: regular space, standard border, normal motion
 *   casual  (median ≲ A$40)  → compact space, rounded corners, friendly weight, solid surface
 * Luxury=outline vs casual=solid is the tier contrast: hairline restraint
 * against filled market-stall confidence.
 *
 * Auto mode with ANTI-FLICKER HYSTERESIS (spec §5): a store must not restyle
 * because someone added three cheap accessories. A band only FLIPS when the new
 * median crosses the boundary by ≥15% (a Schmitt dead-band) AND stays crossed
 * for 2 consecutive refreshes. The last-emitted band is recovered from this
 * source's own prior `space_regular` contribution (each band emits a distinct
 * one); the consecutive-cross streak lives in a short-lived cache key.
 */
class StorePricePointFactor implements EvidenceFactor
{
    public const SOURCE = 'store:price-point';

    private const BAND_LUXURY = 'luxury';

    private const BAND_MID = 'mid';

    private const BAND_CASUAL = 'casual';

    /** Boundary thresholds in AUD. */
    private const LUXURY_MIN = 150.0;

    private const CASUAL_MAX = 40.0;

    /** Fraction a median must clear a boundary by before a flip is even considered. */
    private const HYSTERESIS = 0.15;

    /** Consecutive confident cross-reads required to commit a flip. */
    private const STREAK_TO_FLIP = 2;

    private const MIN_PRODUCTS = 4;

    private const STREAK_TTL_SECONDS = 30 * 86400;

    /**
     * Rough AUD conversion multipliers — enough to keep the band read honest for
     * the common stores, not a real FX feed. Unknown currencies fall through at
     * 1.0 (treated as AUD-scale). Keyed by upper-case ISO code.
     */
    private const TO_AUD = [
        'AUD' => 1.0,
        'USD' => 1.55,
        'EUR' => 1.65,
        'GBP' => 1.95,
        'NZD' => 0.92,
        'CAD' => 1.13,
    ];

    /** Each band's sparse overlay. space_regular is the distinct hysteresis anchor (reverse-mapped on the next resolve). */
    private const BAND_TARGETS = [
        self::BAND_LUXURY => [
            'space_regular' => '0.8rem',    // generous (ALD-tightened)
            'border_thickness' => '0.5px',  // hairline
            'motion_pace' => 'slow',
        ],
        self::BAND_MID => [
            'space_regular' => '0.625rem',  // regular (ALD base)
            // Standard border (1px) — mid means NEUTRAL. The 2026-07-10 label
            // shift briefly dragged this to 0.5px, collapsing the luxury
            // hairline into it; the tier contrast is restored deliberately.
            'border_thickness' => '1px',
            'motion_pace' => 'normal',
        ],
        self::BAND_CASUAL => [
            'space_regular' => '0.5rem',    // compact (ALD-tightened)
            'border_radius' => '0.25rem',   // softly rounded (ALD-square-centered)
            'weight_regular' => '500',
            'motion_pace' => 'normal',
        ],
    ];

    /** Reverse of each band's space_regular anchor → the band that emitted it. */
    private const SPACE_TO_BAND = [
        '0.8rem' => self::BAND_LUXURY,
        '0.625rem' => self::BAND_MID,
        '0.5rem' => self::BAND_CASUAL,
    ];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'store';
    }

    public function mode(): FactorMode
    {
        return FactorMode::Auto;
    }

    public function priority(): int
    {
        // Band D (refiners) — above the category recipe (band C) so premium/casual
        // space + motion override it, but below declared sector (band E).
        return 58;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $products = $evidence->storeProducts();
        if (count($products) < self::MIN_PRODUCTS) {
            return []; // too few products to conclude a price point
        }

        $median = $this->medianAud($products);
        if ($median === null) {
            return [];
        }

        $rawBand = $this->bandFor($median);
        $priorBand = $this->priorBand($evidence);
        $band = $this->applyHysteresis($evidence, $median, $rawBand, $priorBand);

        return self::BAND_TARGETS[$band];
    }

    /** Median product price converted to AUD, or null if nothing parsed. */
    private function medianAud(array $products): ?float
    {
        $values = [];
        foreach ($products as $p) {
            $currency = is_string($p['currency'] ?? null) ? strtoupper($p['currency']) : 'AUD';
            $values[] = $p['price'] * (self::TO_AUD[$currency] ?? 1.0);
        }
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** The raw band a median falls in, ignoring hysteresis. */
    private function bandFor(float $median): string
    {
        if ($median >= self::LUXURY_MIN) {
            return self::BAND_LUXURY;
        }
        if ($median <= self::CASUAL_MAX) {
            return self::BAND_CASUAL;
        }

        return self::BAND_MID;
    }

    /** The band this source last emitted, recovered from its prior space_regular contribution. */
    private function priorBand(IdentityEvidence $evidence): ?string
    {
        $priorSpace = $evidence->priorContributionValue(self::SOURCE, 'space_regular');

        return $priorSpace === null ? null : (self::SPACE_TO_BAND[$priorSpace] ?? null);
    }

    /**
     * Hold the prior band unless the new median has crossed the relevant boundary
     * by ≥15% for STREAK_TO_FLIP consecutive refreshes. With no prior band (first
     * resolve for this store) the raw band commits immediately — there's nothing
     * to flicker away from.
     */
    private function applyHysteresis(IdentityEvidence $evidence, float $median, string $rawBand, ?string $priorBand): string
    {
        if ($priorBand === null || $rawBand === $priorBand) {
            $this->clearStreak($evidence);

            return $rawBand;
        }

        // A candidate flip: only counts if the median is decisively past the
        // boundary between the two bands (outside the ±15% dead-band).
        if (! $this->crossesDecisively($median, $rawBand, $priorBand)) {
            $this->clearStreak($evidence);

            return $priorBand;
        }

        $streak = $this->bumpStreak($evidence, $rawBand);
        if ($streak >= self::STREAK_TO_FLIP) {
            $this->clearStreak($evidence);

            return $rawBand; // sustained cross — commit the flip
        }

        return $priorBand; // crossed once; wait for confirmation
    }

    /** Is $median past the boundary between $priorBand and $rawBand by ≥ HYSTERESIS? */
    private function crossesDecisively(float $median, string $rawBand, string $priorBand): bool
    {
        // Moving toward luxury: must clear LUXURY_MIN * 1.15.
        if ($rawBand === self::BAND_LUXURY) {
            return $median >= self::LUXURY_MIN * (1 + self::HYSTERESIS);
        }
        // Moving toward casual: must fall under CASUAL_MAX * 0.85.
        if ($rawBand === self::BAND_CASUAL) {
            return $median <= self::CASUAL_MAX * (1 - self::HYSTERESIS);
        }

        // Moving toward mid FROM a neighbour: must be decisively inside the mid
        // range (past whichever boundary it's leaving by the dead-band margin).
        if ($priorBand === self::BAND_LUXURY) {
            return $median <= self::LUXURY_MIN * (1 - self::HYSTERESIS);
        }
        if ($priorBand === self::BAND_CASUAL) {
            return $median >= self::CASUAL_MAX * (1 + self::HYSTERESIS);
        }

        return true;
    }

    private function streakKey(IdentityEvidence $evidence): string
    {
        return 'design:store-price-streak:'.$evidence->site()->id;
    }

    /**
     * Increment the consecutive-cross streak toward a specific target band. A
     * cross toward a DIFFERENT band than the one currently streaking restarts the
     * count at 1 (the store is drifting, not settling).
     */
    private function bumpStreak(IdentityEvidence $evidence, string $towardBand): int
    {
        $key = $this->streakKey($evidence);
        $state = Cache::get($key);
        $count = (is_array($state) && ($state['band'] ?? null) === $towardBand)
            ? (int) ($state['count'] ?? 0) + 1
            : 1;

        Cache::put($key, ['band' => $towardBand, 'count' => $count], self::STREAK_TTL_SECONDS);

        return $count;
    }

    private function clearStreak(IdentityEvidence $evidence): void
    {
        Cache::forget($this->streakKey($evidence));
    }
}
