<?php

// Ratchet baseline for CatalogClassificationSweepTest: surfaces that
// WebsiteLinkHarvester::classify() returns null for TODAY. Each is a known
// gap of the N1 class (see docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md).
// The test fails on a NEW invisible surface (regression) and on a STALE row here
// (improvement) — update this list only with the report.
return [
    // Wave 2 (2026-08-28): maker-marketplace shop profiles. classify() leaves
    // Etsy/Depop out of its host tables ON PURPOSE (a listing is worth a
    // probe — see WebsiteLinkHarvester SHOP_HOSTS notes); the catalog
    // detectors still route shop-PROFILE links, so the harvester gap is
    // recorded rather than fixed.
    'depop.shop',
    'etsy.shop',
    'redbubble.shop',
    // Payment links: opaque slugs the harvester can say nothing about — the
    // catalog detector is the whole win (no probe spent).
    'square.payment_link',
    'stripe.payment_link',
    // Cold-build round (2026-08-28): the bookshop's own audiobook storefront
    // (libro.fm/<shop>). Same shape as the .store surfaces below it — the
    // harvester's shop tables want a storefront probe, while the catalog
    // detector routes the link on its own, which is the whole win here.
    'libro_fm.store',
    'direct.book',
    'generic.store',
    'google_business.listing',
    'partna.manual_product',
    // 'skool.community' was here and is CLOSED (2026-09-03, real-URL sweep).
    // It had no detector at all, so classify() had nothing to answer with.
    // It now carries one, and skool.com is in SOCIAL_HOSTS beside Discord —
    // a Skool link on someone's site names the platform instead of falling
    // through as a generic link card.
    'squarespace.store',
    'stan.store',
    'woocommerce.store',
    // Same bucket as the .store rows above, added 2026-09-04 fixing a
    // DIFFERENT bug: WebsiteLinkHarvester's generic 'tiktok' SOCIAL_HOSTS
    // entry was wrongly claiming a /shop/store/… URL as the user's regular,
    // single-account TikTok profile (TiktokShop.php's own docblock: "NOT
    // connectable as a brand card... connects via the shop lane... never a
    // brand card"). Excluding it from that wrong claim correctly leaves
    // classify() with nothing to say — classifyFromCatalog()'s own
    // isProvider check already refuses ShopConnections::surfaces() members
    // on purpose (verified against the real LinkProjector, which resolves
    // this URL to tiktok_shop.store correctly; it is the shop-provider
    // rule, not a missing detector, that keeps it out of classify()).
    'tiktok_shop.store',
];
