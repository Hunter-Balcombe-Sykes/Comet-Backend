<?php

namespace App\Catalog;

/**
 * The P1 bridge between the legacy `platform` slug vocabulary and catalog
 * surface keys. Three consumers must stay in exact lockstep:
 *   1. IntegrationConnection's platform mutator/accessor (legacy writes),
 *   2. the 20260727110000 backfill migration's CASE (same table, SQL form),
 *   3. the generated `platform` alias column (surface → legacy, SQL form).
 * CatalogLegacyMapTest asserts 1↔2↔3 agreement and map ⊆ compiled artefact.
 *
 * Deliberate P1 policy: pseudo-bucket rows (booking/reservations/
 * online-ordering/custom/shop/events-custom) map to hidden partna.* surfaces
 * that alias back to EXACTLY their old value — zero behaviour change; the
 * provider-label upgrade to real brand surfaces is P2's reproject, where
 * gates and XOR move to routing_class.
 */
class LegacyPlatformMap
{
    /** Legacy platform slug => surface key. Covers every writable value (78). */
    private const TO_SURFACE = [
        'apple-music' => 'apple_music.artist',
        'apple-podcast' => 'apple_podcasts.show',
        'bandcamp' => 'bandcamp.artist',
        'behance' => 'behance.profile',
        'bella-booking' => 'bella_booking.book',
        'booksy' => 'booksy.book',
        'bopple' => 'bopple.order',
        'boulevard' => 'boulevard.book',
        'buymeacoffee' => 'buymeacoffee.page',
        'codepen' => 'codepen.profile',
        'discord' => 'discord.server',
        'dribbble' => 'dribbble.profile',
        'easi' => 'easi.order',
        'eventbrite' => 'eventbrite.organiser',
        'facebook' => 'facebook.profile',
        'fresha' => 'fresha.book',
        'github' => 'github.profile',
        'gitlab' => 'gitlab.profile',
        'glossgenius' => 'glossgenius.book',
        'google-business' => 'google_business.listing',
        'gumroad' => 'gumroad.store',
        'humanitix' => 'humanitix.organiser',
        'hungrypanda' => 'hungrypanda.order',
        'instagram' => 'instagram.profile',
        'kick' => 'kick.channel',
        'kitomba' => 'kitomba.book',
        'ko-fi' => 'ko_fi.page',
        'linkedin' => 'linkedin.profile',
        'mangomint' => 'mangomint.book',
        'medium' => 'medium.profile',
        'mindbody' => 'mindbody.book',
        'mixcloud' => 'mixcloud.player',
        'nowbookit' => 'nowbookit.reserve',
        'opentable' => 'opentable.reserve',
        'ovatu' => 'ovatu.book',
        'oztix' => 'oztix.tickets',
        'patreon' => 'patreon.page',
        'phorest' => 'phorest.book',
        'pinterest' => 'pinterest.profile',
        'quandoo' => 'quandoo.reserve',
        'reddit' => 'reddit.profile',
        'resdiary' => 'resdiary.reserve',
        'resident-advisor' => 'resident_advisor.tickets',
        'resy' => 'resy.reserve',
        'sevenrooms' => 'sevenrooms.reserve',
        'shortcuts' => 'shortcuts.book',
        'skool' => 'skool.community',
        'snapchat' => 'snapchat.profile',
        'soundcloud' => 'soundcloud.player',
        'spotify' => 'spotify.player',
        'square' => 'square.book',
        'square-ordering' => 'square.order',
        'strava' => 'strava.club',
        'substack' => 'substack.publication',
        'tablecheck' => 'tablecheck.reserve',
        'telegram' => 'telegram.channel',
        'threads' => 'threads.profile',
        'ticketek' => 'ticketek.tickets',
        'ticketmaster' => 'ticketmaster.tickets',
        'tidal' => 'tidal.player',
        'tiktok' => 'tiktok.profile',
        'timely' => 'timely.book',
        'tock' => 'tock.reserve',
        'trybooking' => 'trybooking.tickets',
        'twitch' => 'twitch.channel',
        'vagaro' => 'vagaro.book',
        'vimeo' => 'vimeo.account',
        'whatsapp' => 'whatsapp.chat',
        'x' => 'x.profile',
        'youtube' => 'youtube.channel',
        'youtube-music' => 'youtube_music.channel',
        'zenoti' => 'zenoti.book',
        // Pseudo buckets → hidden partna.* surfaces (alias back verbatim).
        'custom' => 'partna.custom_link',
        'events-custom' => 'partna.manual_event',
        'shop' => 'partna.storefront',
        'booking' => 'partna.booking_link',
        'reservations' => 'partna.reserve_link',
        'online-ordering' => 'partna.order_link',
    ];

