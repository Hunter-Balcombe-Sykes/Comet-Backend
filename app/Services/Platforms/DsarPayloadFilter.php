<?php

namespace App\Services\Platforms;

use App\Exceptions\Platforms\MissingDsarAllowlistException;

/**
 * #PRIV-2: filters a `site.platform_connections.payload` for a GDPR/DSAR
 * export. Sibling to PublicIntegrationConnectionResource — that class's
 * ALLOWLIST is the PUBLIC wire contract; this is a separate, WIDER contract
 * (the account holder is entitled to their own operational data that the
 * public sitepage hides) minus the keys that describe a third party rather
 * than the account holder. Kept as its own copy rather than aliasing the
 * public ALLOWLIST — the two contracts are allowed to drift independently.
 *
 * DSAR_ALLOWLIST is built mechanically from
 * PublicIntegrationConnectionResource::ALLOWLIST:
 *   1. copied verbatim as the reviewed floor of "safe to disclose";
 *   2. THIRD_PARTY_KEYS removed wherever they appear (reviewer/organiser/
 *      venue identity — personal data about someone who isn't the account
 *      holder and never consented to appearing in their export);
 *   3. owner-operational keys ADDED back in — fields the public wire hides
 *      for presentation reasons but the account holder is entitled to see
 *      under Article 15 (placeId, phoneIntl, hiddenEventIds, channelId, ...).
 */
final class DsarPayloadFilter
{
    /**
     * Third-party payload keys withheld from a data-subject export, in the
     * style of DataExportPayloadBuilder::PII_DISCLOSURE.
     *
     * Slice 6 extended the Google clause to name the aggregate review summary.
     * The summary moved into content.source_stats.summary_text and is withheld
     * there too (DataExportPayloadBuilder::streamContentSourceStats omits it
     * from the select), so a disclosure that named only the legacy
     * `reviewSummary` key would have understated what is held back. The
     * 2026-08-05 sentence structure and its backticked key names are otherwise
     * unchanged — this is an addition, not a rewrite.
     */
    public const WITHHELD_DISCLOSURE = 'Some data fetched from your connected platforms is withheld from this export because it is personal data about OTHER people, not about you: reviewer names, photos and review text from Google Business (`reviews`, `reviewSummary`), including Google\'s own aggregate summary of those reviews wherever else it is held (`summary_text` in `content.source_stats`), and event organiser and venue identity from Eventbrite and Humanitix (`organiser`, `venue`). Those individuals and businesses are not parties to your Partna account and did not consent to appearing in it. Your listing\'s own star average and review count are business facts about you, not about a reviewer, and are NOT withheld. Everything in this section that describes your own connection, listing or content is included in full.';

    /**
     * Removed from every platform's DSAR allowlist wherever the public
     * ALLOWLIST carries them — personal data about a third party (a
     * reviewer, an event organiser, a venue), not about the account holder.
     *
     * Nothing in this class READS this at runtime: the removal was applied by
     * hand when each DSAR_ALLOWLIST entry was authored, so this is the
     * published derivation rule rather than an internal — public for the same
     * reason WITHHELD_DISCLOSURE above is. What keeps it honest is
     * DsarAllowlistCoverageTest, which asserts no allowlist entry ever carries
     * one of these keys; that test is the enforcement, not this declaration.
     *
     * @var list<string>
     */
    public const THIRD_PARTY_KEYS = ['reviews', 'reviewSummary', 'organiser', 'venue'];

