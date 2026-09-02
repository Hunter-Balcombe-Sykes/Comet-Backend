<?php

use App\Services\Profile\NameShapeGate;
use App\Site\Pools\PersonNameMatch;
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

it('folds a single-spaced letter run without gluing the word beside it', function () {
    // jay.ink.academy, live. isLetterSpaced() has said true about this string
    // since the gate shipped and the fold has been mangling it ever since:
    // splitting on 2+ spaces alone meant a name letter-spaced with SINGLE
    // spaces throughout was one segment, so every space went and "ACADEMY"
    // was welded onto the letters — "JAY-INKACADEMY", worse than the input we
    // were rescuing. A word of 2+ characters is a word boundary; only a run of
    // single characters folds.
    expect(NameShapeGate::foldLetterSpacing('J A Y - I N K ACADEMY'))->toBe('JAY-INK ACADEMY')
        ->and(NameShapeGate::foldLetterSpacing('S T U D I O  B I D E'))->toBe('STUDIO BIDE');
});

it('strips emoji out of the display name, not only out of the name parts', function () {
    // fayeellefineline and playlunch, live. The gate nulled the emoji surname
    // and the emoji given name on the first pass and then shipped the same
    // emoji as the page's largest text, because the shape rules only ever ran
    // over first/last. display_name is a name column too.
    $faye = NameShapeGate::apply(
        ['displayName' => 'Fine Line Tattoo Artist ✨Elle ✨', 'firstName' => 'Fine', 'lastName' => '✨'],
        'fayeellefineline',
        'Fine Line Tattoo Artist ✨Elle ✨',
    );
    expect($faye['displayName'])->toBe('Fine Line Tattoo Artist Elle');

    $play = NameShapeGate::apply(
        ['displayName' => 'Play Lunch 🍓', 'firstName' => '🍓', 'lastName' => null],
        'playlunch',
        'Play Lunch 🍓',
    );
    expect($play['displayName'])->toBe('Play Lunch')
        ->and($play['firstName'])->toBeNull();
});

