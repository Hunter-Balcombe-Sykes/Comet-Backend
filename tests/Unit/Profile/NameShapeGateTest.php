<?php

use App\Services\Profile\NameShapeGate;
use Tests\TestCase;

// Unit tests here do not get Laravel booted by tests/Pest.php (only Feature is
// extended), and nameWords() reads resource_path(). Opt in, the same way
// tests/Unit/Ingest/SourceProvisionerIdentifierTest.php does.
uses(TestCase::class)->in(__FILE__);

// Every fixture below is a REAL string from a live build (2026-08-31 batch or
// the 2026-09-01 re-audit batch) — not sanitised examples.

it('knows a descriptor word from a name', function () {
    foreach (['Barber', 'barbers', 'Music', 'Studio', 'Physiotherapy', 'Decorator', 'Trainer', 'Photographer', 'Melbourne', 'Sydney', 'The', 'Edit', 'Barbershop', 'Coaching'] as $word) {
        expect(NameShapeGate::isDescriptor($word))->toBeTrue("'{$word}' should be a descriptor");
    }
    foreach (['Doyle', 'Masina', 'Skinner', 'Akhurst', 'Thorton', 'Waters'] as $word) {
        expect(NameShapeGate::isDescriptor($word))->toBeFalse("'{$word}' should not be a descriptor");
    }
});

it('detects letter-spaced display names', function () {
    // studiobide, live 2026-08-31 — rendered as the page's largest text.
    expect(NameShapeGate::isLetterSpaced('S T U D I O  B I D E'))->toBeTrue()
        ->and(NameShapeGate::isLetterSpaced('J A Y - I N K ACADEMY'))->toBeTrue()
        ->and(NameShapeGate::isLetterSpaced('Christiana Masina'))->toBeFalse();
});

it('derives a name from a name-shaped handle', function () {
    expect(NameShapeGate::nameFromHandle('cassandraskinnerpt'))->toBe('Cassandra Skinner')
        ->and(NameShapeGate::nameFromHandle('simondoylehair'))->toBe('Simon Doyle')
        ->and(NameShapeGate::nameFromHandle('sweetcakesofmine'))->toBeNull()
        ->and(NameShapeGate::nameFromHandle('fsbpt'))->toBeNull();
});

it('never writes a descriptor or an emoji as a surname', function () {
    // fayeellefineline, live: last_name was a sparkle emoji.
    $out = NameShapeGate::apply(
        ['displayName' => 'Fine Line Tattoo Artist ✨Elle ✨', 'firstName' => 'Fine', 'lastName' => '✨'],
        'fayeellefineline',
        'Fine Line Tattoo Artist ✨Elle ✨',
    );
    expect($out['firstName'])->toBeNull()->and($out['lastName'])->toBeNull();
});

it('replaces a descriptor display name with the handle-derived one when it has it', function () {
    // cassandraskinnerpt, live: "Brisbane Personal Trainer" with first "Brisbane", last "Trainer".
    $out = NameShapeGate::apply(
        ['displayName' => 'Brisbane Personal Trainer', 'firstName' => 'Brisbane', 'lastName' => 'Trainer'],
        'cassandraskinnerpt',
        'Brisbane Personal Trainer',
    );
    expect($out['displayName'])->toBe('Cassandra Skinner')
        ->and($out['firstName'])->toBe('Cassandra')
        ->and($out['lastName'])->toBe('Skinner');
});

it('leaves a good name completely alone', function () {
    $out = NameShapeGate::apply(
        ['displayName' => 'Christiana Masina', 'firstName' => 'Christiana', 'lastName' => 'Masina'],
        '_designdivine_',
        'Christiana Masina | Interior Designer',
    );
    expect($out)->toBe(['displayName' => 'Christiana Masina', 'firstName' => 'Christiana', 'lastName' => 'Masina']);
});

it('folds letter-spacing rather than shipping it', function () {
    $out = NameShapeGate::apply(
        ['displayName' => 'S T U D I O  B I D E', 'firstName' => 'S', 'lastName' => 'E'],
        'studiobide',
        'S T U D I O  B I D E',
    );
    expect($out['displayName'])->toBe('STUDIO BIDE')
        ->and($out['firstName'])->toBeNull()
        ->and($out['lastName'])->toBeNull();
});

it('strips the fabricated split off a brand name — the re-audit exhibits', function () {
    // the.shelly.editt → first "The", last "Edit"; tension.music → "Tension"/"Music".
    // The split goes; the display name stays (it is genuinely the brand).
    $shelly = NameShapeGate::apply(
        ['displayName' => 'The Shelly Edit', 'firstName' => 'The', 'lastName' => 'Edit'],
        'the.shelly.editt',
        'The Shelly Edit',
    );
    expect($shelly['firstName'])->toBeNull()->and($shelly['lastName'])->toBeNull();

    $tension = NameShapeGate::apply(
        ['displayName' => 'Tension Music', 'firstName' => 'Tension', 'lastName' => 'Music'],
        'tension.music',
        'Tension Music',
    );
    expect($tension['firstName'])->toBeNull()->and($tension['lastName'])->toBeNull();
});
