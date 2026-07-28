-- platform_connections joins the catalog (plan §1): surface_key becomes the
-- identity column, `platform` becomes a GENERATED legacy alias so every
-- existing reader keeps working while no writer can ever create dual truth
-- (writes to a generated column error loudly). The mapping here is the SQL
-- form of App\Catalog\LegacyPlatformMap — CatalogLegacyMapTest pins the two
-- in lockstep. Pseudo buckets map to hidden partna.* surfaces that alias
-- back verbatim: zero behaviour change until P2's reproject.
-- FK to catalog.surfaces is deliberately deferred to P2 (after catalog:sync
-- is proven in the deploy path) so an empty catalog can never block writes.
-- Table is dev-scale (hundreds of rows): plain index builds, no CONCURRENTLY.
--
-- guard:no-unsafe-migrations:disable-file
-- Justification (2026-07-28): applied to dev on 2026-07-27 against a cold,
-- hundreds-of-rows table — none of the lock-safety patterns (CONCURRENTLY,
-- four-step NOT NULL, NOT VALID) buy anything here, and splitting an
-- ALREADY-APPLIED file into the guard's per-statement shape would desync
-- supabase_migrations history on dev. From-zero applies run this against an
-- empty table, where every statement is instant. Same precedent as the
-- pre-account-sites marker (now archived with the 2026-07-26 baseline).

ALTER TABLE "site"."platform_connections"
    ADD COLUMN "surface_key" text,
    ADD COLUMN "routing_class" text,
    ADD COLUMN "is_primary" boolean NOT NULL DEFAULT false,
    ADD COLUMN "created_by_detector" text,
    ADD COLUMN "created_by_catalog_digest" text;

