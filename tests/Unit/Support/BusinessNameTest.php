<?php

use App\Support\BusinessName;

// Cap raised 15 → 80 (owner, 2026-08-27, issue 10): real business names pass
// through storage untrimmed; 80 is a sanity bound only. The word-boundary trim
// MECHANICS are unchanged — those tests pin an explicit max of 15 so the
// original fixtures keep exercising every edge.

it('passes real business names through untrimmed at the default cap', function () {
    expect(BusinessName::wordTrim('Oxbridge Barbershop Kensington'))->toBe('Oxbridge Barbershop Kensington');
    expect(BusinessName::wordTrim('ST. ALi Coffee Roasters'))->toBe('ST. ALi Coffee Roasters');
    expect(BusinessName::wordTrim('Star Barber Darwin'))->toBe('Star Barber Darwin');
    expect(BusinessName::wordTrim('D.O.C Pizza &amp; Mozzarella Bar - Carlton'))->toBe('D.O.C Pizza &amp; Mozzarella Bar - Carlton');
});

it('still word-trims past the 80-char sanity bound', function () {
    $name = str_repeat('Very Long Salon Name ', 6); // 125 chars squished
    $trimmed = BusinessName::wordTrim($name);

    expect(mb_strlen($trimmed))->toBeLessThanOrEqual(80)
        ->and(str_starts_with(trim($name), $trimmed))->toBeTrue();
});

it('word-trims a long multi-word name, stopping before a fragment would overflow', function () {
    expect(BusinessName::wordTrim('D.O.C Pizza &amp; Mozzarella Bar - Carlton', 15))->toBe('D.O.C Pizza');
});

it('leaves a short name unchanged', function () {
    expect(BusinessName::wordTrim("Joe's Cafe", 15))->toBe("Joe's Cafe");
});

it('leaves a name exactly at the cap unchanged', function () {
    expect(BusinessName::wordTrim('The Bagel House', 15))->toBe('The Bagel House');
});

it('hard-cuts a single word longer than the cap', function () {
    expect(BusinessName::wordTrim('Supercalifragilisticexpialidocious', 15))->toBe('Supercalifragil');
});

it('drops a trailing punctuation-only token left dangling by the cut', function () {
    expect(BusinessName::wordTrim('D.O.C Pizza &', 15))->toBe('D.O.C Pizza');
});

it('keeps an inner hyphen but drops a trailing one', function () {
    expect(BusinessName::wordTrim('Café - Bar -', 15))->toBe('Café - Bar');
});

it('word-trims a multibyte name at word boundaries', function () {
    expect(BusinessName::wordTrim('Café Ñoño Grande Bar', 15))->toBe('Café Ñoño');
});

it('respects a custom max length', function () {
    expect(BusinessName::wordTrim('One Two Three', 7))->toBe('One Two');
});

it('never returns empty for a non-empty, non-whitespace input', function () {
    expect(BusinessName::wordTrim('!!!', 15))->toBe('!!!');
});

it('squishes internal and surrounding whitespace before trimming', function () {
    expect(BusinessName::wordTrim("  Joe's   Cafe  ", 15))->toBe("Joe's Cafe");
});

it('returns an empty string for whitespace-only input', function () {
    expect(BusinessName::wordTrim('   ', 15))->toBe('');
});

it('strips trailing punctuation off a single-token hard cut (contract: never end mid-punctuation)', function () {
    expect(BusinessName::wordTrim('ABCDEFGHIJKLMN-O', 15))->toBe('ABCDEFGHIJKLMN');
});

it('keeps the hard cut for a degenerate all-punctuation token rather than returning empty', function () {
    expect(BusinessName::wordTrim(str_repeat('!', 20), 15))->toBe(str_repeat('!', 15));
});
