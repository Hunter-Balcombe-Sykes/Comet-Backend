<?php

use App\Services\Platforms\NormalizesMenuData;

// T8 (2026-08-27 unclaimed-signup quality plan, issue 8): titleCase() was a
// bare ucwords(strtolower()) — no delimiter handling, no acronym/unit
// preservation, no trailing-period strip. Every fixture string below was
// SERVING on st-ali-coffee-roasters' live wire when this test was written.

$harness = new class
{
    use NormalizesMenuData;

    public function tc(?string $s): ?string
    {
        return $this->titleCase($s);
    }
};

dataset('realStAliStrings', [
    // raw scrape input                          => what should serve
    'slash delimiter' => ['Cold Brew/Oat Latte Can', 'Cold Brew/Oat Latte Can'],
    'slash from lowercased source' => ['cold brew/oat latte can', 'Cold Brew/Oat Latte Can'],
    'paren + unit + trailing period' => ['Cold Brew Bags. (Italo concentrate 1.2l)', 'Cold Brew Bags (Italo Concentrate 1.2L)'],
    'bare trailing period' => ['Cronut.', 'Cronut'],
    'trailing period 2' => ['Danish.', 'Danish'],
    'trailing period 3' => ['Bourdain roll.', 'Bourdain Roll'],
    'paren word + trailing period' => ['Cookie (anzac).', 'Cookie (Anzac)'],
]);

it('normalises the real St Ali strings correctly', function (string $in, string $want) use ($harness) {
    expect($harness->tc($in))->toBe($want);
})->with('realStAliStrings');

it('preserves short all-caps tokens (states, acronyms) in mixed-case input', function () use ($harness) {
    expect($harness->tc("'23 Deep Woods Chardonnay WA"))->toBe("'23 Deep Woods Chardonnay WA");
    expect($harness->tc("'23 Mulline pinot noir VIC"))->toBe("'23 Mulline Pinot Noir VIC");
});

it('still title-cases fully lower and fully upper input (no token to preserve)', function () use ($harness) {
    expect($harness->tc('lamb ragu'))->toBe('Lamb Ragu');
    expect($harness->tc('LAMB RAGU'))->toBe('Lamb Ragu');
    expect($harness->tc('flat white'))->toBe('Flat White');
});

it('handles hyphens and null', function () use ($harness) {
    expect($harness->tc('anzac-day special'))->toBe('Anzac-Day Special');
    expect($harness->tc(null))->toBeNull();
});

// 2026-08-28: T8's ALL-CAPS preservation was gated on the WHOLE STRING being
// mixed-case (`preg_match('/[a-z]/', $s)`), so it disarmed on exactly the
// sources that need it — an all-caps scraped wine list — and the docblock's own
// marquee example served "…Chardonnay Wa". Separately, ucwords(strtolower())
// flattened every interior capital, because the `\b[A-Z]{2,3}\b` restore list
// can only ever see a STANDALONE caps token. Both are token-level properties,
// so the gate moved to the token.

it('preserves an interior capital the source typed deliberately', function () use ($harness) {
    expect($harness->tc('McDonalds'))->toBe('McDonalds');
    expect($harness->tc('iPhone Charger'))->toBe('iPhone Charger');
    expect($harness->tc("MacGyver's iPad Case"))->toBe("MacGyver's iPad Case");
});

it('preserves an all-caps mark in an ALL-CAPS source, not just a mixed-case one', function () use ($harness) {
    // The docblock's own example, in the shape a scraped wine list arrives in.
    expect($harness->tc("'23 DEEP WOODS CHARDONNAY WA"))->toBe("'23 Deep Woods Chardonnay WA");
    expect($harness->tc('BANANA BREAD GF'))->toBe('Banana Bread GF');
    expect($harness->tc('PARMA (VIC)'))->toBe('Parma (VIC)');
});

it('does not mistake a short ordinary word for an acronym', function () use ($harness) {
    // Why the preserved set is an ALLOWLIST and not a length rule: `[A-Z]{2,3}`
    // matches THE, HOT and RED just as happily as WA.
    expect($harness->tc('THE BIG ONE'))->toBe('The Big One');
    expect($harness->tc('HOT CHIPS'))->toBe('Hot Chips');
    expect($harness->tc('RED WINE SPECIAL'))->toBe('Red Wine Special');
});

it('preserves an all-caps mark but never promotes a lowercase one', function () use ($harness) {
    // Preserve, never promote. "gf"/"v" are ordinary words far more often than
    // they are marks, and a scrape gives no way to tell them apart — so a
    // lowercase source gets ordinary title case, nothing cleverer.
    // 2026-09-02: titleCase() now honours CONNECTORS mid-name, matching
    // CasesScannedNames::scanTitleCase() (see CasesScannedNamesTest.php's
    // 'SURF AND TURF' -> 'Surf and Turf'), so "and" here lowercases too.
    expect($harness->tc('SALT AND PEPPER SQUID GF'))->toBe('Salt and Pepper Squid GF');
    expect($harness->tc('banana bread gf'))->toBe('Banana Bread Gf');
});

it('preserves two marks packed into one whitespace token', function () use ($harness) {
    // Live on dev when this was written. The gate is per LETTER RUN, not per
    // whitespace token — "(GF)(V)" is one token whose letters are "GFV", which
    // is no mark at all, so a token-level gate served "(Gf)(V)".
    expect($harness->tc('Veggie Tofu Coconut Curry (GF)(V)'))->toBe('Veggie Tofu Coconut Curry (GF)(V)');
    expect($harness->tc('VEGGIE TOFU COCONUT CURRY (GF)(V)'))->toBe('Veggie Tofu Coconut Curry (GF)(V)');
});

it('lowercases an accented capital instead of stranding it mid-word', function () use ($harness) {
    // strtolower() is byte-wise: "CAFÉ LATTE" served "CafÉ Latte".
    expect($harness->tc('CAFÉ LATTE'))->toBe('Café Latte');
    expect($harness->tc('JAMÓN CROQUETAS'))->toBe('Jamón Croquetas');
});

it('keeps a short caps run that the rest of a mixed-case name vouches for', function () use ($harness) {
    // T8's original signal, kept: in a string that also carries lowercase, an
    // all-caps run is the source's own contrast. Both live on dev.
    expect($harness->tc('Cold Brew CAN (Double Shot Latte)'))->toBe('Cold Brew CAN (Double Shot Latte)');
    expect($harness->tc('OG Kimbap'))->toBe('OG Kimbap');
    // ...and an ALL-CAPS source has no contrast to offer, so the same run is
    // ordinary text there. This is the asymmetry that made the bug invisible.
    expect($harness->tc('COLD BREW CAN'))->toBe('Cold Brew Can');
});