UPDATE "site"."platform_connections" SET "surface_key" = CASE "platform"
    WHEN 'apple-music' THEN 'apple_music.artist'
    WHEN 'apple-podcast' THEN 'apple_podcasts.show'
    WHEN 'bandcamp' THEN 'bandcamp.artist'
    WHEN 'behance' THEN 'behance.profile'
    WHEN 'bella-booking' THEN 'bella_booking.book'
    WHEN 'booksy' THEN 'booksy.book'
    WHEN 'bopple' THEN 'bopple.order'
    WHEN 'boulevard' THEN 'boulevard.book'
    WHEN 'buymeacoffee' THEN 'buymeacoffee.page'
    WHEN 'codepen' THEN 'codepen.profile'
    WHEN 'discord' THEN 'discord.server'
    WHEN 'dribbble' THEN 'dribbble.profile'
    WHEN 'easi' THEN 'easi.order'
    WHEN 'eventbrite' THEN 'eventbrite.organiser'
    WHEN 'facebook' THEN 'facebook.profile'
    WHEN 'fresha' THEN 'fresha.book'
    WHEN 'github' THEN 'github.profile'
    WHEN 'gitlab' THEN 'gitlab.profile'
    WHEN 'glossgenius' THEN 'glossgenius.book'
    WHEN 'google-business' THEN 'google_business.listing'
    WHEN 'gumroad' THEN 'gumroad.store'
    WHEN 'humanitix' THEN 'humanitix.organiser'
    WHEN 'hungrypanda' THEN 'hungrypanda.order'
    WHEN 'instagram' THEN 'instagram.profile'
    WHEN 'kick' THEN 'kick.channel'
    WHEN 'kitomba' THEN 'kitomba.book'
    WHEN 'ko-fi' THEN 'ko_fi.page'
    WHEN 'linkedin' THEN 'linkedin.profile'
    WHEN 'mangomint' THEN 'mangomint.book'
    WHEN 'medium' THEN 'medium.profile'
    WHEN 'mindbody' THEN 'mindbody.book'
    WHEN 'mixcloud' THEN 'mixcloud.player'
    WHEN 'nowbookit' THEN 'nowbookit.reserve'
    WHEN 'opentable' THEN 'opentable.reserve'
    WHEN 'ovatu' THEN 'ovatu.book'
    WHEN 'oztix' THEN 'oztix.tickets'
    WHEN 'patreon' THEN 'patreon.page'
    WHEN 'phorest' THEN 'phorest.book'
    WHEN 'pinterest' THEN 'pinterest.profile'
    WHEN 'quandoo' THEN 'quandoo.reserve'
    WHEN 'reddit' THEN 'reddit.profile'
    WHEN 'resdiary' THEN 'resdiary.reserve'
    WHEN 'resident-advisor' THEN 'resident_advisor.tickets'
    WHEN 'resy' THEN 'resy.reserve'
    WHEN 'sevenrooms' THEN 'sevenrooms.reserve'
    WHEN 'shortcuts' THEN 'shortcuts.book'
    WHEN 'skool' THEN 'skool.community'
    WHEN 'snapchat' THEN 'snapchat.profile'
    WHEN 'soundcloud' THEN 'soundcloud.player'
    WHEN 'spotify' THEN 'spotify.player'
    WHEN 'square' THEN 'square.book'
    WHEN 'square-ordering' THEN 'square.order'
    WHEN 'strava' THEN 'strava.club'
    WHEN 'substack' THEN 'substack.publication'
    WHEN 'tablecheck' THEN 'tablecheck.reserve'
    WHEN 'telegram' THEN 'telegram.channel'
    WHEN 'threads' THEN 'threads.profile'
    WHEN 'ticketek' THEN 'ticketek.tickets'
    WHEN 'ticketmaster' THEN 'ticketmaster.tickets'
    WHEN 'tidal' THEN 'tidal.player'
    WHEN 'tiktok' THEN 'tiktok.profile'
    WHEN 'timely' THEN 'timely.book'
    WHEN 'tock' THEN 'tock.reserve'
    WHEN 'trybooking' THEN 'trybooking.tickets'
    WHEN 'twitch' THEN 'twitch.channel'
    WHEN 'vagaro' THEN 'vagaro.book'
    WHEN 'vimeo' THEN 'vimeo.account'
    WHEN 'whatsapp' THEN 'whatsapp.chat'
    WHEN 'x' THEN 'x.profile'
    WHEN 'youtube' THEN 'youtube.channel'
    WHEN 'youtube-music' THEN 'youtube_music.channel'
    WHEN 'zenoti' THEN 'zenoti.book'
    WHEN 'custom' THEN 'partna.custom_link'
    WHEN 'events-custom' THEN 'partna.manual_event'
    WHEN 'shop' THEN 'partna.storefront'
    WHEN 'booking' THEN 'partna.booking_link'
    WHEN 'reservations' THEN 'partna.reserve_link'
    WHEN 'online-ordering' THEN 'partna.order_link'
    ELSE NULL END;

-- Anything unmapped (the write-guard should have made this impossible) lands
-- as a custom link rather than blocking the NOT NULL step.
UPDATE "site"."platform_connections"
    SET "surface_key" = 'partna.custom_link'
    WHERE "surface_key" IS NULL;

UPDATE "site"."platform_connections" SET "routing_class" = CASE
    WHEN "surface_key" IN (
        'x.profile','tiktok.profile','facebook.profile','snapchat.profile','linkedin.profile',
        'threads.profile','reddit.profile','discord.server','telegram.channel','kick.channel',
        'medium.profile','instagram.profile','whatsapp.chat','substack.publication','patreon.page',
        'ko_fi.page','buymeacoffee.page','github.profile','gitlab.profile','codepen.profile',
        'dribbble.profile','behance.profile'
    ) THEN 'social'
    WHEN "surface_key" IN (
        'pinterest.profile','strava.club','skool.community','youtube.channel','vimeo.account',
        'twitch.channel','spotify.player','soundcloud.player','mixcloud.player','tidal.player',
        'apple_music.artist','apple_podcasts.show','bandcamp.artist','youtube_music.channel',
        'google_business.listing'
    ) THEN 'content'
    WHEN "surface_key" IN (
        'eventbrite.organiser','humanitix.organiser','ticketek.tickets','ticketmaster.tickets',
        'oztix.tickets','trybooking.tickets','resident_advisor.tickets','partna.manual_event'
    ) THEN 'events'
    WHEN "surface_key" IN (
        'fresha.book','square.book','booksy.book','vagaro.book','timely.book','kitomba.book',
        'phorest.book','shortcuts.book','bella_booking.book','boulevard.book','glossgenius.book',
        'mangomint.book','zenoti.book','mindbody.book','ovatu.book','partna.booking_link'
    ) THEN 'booking'
    WHEN "surface_key" IN (
        'opentable.reserve','resdiary.reserve','nowbookit.reserve','resy.reserve','quandoo.reserve',
        'sevenrooms.reserve','tock.reserve','tablecheck.reserve','partna.reserve_link'
    ) THEN 'reservations'
    WHEN "surface_key" IN (
        'square.order','bopple.order','hungrypanda.order','easi.order','partna.order_link'
    ) THEN 'ordering'
    WHEN "surface_key" IN ('partna.storefront','gumroad.store') THEN 'shop'
    ELSE 'link' END;

