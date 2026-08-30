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
// ordering when the surface is connectable, so a new brand in one of those
// classes never lands here. Everything below is a class the catalog cannot
// name for us; why, per group, and in full:
// docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md §A.2.
return [
    // routing_class 'booking' but is_connectable=false — the catalog itself
    // refuses to connect these, so promoting them would hand seedBooking() a
    // write it must never make. Both are path-identified brands on shared
    // registrable domains (office365.com, wixapps.net).
    'microsoft_bookings.book',
    'wix_bookings.book',

    // routing_class 'content' (18) — classify() has no 'content' category,
    // LinkRouter has neither a gateAllows() arm nor a seeder for one. Building
    // that lane is product work, not a classifier change.
    'apple_music.artist',
    'apple_podcasts.show',
    'audiomack.artist',
    'bandcamp.artist',
    'bandcamp.store',
    'beatport.artist',
    'circle.community',
    'dailymotion.channel',
    'feature_fm.release',
    'heyzine.publication',
    'hypeddit.release',
    'kajabi.courses',
    'linkfire.release',
    'mixcloud.player',
    'orchard.release',
    'rumble.channel',
    'strava.club',
    'tidal.player',

    // routing_class 'events' (15) — the catalog cannot tell a single event from
    // an organiser page: no *.event surface exists, only organiser/ticketing
    // ones. LinkRouter::seedEvent() branches on exactly that distinction, and
    // it lives in each scraper's pure normalizer rather than in a surface.
    'admitone.tickets',
    'bandsintown.artist',
    'dice.events',
    'etix.tickets',
    'eventfinda.tickets',
    'eventim.tickets',
    'laylo.drop',
    'megatix.tickets',
    'moshtix.tickets',
    'see_tickets.tickets',
    'skiddle.tickets',
    'songkick.artist',
    'tickethype.tickets',
    'ticketweb.tickets',
    'tixr.tickets',

    // routing_class 'shop' (1) — Gumroad's storefront ROOT is a link card by
    // ruling (task #17, 2026-08-18); a deeper path keeps the product probe.
    'gumroad.store',

    // routing_class 'social' (24) — a catalog social surface is not thereby an
    // account the owner controls. Four are payment handles (paypal.me, venmo,
    // cash_app, buymeacoffee) and four are third-party review listings the user
    // does not own (yelp, trustpilot, tripadvisor, productreview); connecting
    // either would claim a page on their behalf. Pinterest is additionally
    // protected by LINK_ONLY_HOSTS — a board must never spend a probe.
    'bark.company',
    'bluesky.profile',
    'buymeacoffee.page',
    'cameo.profile',
    'cash_app.profile',
    'codepen.profile',
    'fiverr.profile',
    'flickr.photos',
    'gitlab.profile',
    'houzz.pro',
    'kick.channel',
    'ko_fi.page',
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
