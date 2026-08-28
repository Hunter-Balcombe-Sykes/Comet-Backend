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
    'skool.community',
    'squarespace.store',
    'stan.store',
    'woocommerce.store',
];
