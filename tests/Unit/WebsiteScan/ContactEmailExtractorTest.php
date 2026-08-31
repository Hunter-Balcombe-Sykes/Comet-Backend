<?php

use App\Services\Http\MetadataParser;
use App\Services\WebsiteScan\ContactEmailExtractor;

function ceeExtract(string $html, string $baseUrl = 'https://example.com'): ?string
{
    return (new ContactEmailExtractor(new MetadataParser))->extract($html, $baseUrl);
}

it('extracts a plain mailto: link', function () {
    $html = '<a href="mailto:hello@example.com">Email us</a>';

    expect(ceeExtract($html))->toBe('hello@example.com');
});

it('strips a ?subject= query from a mailto: link', function () {
    $html = '<a href="mailto:hello@example.com?subject=Booking%20enquiry">Email us</a>';

    expect(ceeExtract($html))->toBe('hello@example.com');
});

it('lowercases the extracted address', function () {
    $html = '<a href="mailto:Hello@Example.COM">Email us</a>';

    expect(ceeExtract($html))->toBe('hello@example.com');
});

it('skips a noreply@ mailto: and falls through to JSON-LD', function () {
    $html = '<a href="mailto:noreply@example.com">Unsubscribe</a>'
        .'<script type="application/ld+json">{"@type":"LocalBusiness","email":"owner@example.com"}</script>';

    expect(ceeExtract($html))->toBe('owner@example.com');
});

it('skips a malformed mailto: address and falls through to JSON-LD', function () {
    $html = '<a href="mailto:not-an-email">Contact</a>'
        .'<script type="application/ld+json">{"@type":"Organization","email":"real@example.com"}</script>';

    expect(ceeExtract($html))->toBe('real@example.com');
});

it('extracts a JSON-LD LocalBusiness email when no mailto: link exists', function () {
    $html = '<script type="application/ld+json">{"@type":"LocalBusiness","email":"contact@example.com"}</script>';

    expect(ceeExtract($html))->toBe('contact@example.com');
});

it('extracts a JSON-LD contactPoint.email (single object shape)', function () {
    $html = '<script type="application/ld+json">{"@type":"Organization","contactPoint":{"@type":"ContactPoint","email":"support@example.com"}}</script>';

    expect(ceeExtract($html))->toBe('support@example.com');
});

it('extracts a JSON-LD contactPoint.email (list shape, first email wins)', function () {
    $html = '<script type="application/ld+json">{"@type":"Organization","contactPoint":[{"contactType":"sales"},{"email":"sales@example.com"}]}</script>';

    expect(ceeExtract($html))->toBe('sales@example.com');
});

it('prefers a mailto: link over JSON-LD when both are present', function () {
    $html = '<a href="mailto:mailto-wins@example.com">Email</a>'
        .'<script type="application/ld+json">{"@type":"LocalBusiness","email":"jsonld-loses@example.com"}</script>';

    expect(ceeExtract($html))->toBe('mailto-wins@example.com');
});

it('returns null when neither mailto: nor JSON-LD carries an email', function () {
    $html = '<p>No contact info here.</p>';

    expect(ceeExtract($html))->toBeNull();
});

it('returns null for empty html', function () {
    expect(ceeExtract(''))->toBeNull();
});

// ── #FU-1: LIBXML_NONET on an untrusted parse ────────────────────────────────

it('still extracts a mailto: link from a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. Pins that adding it costs no email extraction on
    // the exact page shape that would exercise it — same DOCTYPE+entity
    // fixture as WebsiteLinkHarvesterTest's #W1-SEC-13 regression test.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><body><a href="mailto:hello@example.com">Email us</a></body></html>
    HTML;

    expect(ceeExtract($html))->toBe('hello@example.com');
});
