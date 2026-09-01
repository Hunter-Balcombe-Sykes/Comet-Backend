<?php

namespace App\Services\Platforms\ScrapeCreators;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Log;

// Item 11g (2026-09-01): cross-platform discovery from one identity — the
// vendor walks the account's bio links, link-in-bio pages and same-handle
// corroboration and answers with its verified profile set; this client turns
// that into a registry-filtered {platform => url} map for the detection layer
// (bio-mention chains, GB-enrich socials — wired by a later pass, not here).
// Discovery is ADDITIVE enrichment with no incumbent to fall back to, so the
// lane's lossy contract is total: no key, budget denied, transport, husk,
// shape drift, or nothing-new-found all read null and the caller simply
// proceeds without discoveries.
//
// Budget contract (Item 8 adapter notes): claim BEFORE the call, release on
// transport-null, keep the slot spent on billed husks — NotFound bills as
// success:true, so the gate is payload shape, never HTTP status. This
// endpoint costs 10 credits live (most vendor routes cost 1), which is why
// cache_max_age rides every call — a cached answer is FREE, and an identity's
// platform graph moves on the scale of weeks, not hours. Lane is dormant
// until partna.limits.scrapecreators.sources.find_social_profiles lands (an
// absent cap reads 0 and never claims).
class FindSocialProfilesClient
{
    public const SOURCE = 'find_social_profiles';

    /** G2 economics: a ≤7-day-old cached answer costs 0 of the 10 credits. */
    private const CACHE_MAX_AGE = '7d';

    /**
     * The only source platforms the endpoint accepts, in OUR registry
     * vocabulary ('x', not the vendor's "twitter" — the vendor takes x as an
     * alias). Anything else refuses before any spend.
     */
    private const SOURCE_PLATFORMS = ['instagram', 'tiktok', 'youtube', 'x', 'facebook'];

    /**
     * Loose union of the five platforms' handle grammars (YouTube channel ids
     * included). Exact validation is the vendor's; this only refuses inputs
     * that are obviously not a handle — URLs, whitespace, empties — because a
     * malformed request still bills a credit upstream (client-doc quirk:
     * 400s bill too).
     */
    private const HANDLE_PATTERN = '~^[A-Za-z0-9._-]{1,80}$~';

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly PlatformRegistry $registry,
        private readonly FindSocialProfilesNormalizer $normalizer,
    ) {}

    /**
     * The identity's OTHER platforms, verified by the vendor's corroboration
     * rules and filtered to platforms the registry knows. One URL per
     * platform (highest confidence), source platform excluded — shape:
     * FindSocialProfilesNormalizer.
     *
     * @return non-empty-array<string, string>|null platform key => profile URL
     */
    public function discover(string $platform, string $handle, ?string $userId = null): ?array
    {
        $handle = ltrim(trim($handle), '@');
        if (! in_array($platform, self::SOURCE_PLATFORMS, true) || preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            return null;
        }
        if (! $this->client->enabled() || ! $this->budget->tryClaim(self::SOURCE)) {
            return null;
        }

        $body = $this->client->get('/v1/find-social-profiles', [
            'platform' => $platform,
            'handle' => $handle,
            'cache_max_age' => self::CACHE_MAX_AGE,
        ], $userId);
        if ($body === null) {
            $this->budget->release(self::SOURCE);

            return null;
        }

        $map = $this->normalizer->normalize($body, $this->registry->keys());
        if ($map === null) {
            // Billed either way — husk, drift, and "no new platforms found"
            // all keep the slot spent. Handles here are prospect identities
            // (the Instagram lane's sensitivity class, not Twitch's public
            // channel names), so the log carries a hash, never the handle.
            Log::info('scrapecreators.find_social_profiles.unusable_shape', [
                'platform' => $platform,
                'handle_hash' => hash('sha256', mb_strtolower($handle)),
                'user_id' => $userId,
            ]);

            return null;
        }

        return $map;
    }
}
