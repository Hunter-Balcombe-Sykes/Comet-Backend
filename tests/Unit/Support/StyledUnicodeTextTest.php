<?php

use App\Support\StyledUnicodeText;

// thebloomroommalvern's Instagram name is a run of Mathematical Alphanumeric
// Symbols, and our site rendered it raw as the largest text on the page.

it('folds math-bold and script letters back to plain ones', function () {
    expect(StyledUnicodeText::fold('𝐓𝐡𝐞 𝐁𝐥𝐨𝐨𝐦 𝐑𝐨𝐨𝐦 𝐅𝐥𝐨𝐰𝐞𝐫𝐬 | 𝐌𝐞𝐥𝐛𝐨𝐮𝐫𝐧𝐞 𝐅𝐥𝐨𝐫𝐢𝐬𝐭 | Malvern'))
        ->toBe('The Bloom Room Flowers | Melbourne Florist | Malvern')
        ->and(StyledUnicodeText::fold('𝓢𝓬𝓻𝓲𝓹𝓽 𝓝𝓪𝓶𝓮'))->toBe('Script Name')
        ->and(StyledUnicodeText::fold('ＦＵＬＬＷＩＤＴＨ'))->toBe('FULLWIDTH');
});

it('leaves everything that is not a styled letter exactly as it was', function () {
    // The reason this folds per character instead of running NFKC over the
    // whole string: NFKC would rewrite these three, and they are plausible
    // characters in a real business name.
    expect(StyledUnicodeText::fold('Café Ole ™ ½ price № 4'))->toBe('Café Ole ™ ½ price № 4')
        ->and(StyledUnicodeText::fold('Nails 💅 by Luuce'))->toBe('Nails 💅 by Luuce')
        ->and(StyledUnicodeText::fold('Ollie Smith'))->toBe('Ollie Smith')
        ->and(StyledUnicodeText::fold(''))->toBe('')
        ->and(StyledUnicodeText::fold(null))->toBeNull();
});

it('returns an untouched string rather than an empty one when nothing is styled', function () {
    // Guards the fast path: a plain name must not pay for a normalise pass, and
    // must come back identical rather than trimmed or re-spaced.
    $plain = '  spacing  is  preserved  on  the  fast  path  ';
    expect(StyledUnicodeText::fold($plain))->toBe($plain);
});
