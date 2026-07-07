<?php

namespace App\Services\Design\Presets;

use App\Services\Platforms\Registry\Platform;
use App\Services\Profile\SectorTaxonomy;

/**
 * Derives the SIGNALS a LaunchRecipe trigger reasons over, from ONE assembled
 * IdentityEvidence bag — the read layer between the evidence and the recipe
 * table. Each signal is tagged with the independent SOURCE GROUP it came from
 * (profile / google / instagram / store / platform-mix) so LaunchRecipeFactor
 * can count how many INDEPENDENT groups concur (spec §1a/§1b: two readings of
 * the same payload are ONE group, not two).
 *
 * Reuse over duplication (spec §1b): sector → SectorTaxonomy::bucketFor; aesthetic
 * lean → AestheticLexicon; price band → the same thresholds StorePricePointFactor
 * uses; cuisine → CuisineLexicon (via IdentityEvidence::cuisineHint). The one thing
 * defined locally is the coarse recipe-FAMILY keyword map, because recipes need a
 * different granularity (archetype families) than the per-factor CategoryStylePresets
 * buckets — it is NOT a copy of a factor's lexicon.
 *
 * A "signal group" here = a provenance bucket. `families()` may return the same
 * family from several groups (e.g. sector says beauty AND Google says beauty);
 * the caller treats those as 2 concordant groups. `familiesByGroup()` exposes the
 * per-group breakdown so the caller can enforce independence precisely.
 */
final class RecipeSignals
{
    // Recipe archetype families — the granularity the recipe triggers key on.
    public const FAM_BEAUTY = 'beauty';

    public const FAM_FITNESS = 'fitness';

    public const FAM_HOSPITALITY = 'hospitality';

    public const FAM_CREATIVE = 'creative';

    public const FAM_MAKER = 'maker';

    public const FAM_PROFESSIONAL = 'professional';

    public const FAM_RETAIL = 'retail';

    // Provenance groups — independent evidence sources. Concordance is counted
    // across DISTINCT groups only.
    public const GROUP_PROFILE = 'profile';

    public const GROUP_GOOGLE = 'google';

    public const GROUP_INSTAGRAM = 'instagram';

    public const GROUP_STORE = 'store';

    public const GROUP_PLATFORM_MIX = 'platform-mix';

    // Price bands (mirror StorePricePointFactor's semantics; recipes only need the
    // coarse premium/mid/accessible read, computed here without hysteresis — a
    // recipe is a one-shot first-impression, not a churn-sensitive live band).
    public const PRICE_PREMIUM = 'premium';

    public const PRICE_MID = 'mid';

    public const PRICE_ACCESSIBLE = 'accessible';

    private const PREMIUM_MIN_AUD = 150.0;

    private const ACCESSIBLE_MAX_AUD = 40.0;

    /**
     * Rough AUD multipliers — same table StorePricePointFactor uses, kept in step.
     */
    private const TO_AUD = [
        'AUD' => 1.0, 'USD' => 1.55, 'EUR' => 1.65,
        'GBP' => 1.95, 'NZD' => 0.92, 'CAD' => 1.13,
    ];

