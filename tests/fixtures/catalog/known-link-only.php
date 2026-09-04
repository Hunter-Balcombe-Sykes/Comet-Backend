<?php

// Ratchet baseline for CatalogClassificationSweepTest: surfaces that
// WebsiteLinkHarvester::classify() answers 'link' for — recognised by the
// compiled catalog, never promoted into a connectable category, and costing no
// commerce probe. Same contract as LINK_ONLY_HOSTS.
//
// This list is a RECORD OF DECISIONS, not a backlog. A row here means "the
// catalog detects this brand and we deliberately render it as a link card".
// Giving a brand a row in WebsiteLinkHarvester's host constants means DELETING
// its row here — the test fails in that direction too.
//
// classifyFromCatalog() already promotes routing_class booking / reservations /
// ordering when the surface is connectable, so a CONNECTABLE new brand in one
// of those classes never lands here. A DETECT-ONLY one in those classes is not
// promoted and lands in WebsiteLinkHarvester's host tables instead — see the
// four carried there (venue.ink, youcanbook.me, obeeapp.com, abacus.co). Everything below is a class the catalog cannot
// name for us; why, per group, and in full:
// docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md §A.2.
return [
    // routing_class 'booking' but is_connectable=false — the catalog itself
    // refuses to connect these, so promoting them would hand seedBooking() a
    // write it must never make. Both are path-identified brands on shared
    // registrable domains (office365.com, wixapps.net).
    'microsoft_bookings.book',
    'wix_bookings.book',

    // routing_class 'content' (18, now 14) — classify() has no 'content'
    // category, LinkRouter has neither a gateAllows() arm nor a seeder for
    // one. Building that lane is product work, not a classifier change.
    //
    // 2026-09-04: feature_fm.release, hypeddit.release and linkfire.release
    // dropped out of this list — MediaPageReader::classifyItem() gained real
    // item-URL grammar arms for these brands this round (ffm.to/hypeddit.com
    // lnk.to+lnkfi.re item pages), and classify() consults that grammar
    // before falling through to the catalog's routing_class, so their probe
    // URL now answers 'content-item' instead of a generic link. audiomack,
    // beatport, dailymotion and rumble also gained MediaPageReader arms the
    // same round but KEEP their row here: their probe URLs below
    // (probe-urls.php) are ARTIST/CHANNEL pages, not item pages, and the new
    // grammar only recognises single items — so classify() still correctly
    // answers 'link' for these four's specific probe.
    'apple_music.artist',
    'apple_podcasts.show',
    'audiomack.artist',
    'bandcamp.artist',
    'bandcamp.store',
    'beatport.artist',
    'circle.community',
    'dailymotion.channel',
    'heyzine.publication',
    'kajabi.courses',
    'mixcloud.player',
    'orchard.release',
    'rumble.channel',
    'strava.club',
    'tidal.player',

    // routing_class 'events' (5) — the catalog cannot tell a single event from
    // an organiser page: no *.event surface exists, only organiser/ticketing
    // ones. LinkRouter::seedEvent() branches on exactly that distinction, and
    // it lives in each scraper's pure normalizer rather than in a surface.
    //
    // 2026-09-04: WebsiteLinkHarvester::classify() gained real event-vs-
    // organiser path arms for ten of the original fifteen brands here
    // (admitone, etix, eventim, megatix, moshtix, see_tickets, ticketweb,
    // plus bandsintown/dice/songkick, whose existing catalog path capture
    // was reclassified event-organiser and paired with a brand-new event
    // arm) — removed below. admitone/eventfinda/skiddle/tixr ALSO gained
    // classify() arms this round but keep their row: this sweep's own
    // generic per-surface probe URL for those four doesn't happen to hit
    // the specific event-shaped path, so it still (correctly) answers
    // 'link' for that probe (comment corrected 2026-09-04, critic-caught —
    // eventfinda was previously and wrongly grouped with laylo as
    // "untouched"). laylo never gained an events arm here — its row was
    // instead removed from the 'content' group above, because a SEPARATE
    // change this same round (MediaPageReader::classifyItem() item-URL
    // grammar) reclassifies its probe URL as 'content-item' before
    // classify() ever reaches this brand's (nonexistent) events arm.
    'admitone.tickets',
    'eventfinda.tickets',
    'skiddle.tickets',
    'tixr.tickets',

    // routing_class 'shop' (2) — Gumroad's storefront ROOT is a link card by
    // ruling (task #17, 2026-08-18); a deeper path keeps the product probe.
    'gumroad.store',
    // Amazon storefronts (Item 10b, 2026-09-01): a scanned /shop/ link is a
    // link card — the store CONNECTS only through the dedicated shop-lane
    // endpoint (AmazonShopConnectJob; amazon.com bot-blocks every probe), so
    // classify() must not promote it into a lane that would spend one.
    'amazon-shop.store',

    // routing_class 'social' (12) — a catalog social surface is not thereby an
    // account the owner controls. Four are third-party review/marketplace
    // listings the user does not own (yelp, trustpilot, tripadvisor,
    // productreview); connecting one would claim a page on their behalf.
    // Pinterest is additionally protected by LINK_ONLY_HOSTS — a board must
    // never spend a probe.
    //
    // 2026-09-04: the other twelve (four payment handles — paypal.me, venmo,
    // cash_app, buymeacoffee — plus bluesky/cameo/codepen/gitlab/kick/ko_fi/
    // tumblr/vsco) moved OUT of this list and into WebsiteLinkHarvester's
    // SOCIAL_HOSTS/SOCIAL_PLATFORM by name — a deliberate policy change, not
    // drift, driven by real catalog host detectors this hand-maintained pair
    // had never learned. Removing a row here means "add it to those two
    // constants", never delete it silently — this list and those constants
    // are the two sides of one ledger.
    //
    // …and six of those twelve came straight back the same night (F8). cameo,
    // cash_app, paypal, tumblr, venmo and vsco are all `->notConnectable()`
    // surfaces, so SOCIAL_HOSTS — which exists to name brands that can become
    // a CONNECTION — was the wrong side of the ledger for them. They classify
    // as 'link' via classifyFromCatalog() again, which is where they were
    // before and where the yelp.listing rows below have always sat. Only the
    // six connectable ones (bluesky, buymeacoffee, codepen, gitlab, kick,
    // ko_fi) stayed out.
    'bark.company',
    'cameo.profile',
    'cash_app.profile',
    'fiverr.profile',
    'flickr.photos',
    'houzz.pro',
    'medium.profile',
    'paypal.me',
    'pinterest.profile',
    'productreview.listing',
    'substack.publication',
    'tripadvisor.listing',
    'trustpilot.listing',
    'tumblr.profile',
    'upwork.profile',
    'venmo.profile',
    'vsco.profile',
    'yelp.listing',
];
