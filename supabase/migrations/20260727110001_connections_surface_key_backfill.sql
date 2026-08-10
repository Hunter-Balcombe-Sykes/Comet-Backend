-- #MIG-1. Backfill surface_key and routing_class. Split out of 20260727110000
-- because CONVENTIONS.md §5 forbids backfilling inside the DDL transaction.
--
-- Idempotent: each pass is scoped to rows that still need it (`IS NULL`), so
-- re-running is a no-op and can never overwrite a value a human or a later code
-- path corrected.
--
-- The two original UPDATEs (CASE map, then 'partna.custom_link' fallback) are
-- MERGED into one COALESCE. Outcome is identical to the original two passes.
--
-- 2026-08-10 — THE BATCHED `DO ... LOOP ... COMMIT` FORM WAS REMOVED. It was not
-- portable, and the portability bill came due the first time this repo applied
-- its migrations from zero on something other than a developer's laptop.
--
--   `COMMIT` inside a DO block is legal only when nothing has already opened a
--   transaction around it. Supabase CLI 2.101.0 applies each migration file
--   without a wrapper, so it worked. A current CLI wraps the file, so the same
--   SQL raises `ERROR: invalid transaction termination (SQLSTATE 2D000)` and the
--   apply dies here — verified on GitHub run 31375949918.
--
--   That was invisible for three reasons at once: this migration is already
--   recorded applied on dev and prod so it never re-runs there; every local
--   machine is on 2.101.0; and the one job that applies from zero on every run
--   (the DAST active lane) was manual-only and therefore only ever ran on those
--   same machines.
--
--   The batching existed to avoid holding row locks across a full-table UPDATE —
--   a real concern for the ONE run that mattered, the live dev/prod backfill,
--   which has already happened. Every future execution of this file is a
--   from-zero apply, where `site.platform_connections` is EMPTY and the loop did
--   nothing anyway. So the plain form below is identical in outcome, and is the
--   shape CONVENTIONS.md §5's own "GOOD" example prescribes: a bare UPDATE in its
--   own file, outside any BEGIN/COMMIT.
--
--   Deliberately NO explicit BEGIN/COMMIT here either. Adding one would nest
--   inside the CLI's wrapper and reintroduce a version-dependent file — the exact
--   fault being removed. Statements left bare apply correctly whether the caller
--   wraps them (current CLI) or not (psql -f, scripts/db/fresh-reset.sh, the prod
--   cutover path in CLAUDE.md).
--
-- `lock_timeout` is session-scoped, not SET LOCAL, for that same reason: SET LOCAL
-- outside a transaction is a no-op with a warning. `statement_timeout` is NOT set
-- — the original's 30s bounded a 5,000-row batch, and reusing it for a whole-table
-- UPDATE would abort a legitimate backfill midway. The `IS NULL` predicates make a
-- resumed run safe, so failing slow beats failing partway.
--
-- site.platform_connections is not in the guard's HOT_TABLES, so Check 5's
-- BEGIN + SET LOCAL requirement does not apply.
--
-- The WHEN pairs below are the SQL form of App\Catalog\LegacyPlatformMap and
-- are pinned pair-for-pair by tests/Unit/Catalog/CatalogLegacyMapTest.php
-- ("matches the backfill migration CASE pair-for-pair"). They are reproduced
-- VERBATIM from 20260727110000 — including 'pinterest', retired 2026-07-28,
-- which stays because this records what actually ran.
--
-- ROLLBACK: NONE independently. Nothing records which rows were NULL before
--           the fill, so a blanket SET surface_key = NULL would also wipe
--           values a human or later code path corrected. Reverse the FAMILY
--           instead -- 20260727110000's ROLLBACK drops the columns and takes
--           these values with them.

SET lock_timeout = '2s';

-- Pass 1 — surface_key.
UPDATE "site"."platform_connections"
   SET "surface_key" = COALESCE(CASE "platform"
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
                ELSE NULL END, 'partna.custom_link')
 WHERE "surface_key" IS NULL;

-- Pass 2 — routing_class. ELSE 'link' is total, so every row is classified.
UPDATE "site"."platform_connections"
   SET "routing_class" = CASE
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
            ELSE 'link' END
 WHERE "routing_class" IS NULL;
