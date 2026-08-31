<?php

use App\Services\WebsiteScan\VisibleTextExtractor;

it('extracts plain text from simple markup', function () {
    $html = '<html><body><h1>Menu</h1><p>Welcome to our restaurant.</p></body></html>';
    $text = app(VisibleTextExtractor::class)->extract($html);
    expect($text)->toContain('Menu')->toContain('Welcome to our restaurant.');
});

it('separates block-level siblings onto their own lines', function () {
    $html = '<body><div>Negroni</div><div>$14</div></body>';
    $text = app(VisibleTextExtractor::class)->extract($html);
    $lines = array_values(array_filter(explode("\n", $text), fn ($l) => $l !== ''));
    expect($lines)->toBe(['Negroni', '$14']);
});

it('mirrors real Squarespace menu-item markup as recognisable name/price lines', function () {
    $html = <<<'HTML'
    <body>
      <div class="menu-section">
        <div class="menu-section-title">STARTERS</div>
        <div class="menu-item">
          <div class="menu-item-title">House made kimchi, garlic chive</div>
          <div class="menu-item-description">13</div>
        </div>
      </div>
    </body>
    HTML;
    $text = app(VisibleTextExtractor::class)->extract($html);
    $lines = array_values(array_filter(explode("\n", $text), fn ($l) => $l !== ''));
    expect($lines)->toBe(['STARTERS', 'House made kimchi, garlic chive', '13']);
});

it('drops script and style content entirely', function () {
    $html = '<body><script>var x = "menu leak";</script><style>.a{color:red}</style><p>Real content</p></body>';
    $text = app(VisibleTextExtractor::class)->extract($html);
    expect($text)->not->toContain('menu leak')->not->toContain('color:red')->toContain('Real content');
});

it('collapses whitespace within a single text node', function () {
    $html = "<body><p>Wine   List\n\n  here</p></body>";
    $text = app(VisibleTextExtractor::class)->extract($html);
    expect($text)->toContain('Wine List here');
});

it('returns empty string for markup with no visible text', function () {
    $html = '<body><script>ignored</script></body>';
    expect(app(VisibleTextExtractor::class)->extract($html))->toBe('');
});

it('truncates to the shared OCR text size cap', function () {
    $html = '<body><p>'.str_repeat('a', 70000).'</p></body>';
    $text = app(VisibleTextExtractor::class)->extract($html);
    expect(mb_strlen($text))->toBeLessThanOrEqual(60000);
});

// ── #FU-1: LIBXML_NONET on an untrusted parse ────────────────────────────────

it('still extracts visible text from a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. Pins that adding it costs no text on the exact page
    // shape that would exercise it — same DOCTYPE+entity fixture as
    // WebsiteLinkHarvesterTest's #W1-SEC-13 regression test.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><body><p>Welcome to our restaurant.</p></body></html>
    HTML;

    expect(app(VisibleTextExtractor::class)->extract($html))->toContain('Welcome to our restaurant.');
});
