<?php

use App\Ingest\Connectors\FreshaConnector;
use App\Services\Platforms\NormalizesMenuData;

/**
 * T11 (2026-08-27 unclaimed-signup quality plan): Fresha service names arrive
 * in whatever casing the salon typed — the same service appears as "Refresh"
 * in one scrape list and "REFRESH" in another, and SHOUTING names serve on
 * the public services page. The sitepage's uppercase STYLING stays a
 * design-kit concern (CSS), not storage.
 *
 * 2026-08-28: the connector no longer carries its own casing rule. T11 shipped
 * a bare ucwords(mb_strtolower()) here; T8 (7d19320ef) fixed exactly that
 * pattern one file over the same day and the fix was never backported, so
 * "Colour (Full Head)." kept its trailing period all the way to the public
 * page while the only coverage used punctuation-free inputs and passed.
 *
 * T11's gate ALSO changes with this: it was per WHOLE STRING (mixed case =
 * the merchant's deliberate choice, passes through untouched), and is now per
 * TOKEN, matching the menu drivers. That is not incidental — the period fix
 * is unreachable under a whole-string gate, because "Colour (Full Head)." is
 * mixed-case and would pass through with its period intact. The cost is that
 * a lowercase word inside an otherwise-cased name now capitalises; see the
 * "recases a lowercase word" test below.
 */
function freshaMappedName(string $rawName): ?string
{
    $item = [
        'name' => $rawName,
        'caption' => '45min',
        'description' => null,
        'price' => ['formatted' => 'A$90'],
        'primaryAction' => ['id' => '{"catalogId":"s:123"}'],
    ];

    $mapped = (new ReflectionMethod(FreshaConnector::class, 'mapServiceItem'))
        ->invoke(new FreshaConnector, $item, 'SERVICES', 'c1');

    return $mapped['name'] ?? null;
}

it('title-cases an all-caps service name', function () {
    expect(freshaMappedName('REFRESH'))->toBe('Refresh');
    expect(freshaMappedName('HAIRCUT & BEARD TRIM'))->toBe('Haircut & Beard Trim');
});

it('title-cases an all-lower service name', function () {
    expect(freshaMappedName('beard trim'))->toBe('Beard Trim');
});

it('leaves an already-correct mixed-case name alone', function () {
    expect(freshaMappedName('Kids Cut (Under 12)'))->toBe('Kids Cut (Under 12)');
});

/**
 * The defect this commit exists for. ucwords() has no notion of a terminal
 * period, so a salon that types its list with full stops shipped them to the
 * public services page. Each of these is a separate assertion rather than one
 * chain: a chained expect() aborts at the first failure, which would hide the
 * rest behind whichever one broke first.
 */
it('strips a trailing period a salon typed', function () {
    expect(freshaMappedName('Colour (Full Head).'))->toBe('Colour (Full Head)');
});

it('strips a trailing period from an all-caps name it also recases', function () {
    expect(freshaMappedName('BALAYAGE (FULL HEAD).'))->toBe('Balayage (Full Head)');
});

it('capitalises after a slash, not just after whitespace', function () {
    expect(freshaMappedName('blow dry/style.'))->toBe('Blow Dry/Style');
});

/**
 * NOT a period-stripper run amok: only a TERMINAL period goes. An interior one
 * is part of the name and must survive, or "Olaplex No.4" becomes "Olaplex No4"
 * on the wire.
 */
it('keeps an interior period', function () {
    expect(freshaMappedName('Olaplex No.4 Treatment'))->toBe('Olaplex No.4 Treatment');
});

/**
 * ScrapedNameCasing::ALL_CAPS_MARKS survives the lowercase-then-capitalise
 * pass. ucwords() had no allowlist at all and served "Mens Cut Wa".
 */
it('preserves an allowlisted all-caps mark', function () {
    expect(freshaMappedName('MENS CUT WA'))->toBe('Mens Cut WA');
});

/**
 * The BEHAVIOUR CHANGE, asserted rather than discovered later: under T11's
 * whole-string gate this name was mixed-case and passed through with
 * "enhancement" lowercase. The token gate capitalises it. Owner-approved
 * 2026-08-28 as the unavoidable cost of the period fix.
 */
it('recases a lowercase word inside an otherwise-cased name', function () {
    expect(freshaMappedName('Skin Fade & Beard + Color enhancement'))
        ->toBe('Skin Fade & Beard + Color Enhancement');
});

/**
 * The transform is NOT structurally idempotent — '(' is not a word boundary in
 * the regex sense, the boundary set is applied by hand, and rtrim('.') plus the
 * unit rule are both re-appliable. Nothing re-normalises a Fresha name today
 * (FreshaServiceProjector passes the connector's name straight to
 * 'headline'), but a second application must not corrupt one if a future path
 * adds one. Asserted, not assumed.
 */
it('is idempotent across every case above', function () {
    $names = [
        'REFRESH', 'HAIRCUT & BEARD TRIM', 'beard trim', 'Kids Cut (Under 12)',
        'Colour (Full Head).', 'BALAYAGE (FULL HEAD).', 'blow dry/style.',
        'Olaplex No.4 Treatment', 'MENS CUT WA', 'TONER REFRESH 100ML',
        'Skin Fade & Beard + Color enhancement',
    ];

    foreach ($names as $name) {
        $once = freshaMappedName($name);
        expect(freshaMappedName((string) $once))->toBe($once, "not idempotent: {$name}");
    }
});

/**
 * The point of the refactor, pinned behaviourally rather than by grep: the
 * connector and the menu drivers now produce the SAME string for the same
 * input. Re-fork the connector's casing and this goes red, whatever the new
 * copy happens to do.
 */
it('agrees exactly with the menu drivers on every case', function () {
    $names = [
        'REFRESH', 'HAIRCUT & BEARD TRIM', 'beard trim', 'Kids Cut (Under 12)',
        'Colour (Full Head).', 'BALAYAGE (FULL HEAD).', 'blow dry/style.',
        'Olaplex No.4 Treatment', 'MENS CUT WA', 'TONER REFRESH 100ML',
        'Skin Fade & Beard + Color enhancement',
    ];

    $driver = new class
    {
        use NormalizesMenuData;

        public function via(string $s): ?string
        {
            return $this->titleCase($s);
        }
    };

    foreach ($names as $name) {
        expect(freshaMappedName($name))->toBe($driver->via($name), "diverged on: {$name}");
    }
});
