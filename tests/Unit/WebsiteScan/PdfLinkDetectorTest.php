<?php

use App\Services\WebsiteScan\PdfLinkDetector;

it('finds absolute .pdf-suffixed anchor hrefs', function () {
    $html = '<a href="https://venue.example/menu.pdf">Menu</a><a href="https://venue.example/about">About</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe(['https://venue.example/menu.pdf']);
});

it('resolves a relative .pdf href against the base url', function () {
    $html = '<a href="/files/menu.pdf">Menu</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe(['https://venue.example/files/menu.pdf']);
});

it('matches .pdf case-insensitively', function () {
    $html = '<a href="/menu.PDF">Menu</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe(['https://venue.example/menu.PDF']);
});

it('ignores a query string suffix that is not actually .pdf', function () {
    $html = '<a href="/menu.pdf?download=1">Menu</a><a href="/page?file=menu.pdf">Not a pdf link</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe(['https://venue.example/menu.pdf?download=1']);
});

it('returns multiple pdf links in document order, deduped', function () {
    $html = '<a href="/menu.pdf">Menu</a><a href="/wine-list.pdf">Wine</a><a href="/menu.pdf">Menu again</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([
        'https://venue.example/menu.pdf',
        'https://venue.example/wine-list.pdf',
    ]);
});

it('returns an empty array when there are no pdf links', function () {
    expect(app(PdfLinkDetector::class)->find('<a href="/about">About</a>', 'https://venue.example'))->toBe([]);
});

it('ignores a data: or mailto: href', function () {
    $html = '<a href="mailto:hi@venue.example">Email</a><a href="data:application/pdf;base64,abc">inline</a>';
    expect(app(PdfLinkDetector::class)->find($html, 'https://venue.example'))->toBe([]);
});
