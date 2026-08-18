<?php

// Ratchet baseline for CatalogClassificationSweepTest: surfaces that
// WebsiteLinkHarvester::classify() returns null for TODAY. Each is a known
// gap of the N1 class (see docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md).
// The test fails on a NEW invisible surface (regression) and on a STALE row here
// (improvement) — update this list only with the report.
return [
    'direct.book',
    'generic.store',
    'google_business.listing',
    'partna.manual_product',
    'skool.community',
    'squarespace.store',
    'stan.store',
    'woocommerce.store',
];
