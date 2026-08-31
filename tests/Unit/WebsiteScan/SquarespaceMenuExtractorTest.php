<?php

use App\Services\WebsiteScan\SquarespaceMenuExtractor;

it('extracts items from real Squarespace menu-block markup', function () {
    $html = <<<'HTML'
    <body>
      <div class="menu-wrapper menu-style-simple">
        <div class="menu-section">
          <div class="menu-section-header"><div class="menu-section-title">STARTERS</div></div>
          <div class="menu-items">
            <div class="menu-item">
              <div class="menu-item-title">House made kimchi, garlic chive</div>
              <div class="menu-item-description">13</div>
            </div>
            <div class="menu-item">
              <div class="menu-item-title">Seared tuna &amp; nori cracker</div>
              <div class="menu-item-description">10</div>
            </div>
          </div>
        </div>
        <div class="menu-section">
          <div class="menu-section-header"><div class="menu-section-title">MAINS</div></div>
          <div class="menu-items">
            <div class="menu-item">
              <div class="menu-item-title">Gippsland striploin, sancho pepper</div>
              <div class="menu-item-description">68</div>
            </div>
          </div>
        </div>
      </div>
    </body>
    HTML;

    $items = app(SquarespaceMenuExtractor::class)->extract($html);

    expect($items)->toHaveCount(3)
        ->and($items[0])->toBe([
            'name' => 'House made kimchi, garlic chive',
            'description' => '13',
            'price' => 13.0,
            'category' => 'STARTERS',
            'dietary' => null,
        ])
        ->and($items[2]['category'])->toBe('MAINS')
        ->and($items[2]['price'])->toBe(68.0);
});

it('returns empty when there is no menu-item markup on the page', function () {
    expect(app(SquarespaceMenuExtractor::class)->extract('<body><p>Not a menu</p></body>'))->toBe([]);
});

it('skips a menu-item with no title', function () {
    $html = '<body><div class="menu-item"><div class="menu-item-description">10</div></div></body>';
    expect(app(SquarespaceMenuExtractor::class)->extract($html))->toBe([]);
});

it('handles an item with no description or category gracefully', function () {
    $html = '<body><div class="menu-item"><div class="menu-item-title">Mystery dish</div></div></body>';
    expect(app(SquarespaceMenuExtractor::class)->extract($html))->toBe([[
        'name' => 'Mystery dish',
        'description' => null,
        'price' => null,
        'category' => null,
        'dietary' => null,
    ]]);
});

// ── #FU-1: LIBXML_NONET on an untrusted parse ────────────────────────────────

it('still extracts a menu item from a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. Pins that adding it costs no items on the exact
    // page shape that would exercise it — same DOCTYPE+entity fixture as
    // WebsiteLinkHarvesterTest's #W1-SEC-13 regression test.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><body><div class="menu-item"><div class="menu-item-title">Mystery dish</div></div></body></html>
    HTML;

    expect(app(SquarespaceMenuExtractor::class)->extract($html))->toBe([[
        'name' => 'Mystery dish',
        'description' => null,
        'price' => null,
        'category' => null,
        'dietary' => null,
    ]]);
});
