<?php

use App\Support\BusinessName;

it('word-trims a long multi-word name, stopping before a fragment would overflow', function () {
    expect(BusinessName::wordTrim('D.O.C Pizza &amp; Mozzarella Bar - Carlton'))->toBe('D.O.C Pizza');
});

it('leaves a short name unchanged', function () {
    expect(BusinessName::wordTrim("Joe's Cafe"))->toBe("Joe's Cafe");
});

it('leaves a name exactly at the cap unchanged', function () {
    expect(BusinessName::wordTrim('The Bagel House'))->toBe('The Bagel House');
});

it('hard-cuts a single word longer than the cap', function () {
    expect(BusinessName::wordTrim('Supercalifragilisticexpialidocious'))->toBe('Supercalifragil');
});

it('drops a trailing punctuation-only token left dangling by the cut', function () {
    expect(BusinessName::wordTrim('D.O.C Pizza &'))->toBe('D.O.C Pizza');
});

it('keeps an inner hyphen but drops a trailing one', function () {
    expect(BusinessName::wordTrim('Café - Bar -'))->toBe('Café - Bar');
});

it('word-trims a multibyte name at word boundaries', function () {
    expect(BusinessName::wordTrim('Café Ñoño Grande Bar'))->toBe('Café Ñoño');
});

it('respects a custom max length', function () {
    expect(BusinessName::wordTrim('One Two Three', 7))->toBe('One Two');
});

it('never returns empty for a non-empty, non-whitespace input', function () {
    expect(BusinessName::wordTrim('!!!'))->toBe('!!!');
});

it('squishes internal and surrounding whitespace before trimming', function () {
    expect(BusinessName::wordTrim("  Joe's   Cafe  "))->toBe("Joe's Cafe");
});

it('returns an empty string for whitespace-only input', function () {
    expect(BusinessName::wordTrim('   '))->toBe('');
});
