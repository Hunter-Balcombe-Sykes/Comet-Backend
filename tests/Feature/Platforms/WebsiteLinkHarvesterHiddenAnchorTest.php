<?php

use App\Services\Platforms\WebsiteLinkHarvester;

// Verbatim from https://clk.bio/TheMetaPunter (2026-08-24): Lnk.Bio ships five
// display:none backlinks to its own portfolio on every page it serves. They are
// SEO, not content — a visitor cannot click them, so the owner never published
// them, and all five had already leaked into catalog.unmatched_domains.
it('drops anchors the page hides from its visitors', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks('<html><body>
        <a href="https://kick.com/themetapunter">Kick</a>
        <a href="https://cruciverba.io/" class="d-none" style="display:none">Soluzioni cruciverba</a>
        <a href="https://petrolprice.sg/" style="display: none">Petrol Price Singapore</a>
        <a href="https://mediakit.bio/" style="DISPLAY:NONE">Mediakit</a>
        <a href="https://menoo.me/" hidden>Menoo</a>
        <a href="https://calcio.dev/" style="visibility:hidden">pc calcio 7 trainer</a>
    </body></html>', 'https://clk.bio/TheMetaPunter');

    expect($links)->toBe(['https://kick.com/themetapunter']);
});

it('keeps a visible anchor that merely carries an unrelated inline style', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><a href="https://example.org/real" style="color:red;display:block">Real</a></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe(['https://example.org/real']);
});

// A collapsed mobile nav hides its CONTAINER, not each link. Matching ancestors
// would need cascade the DOM parser never computes, and would silently eat real
// navigation — so the rule deliberately reads the anchor's own markup only.
it('keeps a visible anchor nested inside a hidden container', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><div style="display:none"><a href="https://example.org/nav">Nav</a></div></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe(['https://example.org/nav']);
});
