<?php

use App\Ingest\Connectors\GoogleBusinessConnector;
use App\Ingest\Landing\Redactor;

// #SEC-2 — Google photo-attribution PII survived the `when_unclaimed` redaction.
//
// The manifest declared ['author', 'author_uri', 'author_photo'], which is the
// shape mapReview() emits (top-level keys, which is what Redactor walks). But
// mapPhoto() emits a DIFFERENTLY SHAPED `attribution` block —
// authors[].name / authors[].uri / maps_uri / flag_uri — that no declared path
// named. So the full credit, reviewer names included, was persisted into
// content.media_assets.attribution regardless of claim status: the same data
// subject as the already-open LEGAL-2 item, exposed on a second surface.
//
// Asserted at the redaction seam rather than through a live Places fetch: the
// defect is entirely in which PATHS are declared and whether Redactor reaches
// the shape they name, and that is exactly what these cases pin.

/** The shape mapPhoto() actually returns — see GoogleBusinessConnector::mapPhoto(). */
function gbPhotoDoc(): array
{
    return [
        'key' => 'gphoto:abc123',
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
        'width_px' => 4032,
        'height_px' => 3024,
        'attribution' => [
            'authors' => [
                ['name' => 'Jo Rivera', 'uri' => 'https://maps.google.com/contrib/1234567890'],
                ['name' => 'Sam Patel', 'uri' => null],
            ],
            'maps_uri' => 'https://maps.google.com/p/1',
            'flag_uri' => 'https://maps.google.com/flag?postId=!1e10!2sabc123',
        ],
    ];
}

it('declares the photo-attribution path, scoped to unclaimed', function () {
    $manifest = GoogleBusinessConnector::manifest();

    expect($manifest->redactionsFor(isClaimed: false))->toContain('attribution.authors');

    // Scoped, not unconditional: a claimed owner's own listing keeps the credit,
    // which is what the Places terms ask for wherever the photo is displayed.
    expect($manifest->redactionsFor(isClaimed: true))->not->toContain('attribution.authors');
});

it('strips every photo author from an UNCLAIMED build, and keeps the non-personal link-back', function () {
    $redacted = Redactor::apply(gbPhotoDoc(), GoogleBusinessConnector::manifest()->redactionsFor(isClaimed: false));

    // The defect: these names reached content.media_assets.attribution.
    expect($redacted['attribution'])->not->toHaveKey('authors');
    expect(json_encode($redacted))->not->toContain('Jo Rivera')
        ->and(json_encode($redacted))->not->toContain('Sam Patel')
        ->and(json_encode($redacted))->not->toContain('maps.google.com/contrib');

    // The whole path, NOT the 'attribution.authors.*.name' wildcard the audit
    // suggested. Redactor does support the wildcard, but it leaves
    // `authors: [[], []]` — husks that still disclose how many people
    // contributed. Nothing of the sort survives here.
    expect($redacted['attribution']['authors'] ?? null)->toBeNull();

    // maps_uri and flag_uri carry no personal data and are the link-back half of
    // the Places attribution obligation, which still attaches because an
    // unclaimed pre-account site is public by design. They must survive.
    expect($redacted['attribution']['maps_uri'])->toBe('https://maps.google.com/p/1');
    expect($redacted['attribution']['flag_uri'])->toContain('flag?postId=');

    // And nothing else on the photo is collateral damage.
    expect($redacted['key'])->toBe('gphoto:abc123');
    expect($redacted['url'])->toContain('lh3.googleusercontent.com');
    expect($redacted['width_px'])->toBe(4032);
});

it('leaves the credit intact on a CLAIMED listing', function () {
    $redacted = Redactor::apply(gbPhotoDoc(), GoogleBusinessConnector::manifest()->redactionsFor(isClaimed: true));

    // Non-vacuity guard for the case above: if this ever comes back empty the
    // redaction is unconditional and the scope assertion proves nothing.
    expect($redacted['attribution']['authors'])->toHaveCount(2);
    expect($redacted['attribution']['authors'][0]['name'])->toBe('Jo Rivera');
});

it('still strips the REVIEW shape, which uses different top-level keys', function () {
    // mapReview()'s output. The original three declared paths were correct for
    // this shape and must keep working — the photo fix is additive.
    $review = [
        'review_id' => 'rev-1',
        'rating' => 5,
        'text' => 'Lovely cut.',
        'author' => 'Jo Rivera',
        'author_uri' => 'https://maps.google.com/contrib/1234567890',
        'author_photo' => 'https://lh3.googleusercontent.com/a/avatar',
    ];

    $redacted = Redactor::apply($review, GoogleBusinessConnector::manifest()->redactionsFor(isClaimed: false));

    expect($redacted)->not->toHaveKey('author')
        ->and($redacted)->not->toHaveKey('author_uri')
        ->and($redacted)->not->toHaveKey('author_photo')
        ->and($redacted['rating'])->toBe(5)
        ->and($redacted['text'])->toBe('Lovely cut.');
});
