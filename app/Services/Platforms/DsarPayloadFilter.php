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
     */
    public const WITHHELD_DISCLOSURE = 'Some data fetched from your connected platforms is withheld from this export because it is personal data about OTHER people, not about you: reviewer names, photos and review text from Google Business (`reviews`, `reviewSummary`), and event organiser and venue identity from Eventbrite and Humanitix (`organiser`, `venue`). Those individuals and businesses are not parties to your Partna account and did not consent to appearing in it. Everything in this section that describes your own connection, listing or content is included in full.';

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
        'youtube' => ['handle', 'name', 'description', 'link', 'thumbnail', 'latest', 'highlights'],
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
        'events-custom' => ['kind', 'id', 'name', 'location', 'startDate', 'endDate', 'description', 'startsAt', 'endsAt', 'price', 'priceMin', 'currency', 'availability', 'soldOut', 'image', 'link', 'slug', 'aliases'],
        'custom' => ['kind', 'url', 'name', 'description', 'favicon', 'logo'],
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
        'booking' => ['url', 'provider'],
        'reservations' => ['url', 'provider'],
        'online-ordering' => ['url', 'name', 'favicon', 'logo', 'provider'],
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
        'shop' => [],
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