ALTER TABLE "site"."platform_connections" ALTER COLUMN "surface_key" SET NOT NULL;
ALTER TABLE "site"."platform_connections" ALTER COLUMN "routing_class" SET NOT NULL;
ALTER TABLE "site"."platform_connections"
    ADD CONSTRAINT "platform_connections_routing_class_check"
    CHECK ("routing_class" IN ('social', 'content', 'events', 'shop', 'booking', 'reservations', 'ordering', 'link', 'ignore'));

-- Index swap: identity moves from platform to surface_key.
DROP INDEX "site"."idx_platform_connections_unique_active";
DROP INDEX "site"."idx_platform_connections_canonical";
DROP INDEX "site"."idx_platform_connections_user_platform_sort";
CREATE UNIQUE INDEX "idx_platform_connections_unique_active"
    ON "site"."platform_connections" ("user_id", "surface_key", "resource_id")
    WHERE ("deleted_at" IS NULL);
CREATE UNIQUE INDEX "idx_platform_connections_canonical"
    ON "site"."platform_connections" ("user_id", "surface_key", "canonical_key")
    WHERE (("canonical_key" IS NOT NULL) AND ("deleted_at" IS NULL));
CREATE INDEX "idx_platform_connections_user_surface_sort"
    ON "site"."platform_connections" ("user_id", "surface_key", "sort_order")
    WHERE ("deleted_at" IS NULL);
-- One primary CTA per routing class per user (plan §1 SetPrimary).
CREATE UNIQUE INDEX "idx_platform_connections_primary_per_class"
    ON "site"."platform_connections" ("user_id", "routing_class")
    WHERE ("is_primary" AND "deleted_at" IS NULL);

-- The legacy alias: platform is now DERIVED. Only 14 surfaces alias to a
-- non-prefix slug; everything else is the brand prefix.
ALTER TABLE "site"."platform_connections" DROP COLUMN "platform";
ALTER TABLE "site"."platform_connections" ADD COLUMN "platform" text
    GENERATED ALWAYS AS (CASE "surface_key"
        WHEN 'apple_music.artist' THEN 'apple-music'
        WHEN 'apple_podcasts.show' THEN 'apple-podcast'
        WHEN 'bella_booking.book' THEN 'bella-booking'
        WHEN 'google_business.listing' THEN 'google-business'
        WHEN 'ko_fi.page' THEN 'ko-fi'
        WHEN 'resident_advisor.tickets' THEN 'resident-advisor'
        WHEN 'square.order' THEN 'square-ordering'
        WHEN 'youtube_music.channel' THEN 'youtube-music'
        WHEN 'partna.custom_link' THEN 'custom'
        WHEN 'partna.manual_event' THEN 'events-custom'
        WHEN 'partna.storefront' THEN 'shop'
        WHEN 'partna.booking_link' THEN 'booking'
        WHEN 'partna.reserve_link' THEN 'reservations'
        WHEN 'partna.order_link' THEN 'online-ordering'
        ELSE split_part("surface_key", '.', 1)
    END) STORED NOT NULL;
