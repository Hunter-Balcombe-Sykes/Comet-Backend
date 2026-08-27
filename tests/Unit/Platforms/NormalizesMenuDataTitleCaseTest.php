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
