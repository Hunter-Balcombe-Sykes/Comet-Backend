<?php

use App\Services\WebsiteScan\PdfLinkDetector;

it('finds absolute .pdf-suffixed anchor hrefs with their link text', function () {
    $html = '<a href="https://venue.example/menu.pdf">Menu</a><a href="https://venue.example/about">About</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.pdf', 'text' => 'Menu'],
    ]);
});

it('resolves a relative .pdf href against the base url', function () {
    $html = '<a href="/files/menu.pdf">Our Menu</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/files/menu.pdf', 'text' => 'Our Menu'],
    ]);
});

it('matches .pdf case-insensitively', function () {
    $html = '<a href="/menu.PDF">Menu</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.PDF', 'text' => 'Menu'],
    ]);
});

it('ignores a query string suffix that is not actually .pdf', function () {
    $html = '<a href="/menu.pdf?download=1">Menu</a><a href="/page?file=menu.pdf">Not a pdf link</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.pdf?download=1', 'text' => 'Menu'],
    ]);
});

it('returns multiple pdf links in document order, deduped, keeping first-seen text', function () {
    $html = '<a href="/menu.pdf">Menu</a><a href="/wine-list.pdf">Wine</a><a href="/menu.pdf">Menu again</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.pdf', 'text' => 'Menu'],
        ['url' => 'https://venue.example/wine-list.pdf', 'text' => 'Wine'],
    ]);
});

it('returns an empty array when there are no pdf links', function () {
    expect(app(PdfLinkDetector::class)->find('<a href="/about">About</a>', 'https://venue.example'))->toBe([]);
});

it('ignores a data: or mailto: href', function () {
    $html = '<a href="mailto:hi@venue.example">Email</a><a href="data:application/pdf;base64,abc">inline</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([]);
});

it('resolves a protocol-relative .pdf href against the base scheme', function () {
    $html = '<a href="//cdn.example.com/menu.pdf">Menu</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://cdn.example.com/menu.pdf', 'text' => 'Menu'],
    ]);
});

it('excludes a mailto: anchor even when it would otherwise resolve to a .pdf-suffixed path', function () {
    $html = '<a href="mailto:menu@venue.example">Email us</a><a href="/menu.pdf">Menu</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.pdf', 'text' => 'Menu'],
    ]);
});

it('collapses whitespace in nested link text', function () {
    $html = "<a href=\"/menu.pdf\"><span>Wine</span>\n  <span>List</span></a>";
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.pdf', 'text' => 'Wine List'],
    ]);
});

// ── #FU-1: LIBXML_NONET on an untrusted parse ────────────────────────────────

it('still finds a pdf link on a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. Pins that adding it costs no links on the exact page
    // shape that would exercise it — same DOCTYPE+entity fixture as
    // WebsiteLinkHarvesterTest's #W1-SEC-13 regression test.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><body><a href="/menu.pdf">Menu</a></body></html>
    HTML;

    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        ['url' => 'https://venue.example/menu.pdf', 'text' => 'Menu'],
    ]);
});