    /**
     * Surface key => legacy slug, ONLY where the legacy slug is not simply the
     * surface's brand prefix. Everything else aliases via the prefix rule
     * (split_part in Postgres). Keep this list mirrored in the generated
     * `platform` column's CASE.
     */
    private const SPECIAL_TO_LEGACY = [
        'apple_music.artist' => 'apple-music',
        'apple_podcasts.show' => 'apple-podcast',
        'bella_booking.book' => 'bella-booking',
        'google_business.listing' => 'google-business',
        'ko_fi.page' => 'ko-fi',
        'resident_advisor.tickets' => 'resident-advisor',
        'square.order' => 'square-ordering',
        'youtube_music.channel' => 'youtube-music',
        'partna.custom_link' => 'custom',
        'partna.manual_event' => 'events-custom',
        'partna.storefront' => 'shop',
        'partna.booking_link' => 'booking',
        'partna.reserve_link' => 'reservations',
        'partna.order_link' => 'online-ordering',
    ];

    /** Surface key => routing class (P1 truth; must agree with the artefact). */
    private const ROUTING_CLASS = [
        // social
        'x.profile' => 'social', 'tiktok.profile' => 'social', 'facebook.profile' => 'social',
        'snapchat.profile' => 'social', 'linkedin.profile' => 'social', 'threads.profile' => 'social',
        'reddit.profile' => 'social', 'discord.server' => 'social', 'telegram.channel' => 'social',
        'kick.channel' => 'social', 'medium.profile' => 'social', 'instagram.profile' => 'social',
        'whatsapp.chat' => 'social', 'substack.publication' => 'social', 'patreon.page' => 'social',
        'ko_fi.page' => 'social', 'buymeacoffee.page' => 'social', 'github.profile' => 'social',
        'gitlab.profile' => 'social', 'codepen.profile' => 'social', 'dribbble.profile' => 'social',
        'behance.profile' => 'social',
        // content (no gates, no XOR, no CTA class)
        'pinterest.profile' => 'content', 'strava.club' => 'content', 'skool.community' => 'content',
        'youtube.channel' => 'content', 'vimeo.account' => 'content', 'twitch.channel' => 'content',
        'spotify.player' => 'content', 'soundcloud.player' => 'content', 'mixcloud.player' => 'content',
        'tidal.player' => 'content', 'apple_music.artist' => 'content', 'apple_podcasts.show' => 'content',
        'bandcamp.artist' => 'content', 'youtube_music.channel' => 'content',
        'google_business.listing' => 'content',
        // events
        'eventbrite.organiser' => 'events', 'humanitix.organiser' => 'events',
        'ticketek.tickets' => 'events', 'ticketmaster.tickets' => 'events', 'oztix.tickets' => 'events',
        'trybooking.tickets' => 'events', 'resident_advisor.tickets' => 'events',
        'partna.manual_event' => 'events',
        // booking
        'fresha.book' => 'booking', 'square.book' => 'booking', 'booksy.book' => 'booking',
        'vagaro.book' => 'booking', 'timely.book' => 'booking', 'kitomba.book' => 'booking',
        'phorest.book' => 'booking', 'shortcuts.book' => 'booking', 'bella_booking.book' => 'booking',
        'boulevard.book' => 'booking', 'glossgenius.book' => 'booking', 'mangomint.book' => 'booking',
        'zenoti.book' => 'booking', 'mindbody.book' => 'booking', 'ovatu.book' => 'booking',
        'partna.booking_link' => 'booking',
        // reservations
        'opentable.reserve' => 'reservations', 'resdiary.reserve' => 'reservations',
        'nowbookit.reserve' => 'reservations', 'resy.reserve' => 'reservations',
        'quandoo.reserve' => 'reservations', 'sevenrooms.reserve' => 'reservations',
        'tock.reserve' => 'reservations', 'tablecheck.reserve' => 'reservations',
        'partna.reserve_link' => 'reservations',
        // ordering
        'square.order' => 'ordering', 'bopple.order' => 'ordering', 'hungrypanda.order' => 'ordering',
        'easi.order' => 'ordering', 'partna.order_link' => 'ordering',
        // shop
        'partna.storefront' => 'shop', 'gumroad.store' => 'shop',
        // link
        'partna.custom_link' => 'link',
    ];

    public static function surfaceFor(string $legacyPlatform): ?string
    {
        return self::TO_SURFACE[$legacyPlatform] ?? null;
    }

    public static function isKnownSurface(string $surfaceKey): bool
    {
        return isset(self::ROUTING_CLASS[$surfaceKey]);
    }

    public static function legacyFor(string $surfaceKey): string
    {
        return self::SPECIAL_TO_LEGACY[$surfaceKey]
            ?? explode('.', $surfaceKey, 2)[0];
    }

    public static function routingClassFor(string $surfaceKey): ?string
    {
        return self::ROUTING_CLASS[$surfaceKey] ?? null;
    }

    /** @return array<string, string> */
    public static function toSurfaceMap(): array
    {
        return self::TO_SURFACE;
    }

    /** @return array<string, string> */
    public static function specialToLegacyMap(): array
    {
        return self::SPECIAL_TO_LEGACY;
    }

    /** @return array<string, string> */
    public static function routingClassMap(): array
    {
        return self::ROUTING_CLASS;
    }
}
