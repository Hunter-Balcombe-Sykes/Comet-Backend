<?php

use App\Services\WebsiteScan\AboutProseExtractor;

function apeExtract(string $html): ?string
{
    return (new AboutProseExtractor)->extract($html);
}

it('extracts a direct-sibling paragraph following an "About" heading', function () {
    $html = '<h2>About Us</h2><p>We have been roasting our own beans in this exact spot since 1998, and every cup still starts with the same recipe our founder wrote on a napkin.</p>';

    expect(apeExtract($html))->toBe('We have been roasting our own beans in this exact spot since 1998, and every cup still starts with the same recipe our founder wrote on a napkin.');
});

it('matches "Our Story" as a heading keyword', function () {
    $html = '<h3>Our Story</h3><p>It all began on a rainy Tuesday when three friends decided the neighbourhood needed a proper wine bar.</p>';

    expect(apeExtract($html))->not->toBeNull();
});

it('collects a paragraph nested one level inside a sibling wrapper div', function () {
    $html = '<h2>About</h2><div class="content"><p>Family owned and operated for three generations, we still hand-roll every pasta shape on the menu today.</p></div>';

    expect(apeExtract($html))->toBe('Family owned and operated for three generations, we still hand-roll every pasta shape on the menu today.');
});

it('joins up to 3 following paragraphs with a blank line between them', function () {
    $html = '<h2>About</h2>'
        .'<p>Paragraph one has enough real words in it to clear the noise filter easily.</p>'
        .'<p>Paragraph two also carries plenty of real prose content to pass the same filter.</p>'
        .'<p>Paragraph three rounds out the section with more genuine descriptive text.</p>';

    expect(apeExtract($html))->toBe(
        "Paragraph one has enough real words in it to clear the noise filter easily.\n\n"
        ."Paragraph two also carries plenty of real prose content to pass the same filter.\n\n"
        .'Paragraph three rounds out the section with more genuine descriptive text.'
    );
});

it('stops collecting once a fourth paragraph would exceed the 3-paragraph cap', function () {
    $html = '<h2>About</h2>'
        .'<p>Paragraph one has enough real words in it to clear the noise filter easily.</p>'
        .'<p>Paragraph two also carries plenty of real prose content to pass the same filter.</p>'
        .'<p>Paragraph three rounds out the section with more genuine descriptive text.</p>'
        .'<p>Paragraph four should never appear in the result because the cap is three.</p>';

    expect(apeExtract($html))->not->toContain('Paragraph four');
});

it('stops collecting at the next heading', function () {
    $html = '<h2>About</h2><p>Real prose about the business goes here for the reader to enjoy.</p><h2>Hours</h2><p>Monday to Friday, nine until five, closed on public holidays.</p>';

    $result = apeExtract($html);
    expect($result)->toContain('Real prose about the business');
    expect($result)->not->toContain('Monday to Friday');
});

it('skips short noise text (e.g. a stray nav label) picked up by the wrapper widen', function () {
    $html = '<h2>About</h2><div><span>Menu</span><p>A properly long paragraph of real prose describing the business in detail for the reader.</p></div>';

    expect(apeExtract($html))->toBe('A properly long paragraph of real prose describing the business in detail for the reader.');
});

it('collapses internal whitespace/newlines in the collected paragraph', function () {
    $html = "<h2>About</h2><p>Line one of the story\n   continues right here without a real break in the sentence at all.</p>";

    expect(apeExtract($html))->toBe('Line one of the story continues right here without a real break in the sentence at all.');
});

it('returns null when no About/Our Story heading exists on the page', function () {
    $html = '<h2>Hours</h2><p>Open every day from nine until five for your convenience.</p>';

    expect(apeExtract($html))->toBeNull();
});

it('returns null when the About heading has no usable following paragraph', function () {
    $html = '<h2>About</h2><img src="photo.jpg">';

    expect(apeExtract($html))->toBeNull();
});

it('returns null for empty html', function () {
    expect(apeExtract(''))->toBeNull();
});

it('clamps an excessively long collected prose block to 1000 chars with an ellipsis', function () {
    $long = str_repeat('word ', 400); // ~2000 chars, well past the 1000-char cap
    $html = "<h2>About</h2><p>{$long}</p>";

    $result = apeExtract($html);
    expect(mb_strlen($result))->toBeLessThanOrEqual(1001); // 1000 + the ellipsis char
    expect($result)->toEndWith('…');
});
