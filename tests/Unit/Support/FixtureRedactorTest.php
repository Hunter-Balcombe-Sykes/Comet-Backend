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
