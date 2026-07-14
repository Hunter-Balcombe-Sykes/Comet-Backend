<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformRegistry;

/**
 * Ambient factor (band A, 12): the MIX of platform TYPES a user connects is a
 * weak "what kind of operator is this" signal. A creator with YouTube+Twitch
 * reads different from a salon with Google-Business+Fresha or a shop. We collapse
 * each active platform to a "vibe" via its registered PlatformCategory, take the
 * dominant vibe, and emit a SPARSE overlay (motion + effect character only) that
 * any real category factor (band C+) overrides. Deliberately the lowest band
 * besides outside-websites — it's a tiebreaker, never a headline.
 *
 * Auto mode: recomputes as platforms connect/disconnect (the mix is live state).
 * Abstains unless one vibe has a strict plurality (a genuine lean, not a tie).
 *
 * Vibe → sparse targets (spec §2 row 9; effect_surface retired 2026-07-15):
 *   creator-av       → media-forward + energetic: fast motion, hard shadows
 *   social-lifestyle → imagery-led + soft: normal motion, soft shadows
 *   local-service    → trustworthy + calm: slow motion, soft shadows
 *   retail           → clean + flat: normal motion, flat shadows
 */
class PlatformMixFactor implements EvidenceFactor
{
    public const SOURCE = 'platform-mix:vibe';

    private const VIBE_CREATOR_AV = 'creator-av';

    private const VIBE_SOCIAL_LIFESTYLE = 'social-lifestyle';

    private const VIBE_LOCAL_SERVICE = 'local-service';

    private const VIBE_RETAIL = 'retail';

    /** Sparse design-kit overlay per vibe. Only motion + effect character — the axes the mix is opinionated about. */
    private const VIBE_TARGETS = [
        self::VIBE_CREATOR_AV => [
            'motion_pace' => 'fast',
            'effect_shadow_style' => 'hard',
        ],
        self::VIBE_SOCIAL_LIFESTYLE => [
            'motion_pace' => 'normal',
            'effect_shadow_style' => 'soft',
        ],
        self::VIBE_LOCAL_SERVICE => [
            'motion_pace' => 'slow',
            'effect_shadow_style' => 'soft',
        ],
        self::VIBE_RETAIL => [
            'motion_pace' => 'normal',
            'effect_shadow_style' => 'flat',
        ],
    ];

    public function __construct(private readonly PlatformRegistry $registry) {}

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'platform-mix';
    }

    public function mode(): FactorMode
    {
        return FactorMode::Auto;
    }

    public function priority(): int
    {
        // Band A (ambient) — one notch above outside-websites (10), so any
        // real category/refiner factor beats it. It's a vibe tiebreaker only.
        return 12;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $counts = [
            self::VIBE_CREATOR_AV => 0,
            self::VIBE_SOCIAL_LIFESTYLE => 0,
            self::VIBE_LOCAL_SERVICE => 0,
            self::VIBE_RETAIL => 0,
        ];

        foreach ($evidence->platformSlugs() as $slug) {
            $vibe = $this->vibeFor($slug);
            if ($vibe !== null) {
                $counts[$vibe]++;
            }
        }

        $vibe = $this->plurality($counts);

        return $vibe === null ? [] : self::VIBE_TARGETS[$vibe];
    }

    /** The vibe a platform slug belongs to, via its registered category. Null when unknown/uncategorised. */
    private function vibeFor(string $slug): ?string
    {
        $category = $this->registry->get($slug)?->getCategory();
        if ($category === null) {
            return null;
        }

        return match ($category) {
            PlatformCategory::Streaming, PlatformCategory::Content => self::VIBE_CREATOR_AV,
            PlatformCategory::Social, PlatformCategory::Music, PlatformCategory::Education => self::VIBE_SOCIAL_LIFESTYLE,
            PlatformCategory::Business,
            PlatformCategory::Booking,
            PlatformCategory::Reservations,
            PlatformCategory::OnlineOrdering,
            PlatformCategory::Events => self::VIBE_LOCAL_SERVICE,
            PlatformCategory::Shop => self::VIBE_RETAIL,
        };
    }

    /**
     * The single vibe with a strict plurality, or null when nothing was counted
     * or the top two tie (no confident lean).
     *
     * @param  array<string, int>  $counts
     */
    private function plurality(array $counts): ?string
    {
        arsort($counts);
        $ordered = array_values($counts);
        if (($ordered[0] ?? 0) === 0) {
            return null; // nothing categorisable connected
        }
        if (count($ordered) > 1 && $ordered[0] === $ordered[1]) {
            return null; // tie for first — abstain
        }

        return (string) array_key_first($counts);
    }
}