    /**
     * Ordered keyword => recipe family. Specific-before-generic (barber before
     * bar). Deliberately coarser than the per-factor bucket maps: it targets the
     * six archetype families the recipes recognise, not the ten CategoryStylePresets
     * buckets.
     *
     * @var array<string, string>
     */
    private const FAMILY_KEYWORDS = [
        // Beauty / wellness / bridal.
        'barber' => self::FAM_BEAUTY,
        'hair' => self::FAM_BEAUTY,
        'nail' => self::FAM_BEAUTY,
        'lash' => self::FAM_BEAUTY,
        'brow' => self::FAM_BEAUTY,
        'spa' => self::FAM_BEAUTY,
        'beauty' => self::FAM_BEAUTY,
        'cosmetic' => self::FAM_BEAUTY,
        'skincare' => self::FAM_BEAUTY,
        'skin care' => self::FAM_BEAUTY,
        'wellness' => self::FAM_BEAUTY,
        'bridal' => self::FAM_BEAUTY,
        'makeup' => self::FAM_BEAUTY,

        // Fitness / sport.
        'gym' => self::FAM_FITNESS,
        'fitness' => self::FAM_FITNESS,
        'crossfit' => self::FAM_FITNESS,
        'yoga' => self::FAM_FITNESS,
        'pilates' => self::FAM_FITNESS,
        'personal train' => self::FAM_FITNESS,
        'boxing' => self::FAM_FITNESS,
        'martial art' => self::FAM_FITNESS,
        'sport' => self::FAM_FITNESS,
        'athletic' => self::FAM_FITNESS,

        // Hospitality / food.
        'restaurant' => self::FAM_HOSPITALITY,
        'cafe' => self::FAM_HOSPITALITY,
        'coffee' => self::FAM_HOSPITALITY,
        'bakery' => self::FAM_HOSPITALITY,
        'bar' => self::FAM_HOSPITALITY,
        'bistro' => self::FAM_HOSPITALITY,
        'eatery' => self::FAM_HOSPITALITY,
        'food' => self::FAM_HOSPITALITY,
        'catering' => self::FAM_HOSPITALITY,
        'hotel' => self::FAM_HOSPITALITY,

        // Creative / art / photography / design.
        'photograph' => self::FAM_CREATIVE,
        'videograph' => self::FAM_CREATIVE,
        'film' => self::FAM_CREATIVE,
        'art gallery' => self::FAM_CREATIVE,
        'gallery' => self::FAM_CREATIVE,
        'artist' => self::FAM_CREATIVE,
        'design studio' => self::FAM_CREATIVE,
        'graphic design' => self::FAM_CREATIVE,
        'illustrat' => self::FAM_CREATIVE,
        'creative' => self::FAM_CREATIVE,
        'musician' => self::FAM_CREATIVE,

        // Maker / craft / handmade.
        'ceramic' => self::FAM_MAKER,
        'pottery' => self::FAM_MAKER,
        'handmade' => self::FAM_MAKER,
        'craft' => self::FAM_MAKER,
        'woodwork' => self::FAM_MAKER,
        'jewellery' => self::FAM_MAKER,
        'jewelry' => self::FAM_MAKER,
        'maker' => self::FAM_MAKER,
        'artisan' => self::FAM_MAKER,

        // Professional services.
        'lawyer' => self::FAM_PROFESSIONAL,
        'legal' => self::FAM_PROFESSIONAL,
        'accountant' => self::FAM_PROFESSIONAL,
        'consult' => self::FAM_PROFESSIONAL,
        'real estate' => self::FAM_PROFESSIONAL,
        'financial' => self::FAM_PROFESSIONAL,

        // Retail / boutique.
        'boutique' => self::FAM_RETAIL,
        'clothing' => self::FAM_RETAIL,
        'fashion' => self::FAM_RETAIL,
        'retail' => self::FAM_RETAIL,
        'shop' => self::FAM_RETAIL,
    ];

    /** Map a curated sector slug's CategoryStylePresets bucket to a recipe family. */
    private const BUCKET_TO_FAMILY = [
        CategoryStylePresets::BEAUTY_PERSONAL_CARE => self::FAM_BEAUTY,
        CategoryStylePresets::HEALTH_FITNESS => self::FAM_FITNESS,
        CategoryStylePresets::FOOD_DRINK => self::FAM_HOSPITALITY,
        CategoryStylePresets::HOSPITALITY => self::FAM_HOSPITALITY,
        CategoryStylePresets::CREATIVE_ENTERTAINMENT => self::FAM_CREATIVE,
        CategoryStylePresets::PROFESSIONAL_SERVICES => self::FAM_PROFESSIONAL,
        CategoryStylePresets::RETAIL_SHOPPING => self::FAM_RETAIL,
        // home_services, automotive, education map to no recipe family (no recipe
        // for them yet) — omitted so they contribute no family signal.
    ];

    public function __construct(private readonly IdentityEvidence $evidence) {}

    /**
     * The recipe family each independent source group votes for (a group with no
     * classifiable signal is absent from the map). This is the concordance
     * substrate — the caller counts how many DISTINCT groups agree on a family.
     *
     * @return array<string, string> group => family
     */
    public function familiesByGroup(): array
    {
        $out = [];

        // profile: the user's declared, manually-picked sector.
        $sectorFamily = $this->sectorFamily();
        if ($sectorFamily !== null) {
            $out[self::GROUP_PROFILE] = $sectorFamily;
        }

        // google: the Google-Business declared category.
        $googleFamily = $this->familyFromCategory($this->googleCategory());
        if ($googleFamily !== null) {
            $out[self::GROUP_GOOGLE] = $googleFamily;
        }

        // instagram: the IG business category (ONE group even if we read several
        // IG fields — same payload).
        $igFamily = $this->familyFromCategory($this->instagramCategory());
        if ($igFamily !== null) {
            $out[self::GROUP_INSTAGRAM] = $igFamily;
        }

        return $out;
    }

