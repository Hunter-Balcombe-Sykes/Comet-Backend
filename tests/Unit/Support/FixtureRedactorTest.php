<?php

// tests/Unit/Support/FixtureRedactorTest.php

use App\Support\Fixtures\FixtureRedactor;

it('strips reviewer PII from a places JSON body via GoogleBusinessPayload', function () {
    $body = json_encode([
        'name' => 'Beef\'s Barbers',
        'reviews' => [['author' => 'Jane Reviewer', 'text' => 'great']],
        'photos' => [['ref' => 'p1', 'authors' => [['displayName' => 'Bob']]]],
    ]);

    $out = json_decode(FixtureRedactor::apply('places', $body, 'json'), true);

    expect($out)->not->toHaveKey('reviews')
        ->and($out['photos'][0])->not->toHaveKey('authors')
        ->and($out['name'])->toBe('Beef\'s Barbers');
});

it('redacts email addresses and phone numbers in any body', function () {
    $html = '<a href="mailto:jane@example.com">jane@example.com</a> call +61 412 345 678 or (03) 9123 4567';

    $out = FixtureRedactor::apply('websites', $html, 'html');

    $this->assertStringNotContainsString('jane@example.com', $out, 'raw email survived redaction');
    $this->assertStringNotContainsString('412 345 678', $out, 'raw mobile survived redaction');
    $this->assertStringNotContainsString('9123 4567', $out, 'raw landline survived redaction');
    expect($out)->toContain('[redacted-email]');
    expect($out)->toContain('[redacted-phone]');
});

it('leaves binary media bodies untouched', function () {
    $bytes = random_bytes(64);
    expect(FixtureRedactor::apply('media', $bytes, 'jpg'))->toBe($bytes);
});

// Regression: a 2-digit-minimum area code missed "+61 3 9123 4567" — an AU
// number written with a country code commonly drops the leading zero,
// leaving a single-digit state code. Widened to 1-4 digits; each of these
// four shapes must come out fully redacted.
it('redacts AU phone formats including a single-digit area code after a country code', function (string $number) {
    $html = "<p>Call {$number} for a quote</p>";

    $out = FixtureRedactor::apply('websites', $html, 'html');

    $this->assertStringNotContainsString($number, $out, "raw phone number survived redaction: {$number}");
    expect($out)->toContain('[redacted-phone]');
})->with([
    '+61 3 9123 4567',
    '+61 412 345 678',
    '(03) 9123 4567',
    '03 9123 4567',
]);

// Standing regression for the JSON-corruption bug: widening the phone
// pattern makes it match more, which makes it MORE likely — not less — to
// eat an unquoted numeric field if redaction were ever applied to raw JSON
// text instead of string leaves. High-precision coordinates are exactly the
// shape that broke a naive text-based redaction.
it('keeps a high-precision places JSON body parseable and redacted after a widened phone match', function () {
    $body = json_encode([
        'name' => 'Beef\'s Barbers',
        'latitude' => -37.81234567890123,
        'longitude' => 144.96345678901234,
        'internationalPhoneNumber' => '+61 3 9123 4567',
        'reviews' => [['author' => 'Jane Reviewer', 'text' => 'great']],
    ]);

    $out = FixtureRedactor::apply('places', $body, 'json');
    $decoded = json_decode($out, true);

    $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'redacted places JSON failed to parse: '.json_last_error_msg());
    expect($decoded['latitude'])->toBeFloat();
    expect($decoded['longitude'])->toBeFloat();
    expect($decoded['internationalPhoneNumber'])->toBe('[redacted-phone]');
    $this->assertStringNotContainsString('9123 4567', $out, 'raw phone survived JSON redaction');
});