it('hands the review matcher a name it can read — the second consumer of this column', function () {
    // WHY this test lives in the gate's file and not the matcher's: F3 is a
    // write-side defect with a read-side symptom. PersonNameMatch fails closed
    // on anything that is not a name, so ONE trailing sparkle on an otherwise
    // clean display name — with first_name empty, which is what the gate
    // itself writes when it rejects a part — returns null tokens, and null
    // tokens suppress every review on the page. That is the "publishes no
    // reviews at all" class: not a matcher bug, a name the matcher was right
    // to refuse. Gate the column and the same account publishes.
    expect(PersonNameMatch::tokens('Lucy Nguyen ✨', ''))->toBeNull();

    $out = NameShapeGate::apply(
        ['displayName' => 'Lucy Nguyen ✨', 'firstName' => null, 'lastName' => null],
        'lucynguyenmua',
        'Lucy Nguyen ✨',
    );
    expect($out['displayName'])->toBe('Lucy Nguyen')
        ->and(PersonNameMatch::tokens($out['displayName'], $out['firstName']))
        ->toBe(['full' => ['lucy nguyen'], 'first' => ['lucy']]);
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

it("takes the person's own separator in the handle before any dictionary — jordan.dimitriadis (melbournehairspecialist, 2026-09-02)", function () {
    expect(NameShapeGate::nameFromHandle('jordan.dimitriadis'))->toBe('Jordan Dimitriadis')
        ->and(NameShapeGate::nameFromHandle('jordan_dimitriadis'))->toBe('Jordan Dimitriadis')
        ->and(NameShapeGate::nameFromHandle('studio.mj'))->toBeNull()
        ->and(NameShapeGate::nameFromHandle('jordan.au'))->toBeNull();
    $out = NameShapeGate::apply(['displayName' => 'MELBOURNE HAIR SPECIALIST', 'firstName' => null, 'lastName' => null], 'jordan.dimitriadis', 'MELBOURNE HAIR SPECIALIST');
    expect($out)->toBe(['displayName' => 'Jordan Dimitriadis', 'firstName' => 'Jordan', 'lastName' => 'Dimitriadis']);
});

it('title-cases an ALL-CAPS person name and leaves a descriptor phrase with no person handle alone', function () {
    $out = NameShapeGate::apply(['displayName' => 'JORDAN DIMITRIADIS', 'firstName' => 'JORDAN', 'lastName' => 'DIMITRIADIS'], 'someone', 'JORDAN DIMITRIADIS');
    expect($out['displayName'])->toBe('Jordan Dimitriadis')->and($out['firstName'])->toBe('Jordan')->and($out['lastName'])->toBe('Dimitriadis');
    $brand = NameShapeGate::apply(['displayName' => 'MELBOURNE HAIR SPECIALIST', 'firstName' => null, 'lastName' => null], 'mhs2020', 'MELBOURNE HAIR SPECIALIST');
    expect($brand['displayName'])->toBe('MELBOURNE HAIR SPECIALIST');
});

// Handle seed (2026-09-02): which of the two strings a build has — the IG
// username and the IG Name field — should seed the handle. Fixtures are real
// dev builds; the expectations are the owner-approved outcomes.

it('keeps the name when the username carries part of it', function () {
    expect(NameShapeGate::handleCarriesName('ryanfitzsimonshair', 'Ryan Fitzsimons'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('by.dannydixon', 'Danny Dixon'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('jordan.dimitriadis', 'Jordan Dimitriadis'))->toBeTrue()
        // Persona-looking username, but "sam" is right there in it.
        ->and(NameShapeGate::handleCarriesName('sammy.pdf', 'Sam Akhurst'))->toBeTrue()
        // Leetspeak in the username does not hide the given name.
        ->and(NameShapeGate::handleCarriesName('rubytallu1ah', 'Ruby Warren'))->toBeTrue()
        // Only the SURNAME survives in the username; one token is enough.
        ->and(NameShapeGate::handleCarriesName('emdinonhair', 'Emma Dinon'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('georgetheosteo', 'George Sotiri'))->toBeTrue()
        // "Jo" is two letters and skipped; "bentley" carries the decision.
        ->and(NameShapeGate::handleCarriesName('joannebentleymakeup', 'Jo Bentley'))->toBeTrue();
});

it('prefers the username when it carries no part of the name', function () {
    // The case that motivated the change.
    expect(NameShapeGate::handleCarriesName('themetapunter', 'Joe Osborne'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('certifiedbarberboy', 'Jesse Jensz'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('barberfaydos', 'Jaiden Acallar'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('_designdivine_', 'Christiana Masina'))->toBeFalse();
});

it('prefers the username when the name field is not a person name at all', function () {
    // These are the ~30 dev builds whose handle is today a slugged description.
    expect(NameShapeGate::handleCarriesName('hoasisbeauty', 'Heavenly Oasis Laser Skin Beauty'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('sweetcakesofmine', 'Melbourne Cake decorator'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('makeupbykatarina', 'MELBOURNE MAKE UP ARTIST'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('nailsbylaurissa', 'Sydney Nail Artist'))->toBeFalse()
        // One token is not a person's full name. `lucy` / `amber` are handles
        // the next Lucy and the next Amber cannot have.
        ->and(NameShapeGate::handleCarriesName('nailsbyluuce', 'Lucy'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('get_scissored', 'Amber'))->toBeFalse();
});

it('folds accents deterministically, never through iconv', function () {
    // Str::ascii gives 'Ben Bohmer' on macOS AND on Cloud's glibc;
    // iconv('ASCII//TRANSLIT') does not agree with itself across the two.
    expect(NameShapeGate::handleCarriesName('benbohmermusic', 'Ben Böhmer'))->toBeTrue();
});

it('fails toward the name when there is no username to prefer', function () {
    // Returning false here would seed HandleAllocator with '' -> 'professional'.
    expect(NameShapeGate::handleCarriesName('', 'Joe Osborne'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('___', 'Joe Osborne'))->toBeTrue()
        // A blank name is not a name; the username wins.
        ->and(NameShapeGate::handleCarriesName('somebrand', ''))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('somebrand', '   '))->toBeFalse();
});