    /**
     * Per-platform allowlist of payload keys included in a DSAR export.
     * `highlights` stays listed even though Featured was removed (2026-08-06):
     * older stored payloads still carry the key until a refresh rewrites them,
     * and a DSAR export must disclose what is actually held.
     * Derived from PublicIntegrationConnectionResource::ALLOWLIST — see the
     * class docblock for the exact derivation rule. Platforms not listed
     * here fail CLOSED (see filter() below).
     *
     * @var array<string, list<string>>
     */
    private const DSAR_ALLOWLIST = [
        'instagram' => ['username', 'fullName', 'profilePicUrl', 'businessCategory', 'followersCount', 'postsCount', 'mode', 'images', 'videoUrl', 'videoPoster', 'imagesDropped'],
        'youtube' => ['handle', 'avatarUrl', 'name', 'description', 'link', 'thumbnail', 'latest', 'highlights'],
        'apple-music' => ['input', 'name', 'thumbnail', 'releaseDate', 'link', 'latest', 'highlights'],
        'apple-podcast' => ['input', 'name', 'thumbnail', 'description', 'releaseDate', 'link', 'latest', 'highlights'],
        // organiser/venue removed (third-party identity); hiddenEventIds added
        // back — the owner's own dashboard curation state, hidden from the
        // public wire but theirs to see. location is the event's own
        // geography (the account holder's own data), kept — not a third
        // party's identity like venue, so the asymmetry with venue above is
        // deliberate.
        'eventbrite' => ['url', 'next', 'upcoming', 'kind', 'id', 'name', 'location', 'startDate', 'endDate', 'description', 'startsAt', 'endsAt', 'price', 'priceMin', 'currency', 'availability', 'soldOut', 'image', 'link', 'slug', 'aliases', 'hiddenEventIds'],
        'humanitix' => ['url', 'next', 'upcoming', 'kind', 'id', 'name', 'location', 'startDate', 'endDate', 'description', 'startsAt', 'endsAt', 'price', 'priceMin', 'currency', 'availability', 'soldOut', 'image', 'link', 'slug', 'aliases', 'hiddenEventIds'],
        // venue removed (third-party identity); location kept (own geography).
        'facebook' => ['username', 'url'],
        'tiktok' => ['username', 'url'],
        'x' => ['username', 'url'],
        'linkedin' => ['username', 'url'],
        'threads' => ['username', 'url'],
        'reddit' => ['username', 'url'],
        'snapchat' => ['username', 'url'],
        'discord' => ['username', 'url'],
        'telegram' => ['username', 'url'],
        'kick' => ['username', 'url'],
        'medium' => ['username', 'url'],
        'whatsapp' => ['username', 'url'],
        'substack' => ['username', 'url'],
        'patreon' => ['username', 'url'],
        'ko-fi' => ['username', 'url'],
        'buymeacoffee' => ['username', 'url'],
        'github' => ['username', 'url'],
        'gitlab' => ['username', 'url'],
        'codepen' => ['username', 'url'],
        'dribbble' => ['username', 'url'],
        'behance' => ['username', 'url'],
        'gumroad' => ['username', 'url'],
        'fresha' => ['url', 'selection'],
        'spotify' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'soundcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'mixcloud' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'tidal' => ['url', 'name', 'thumbnail', 'embedUrl', 'link'],
        'square' => ['url'],
        'bandcamp' => ['url', 'artist', 'name', 'thumbnail', 'link', 'latest', 'highlights', 'releases'],
        'vimeo' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights'],
        // channelId added back — the re-fetch input the public wire hides,
        // but it is the owner's own re-scrape identifier, not a secret.
        'youtube-music' => ['url', 'name', 'thumbnail', 'link', 'latest', 'items', 'highlights', 'channelId'],
        'twitch' => ['url', 'login', 'name', 'image', 'description'],
        'skool' => ['url', 'name', 'image', 'description'],
        'strava' => ['url', 'name', 'location', 'image', 'description', 'members'],
        // reviews/reviewSummary removed (third-party reviewer identity —
        // #PRIV-2's namesake case); placeId/phoneIntl/priceLevel/priceRange/
        // detailsFetchedAt added back — the owner's own operational data the
        // public wire hides for presentation reasons only.
        'google-business' => ['url', 'name', 'address', 'lat', 'lng', 'rating', 'reviewCount', 'businessStatus', 'category', 'phone', 'website', 'hours', 'links', 'editorialSummary', 'amenities', 'photos', 'placeId', 'phoneIntl', 'priceLevel', 'priceRange', 'detailsFetchedAt'],
        'opentable' => ['url', 'rid', 'name', 'embedUrl'],
        'resdiary' => ['url', 'microsite', 'name', 'embedUrl'],
        'nowbookit' => ['url', 'accountId', 'venueId', 'name', 'embedUrl'],
        'booksy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'vagaro' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'timely' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'kitomba' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'phorest' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'shortcuts' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bella-booking' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'boulevard' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'glossgenius' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'mangomint' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'zenoti' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'mindbody' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ovatu' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'resy' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'quandoo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'sevenrooms' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tock' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tablecheck' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ticketek' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'oztix' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'trybooking' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'resident-advisor' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ticketmaster' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'bopple' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'square-ordering' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'hungrypanda' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'easi' => ['url', 'name', 'favicon', 'logo', 'provider'],
        // shop: payload is vestigial — brands live relationally in
        // site.shop_brands (FOUND-25), not in this connection's payload
        // column, so there is nothing here to disclose. site.shop_brands
        // itself is NOT currently exported by DataExportPayloadBuilder at
        // all; that is a pre-existing gap, out of scope for this unit.
        //
        // NOTE 2026-08-17: site.shop_brands has since been DROPPED by the shop
        // re-home; storefronts live in content.storefronts. The `[]` above is
        // still correct for this legacy pseudo-platform key (its own payload
        // remains vestigial), but the rationale's second sentence is now
        // historical. The per-brand storefront slugs below are a different
        // thing and do disclose.
        'shop' => [],

        // ── Derived brand platforms (uniformity Phase B) ────────────────────
        // Every connectable, URL-detected catalog brand that has no hand-written
        // descriptor now carries platform routes, so each needs a DSAR entry or
        // its payload renders EMPTY in the account holder's own export.
        //
        // All take the SAME shape as the already-reviewed link-only brand
        // entries above (booksy, vagaro, resy, ticketek, oztix, trybooking,
        // resident-advisor, ticketmaster, bopple, square-ordering, hungrypanda,
        // easi): the connection is written by LinkRouter as a CardPayload and
        // holds exactly the URL the account holder pasted plus the card metadata
        // resolved from it.
        //
        // Deliberately NOT `[]`. These keys are the account holder's own data —
        // their booking page, their store, the business name shown on it — and
        // Article 15 entitles them to it. `[]` is reserved for a payload that
        // genuinely holds nothing (the `shop` case above, which says so).
        // DsarPayloadFilter already fails closed, so the risk on this list is
        // under-disclosure, never leakage. None of these platforms can hold
        // THIRD_PARTY_KEYS: a card carries no reviewer, organiser or venue.
        //
        // Storefront brands (shopify, woocommerce, squarespace, bigcartel) are
        // deliberately ABSENT: they are not derivable platforms at all. A store
        // is connected by the commerce probe, and registering 'shopify' would
        // steal the family fallback that scheduled product refresh depends on.
        //
        // The brands still marked notConnectable() in the catalog get their
        // entry in the same commit that flips them — an entry for an
        // unregistered platform is itself a test failure (the stale-entry
        // assertion in DsarAllowlistCoverageTest), and rightly so.
        'acuity' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'calendly' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'chope' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'chownow' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'circle' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'deliveroo' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'doordash' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'eat_app' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'genbook' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'grubhub' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'just_eat' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'kajabi' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'luma' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'menulog' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'uber_eats' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'noterro' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'order_online' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'ordermate' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'partiful' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'schedulicity' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'setmore' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'simplybook_me' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'skipthedishes' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'slice' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'tablein' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'thefork' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'toast' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'treatwell' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'wolt' => ['url', 'name', 'favicon', 'logo', 'provider'],
        'zomato' => ['url', 'name', 'favicon', 'logo', 'provider'],
    ];

    /**
     * The DSAR allowlist for a platform, or null if none exists (fail-closed
     * caller must decide what to do — filter() below reports and returns []).
     *
     * @return list<string>|null
     */
    public static function keysFor(string $platform): ?array
    {
        return self::DSAR_ALLOWLIST[$platform] ?? null;
    }

    /**
     * Restrict a stored payload to its platform's DSAR allowlist. Mirrors
     * PublicIntegrationConnectionResource::filterPayload()'s fail-closed
     * shape: an unlisted platform never falls through to a raw pass-through
     * of unvetted stored keys.
     */
    public static function filter(string $platform, mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $allowed = self::keysFor($platform);

        if ($allowed === null) {
            report(new MissingDsarAllowlistException($platform));

            return [];
        }

        // Nested identity survives array_intersect_key (it inspects top-level
        // keys only), so allowlisted parents like `photos` need a second pass.
        return ThirdPartyPii::stripNested(array_intersect_key($payload, array_flip($allowed)));
    }
}
