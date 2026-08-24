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

// Lnk.Bio hides four of its five SEO backlinks with an inline style and the
// fifth with Bootstrap's d-none alone (measured on the recorded fixture,
// 2026-08-24) — the class carries the same meaning with no stylesheet needed.
it('drops an anchor hidden by a framework display utility class', function (string $class) {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><a href="https://calcio.dev/" class="'.$class.'">pc calcio 7 trainer</a></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe([]);
})->with([
    'bootstrap' => 'd-none',
    'bootstrap with siblings' => 'lnkbio-promo d-none',
    'tailwind' => 'hidden',
]);

// "d-none d-md-block" is Bootstrap for "hidden on phones, VISIBLE on desktop".
// Reading the d-none half alone would delete a link most visitors can see.
it('keeps an anchor a breakpoint class re-shows', function (string $class) {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><a href="https://example.org/desktop" class="'.$class.'">Desktop</a></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe(['https://example.org/desktop']);
})->with([
    'bootstrap' => 'd-none d-md-block',
    'bootstrap other util' => 'd-none d-lg-inline',
    'tailwind' => 'hidden md:block',
    // The Tailwind arm was originally an enumeration of seven display values
    // and dropped every link below. Dropping a VISIBLE link is the costly
    // direction — harvest() feeds GoogleBusinessAutoSync::seed(), so a lost
    // anchor is a business's missing social account — so any breakpoint class
    // other than the hidden token itself counts as re-showing.
    'tailwind table-cell' => 'hidden md:table-cell',
    'tailwind list-item' => 'hidden lg:list-item',
    'tailwind inline-grid' => 'hidden md:inline-grid',
    'tailwind contents' => 'hidden sm:contents',
    'tailwind important' => 'hidden md:!block',
    'custom breakpoint' => 'hidden tablet:block',
    'numeric breakpoint' => 'hidden 3xl:block',
]);

// The one thing a breakpoint class must NOT do is re-show when it also hides.
it('still drops an anchor hidden at every breakpoint', function (string $class) {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><a href="https://calcio.dev/" class="'.$class.'">Promo</a></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe([]);
})->with([
    'bootstrap' => 'd-none d-md-none',
    'tailwind' => 'hidden md:hidden',
]);