    /**
     * Distinct set of recipe families evidenced across all groups, with the count
     * of independent groups backing each — {family => concordantGroupCount}.
     *
     * @return array<string, int>
     */
    public function familyConcordance(): array
    {
        $counts = [];
        foreach ($this->familiesByGroup() as $family) {
            $counts[$family] = ($counts[$family] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The aesthetic lean (soft/neutral/bold) from the Instagram presentation, or
     * null. Shares the INSTAGRAM group with the IG category family — the caller
     * must NOT count this as an independent group alongside the IG family.
     */
    public function aestheticLean(): ?string
    {
        $payload = $this->evidence->instagramPayload();
        if ($payload === null) {
            return null;
        }

        $votes = array_filter([
            AestheticLexicon::leanOf(is_string($payload['businessCategory'] ?? null) ? $payload['businessCategory'] : ''),
            AestheticLexicon::leanOf(is_string($payload['fullName'] ?? null) ? $payload['fullName'] : ''),
        ], fn ($v) => $v !== null && $v !== AestheticLexicon::NEUTRAL);

        // Need a non-conflicting lean: all non-neutral votes must agree.
        $unique = array_values(array_unique($votes));

        return count($unique) === 1 ? $unique[0] : null;
    }

    /** The coarse store price band (premium/mid/accessible), or null when < 4 products. */
    public function pricePoint(): ?string
    {
        $products = $this->evidence->storeProducts();
        if (count($products) < 4) {
            return null;
        }

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
        $median = $count % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;

        if ($median >= self::PREMIUM_MIN_AUD) {
            return self::PRICE_PREMIUM;
        }
        if ($median <= self::ACCESSIBLE_MAX_AUD) {
            return self::PRICE_ACCESSIBLE;
        }

        return self::PRICE_MID;
    }

    /**
     * Whether the user has at least one active shop connection (the maker-craft
     * gate). 'shop' is a bare platform slug, not a Platform enum case — matches
     * how IdentityEvidence::storeProducts() and OutsideWebsitesFactor reference it.
     */
    public function hasActiveShop(): bool
    {
        return in_array('shop', $this->evidence->platformSlugs(), true);
    }

    /** The cuisine hint (fine_dining / cafe / fast_casual / specific-cuisine), or null. */
    public function cuisineHint(): ?string
    {
        return $this->evidence->cuisineHint();
    }

    /** Whether the connected business is a food/hospitality one (Google or IG says so). */
    public function isHospitality(): bool
    {
        return $this->familyFromCategory($this->googleCategory()) === self::FAM_HOSPITALITY
            || $this->familyFromCategory($this->instagramCategory()) === self::FAM_HOSPITALITY;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** The declared-sector recipe family (manual sector only — matches SectorFactor's gate). */
    private function sectorFamily(): ?string
    {
        $user = $this->evidence->user();
        if (($user->sector_source ?? null) !== 'manual') {
            return null;
        }
        $slug = trim((string) ($user->sector ?? ''));
        if ($slug === '') {
            return null;
        }
        $bucket = SectorTaxonomy::bucketFor($slug);

        return $bucket === null ? null : (self::BUCKET_TO_FAMILY[$bucket] ?? null);
    }

    private function googleCategory(): ?string
    {
        $connection = $this->evidence->connections()->firstWhere('platform', Platform::GoogleBusiness->value);
        $category = $connection && is_array($connection->payload) ? ($connection->payload['category'] ?? null) : null;

        return is_string($category) ? $category : null;
    }

    private function instagramCategory(): ?string
    {
        $payload = $this->evidence->instagramPayload();
        $category = is_array($payload) ? ($payload['businessCategory'] ?? null) : null;

        return is_string($category) ? $category : null;
    }

    /** Classify a raw category string into a recipe family via the coarse keyword map. */
    private function familyFromCategory(?string $category): ?string
    {
        if (! is_string($category)) {
            return null;
        }
        $lower = strtolower(trim($category));
        if ($lower === '' || $lower === 'none') {
            return null;
        }

        foreach (self::FAMILY_KEYWORDS as $keyword => $family) {
            if (str_contains($lower, $keyword)) {
                return $family;
            }
        }

        return null;
    }
}
