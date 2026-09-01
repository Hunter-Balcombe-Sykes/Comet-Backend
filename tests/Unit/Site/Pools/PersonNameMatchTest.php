<?php

use App\Services\Profile\NameShapeGate;
use App\Site\Pools\PersonNameMatch;

// ── The incident (2026-09-01) ───────────────────────────────────────────────
// We published OTHER PEOPLE'S Google reviews on real named individuals'
// sitepages. broken-oven — display_name "Lower East by", first_name "The
// Broken Oven Pizza Bar" — admitted seven venue reviews, including a 1-star
// about a cappuccino and two praising a barber called Shuki, because
// tokens() took the FIRST WORD of first_name ("the"), applied a bare 3-char
// floor, and handed "the" to the pool as a name to look for in review prose.
// "the" appears in seven of that venue's eleven reviews. That is the whole
// mechanism.
//
// The owner rule (2026-08-31): a review belongs on a person's page only if it
// MENTIONS THEM BY NAME. Everything else stays with the venue. So the matcher
// must be able to answer "this string is not a usable name" and suppress
// everything, rather than treating whatever sits in display_name as one.
// 37 of 84 partna accounts currently hold a descriptor, an emoji or a raw
// handle in that column, so this is the common case, not the edge.

// ── Usability: strings that are NOT a person's name ─────────────────────────

it('refuses the broken-oven name pair outright', function () {
    // Live tonight. Neither column holds a name: the display is a venue
    // fragment ending in a preposition, the "first name" is the venue.
    expect(PersonNameMatch::tokens('Lower East by', 'The Broken Oven Pizza Bar'))->toBeNull();
});

it('refuses a descriptor whose lead token is an ordinary word', function (string $display, ?string $first) {
    expect(PersonNameMatch::tokens($display, $first))->toBeNull();
})->with([
    'article lead' => ['The YOGA People Sydney', 'The'],
    'trade lead' => ['Private Chef', 'Private'],
    'city lead' => ['MELBOURNE MAKE UP ARTIST', 'MELBOURNE'],
    'city + trade' => ['Sydney Nail Artist', 'Sydney'],
    'honorific' => ['DJ Hellraiser', 'DJ'],
    'trade tail' => ['Trae the Barber', 'the barber'],
    'qualifier lead' => ['Mobile Pilates instructor', 'Mobile'],
]);

it('refuses a handle, an email local part and a name carrying digits', function (string $display) {
    expect(PersonNameMatch::tokens($display, null))->toBeNull();
})->with([
    'slug handle' => ['camilla-reynolds'],
    'generated handle' => ['user-ot9fss'],
    'plus-addressed' => ['tobiasindarwin+fableqa2'],
    'digits' => ['Channel 303'],
]);

it('refuses a vanity string but still reads the clean first_name beside it', function () {
    // "SIMON DOYLE | Barber & Educator" is a bio line, not a name — the pipe
    // and the trade word both say so. first_name is clean, so the person is
    // still matchable by their first name alone.
    $names = PersonNameMatch::tokens('SIMON DOYLE | Barber & Educator', 'SIMON');

    expect($names)->not->toBeNull()
        ->and($names['full'])->toBe([])
        ->and($names['first'])->toBe(['simon']);
});

it('reads through emoji and punctuation to the name underneath', function () {
    // ollies: display_name "ST. ALi Coffee" is the venue; first_name "Raff" is
    // the human. The venue string must not become a needle.
    $names = PersonNameMatch::tokens('ST. ALi Coffee', 'Raff');

    expect($names['full'])->toBe([])
        ->and($names['first'])->toBe(['raff']);
});

it('keeps a plain human name in both tiers', function () {
    $names = PersonNameMatch::tokens('Simon Doyle', 'Simon');

    expect($names['full'])->toBe(['simon doyle'])
        ->and($names['first'])->toBe(['simon']);
});

it('keeps a unicode surname intact', function () {
    expect(PersonNameMatch::tokens('Ben Böhmer', 'Ben')['full'])->toBe(['ben böhmer']);
});

it('keeps a single-token display name out of the full tier', function () {
    // The tier boundary, pinned: tokens() promotes to `full` on
    // `count($words) > 1`, and `>= 1` there is invisible to every other
    // assertion in this file because no other case has a one-word
    // display_name that survives the lexicon. Under that mutant a lone token
    // becomes a "full name" — it skips the 3-character floor (array_diff then
    // strikes it out of `first`, so "K" alone would be a matchable name) and
    // it claims the strong tier's authority on one ordinary word, which is the
    // exact shape that published seven strangers' reviews.
    expect(PersonNameMatch::tokens('Raff', 'Raff'))->toBe(['full' => [], 'first' => ['raff']])
        ->and(PersonNameMatch::tokens('Jo', null))->toBeNull();
});

it('still refuses a one- or two-letter lead token', function () {
    // Pre-existing floor, kept: initials match half the dictionary. The full
    // name survives — two tokens in sequence is a strong enough claim.
    $names = PersonNameMatch::tokens('K Jah', 'K');

    expect($names['first'])->toBe([])
        ->and($names['full'])->toBe(['k jah']);
});

it('returns null when nothing at all is on file', function () {
    expect(PersonNameMatch::tokens('', ''))->toBeNull()
        ->and(PersonNameMatch::tokens(null, null))->toBeNull();
});

// ── The text tier: does the review name this person? ────────────────────────

it('admits the review the owner rule is designed to admit', function () {
    // The true positive. "Emma was fantastic" on a salon listing IS a review
    // of Emma, and the fix must not take it away.
    $names = PersonNameMatch::tokens('Emma Dinon', 'Emma');

    expect(PersonNameMatch::matchesText('Emma was fantastic, best colour I have had.', $names))->toBeTrue();
});

it('does not admit broken-oven venue prose on the strength of "the"', function () {
    // The seven live strings, verbatim from content.f_review tonight. Every
    // one of them was published on a named individual's page.
    $names = PersonNameMatch::tokens('Lower East by', 'The Broken Oven Pizza Bar');

    $live = [
        'Absolutely loved my breakfast here! I ordered an extra-hot cappuccino along with bacon and eggs everything was perfect. The coffee came out piping hot just the way I like it',
        'Absolutely everything we tried was sensational!!! The presentation, the flavours, the quality of the food!',
        'This is my first ever google review because we were so impressed by this place. Delicious food (always appreciate an all day menu because I love breakfast food).',
        'Moved into the area about a month ago and discovered this little gem doing big things.',
        'Bad capuchino , no taste of coffee at all . I am Sorry to have to write this because I normally like this coffee place in the Mall But in this other location my capuchino was just warm milk.',
        'Shuki did a fantastic job today. Keep up the great work, and thanks for your service!',
        'Really loved my visit! Sayuri is incredibly talented, and the salon has such a calm and relaxing atmosphere.',
    ];

    // $names is null, so there is nothing to match with — assert the shape the
    // caller relies on AND that no string sneaks through a non-null tier.
    expect($names)->toBeNull();
    foreach ($live as $text) {
        expect(PersonNameMatch::matchesText($text, ['full' => [], 'first' => []]))->toBeFalse();
    }
});

it('requires a lone name token to appear as a proper noun, not as prose', function () {
    // Second guard behind the lexicon: a person's name in a review is a proper
    // noun and is capitalised. A one-word needle that only ever appears
    // lower-case is prose colliding with the name, not an attribution — and
    // the lexicon can never be complete, so this is what bounds the blast
    // radius of the next descriptor we fail to recognise.
    $names = PersonNameMatch::tokens('Lime Tree Bower', 'Lime');

    expect(PersonNameMatch::matchesText('The fresh lime cordial was excellent.', $names))->toBeFalse()
        ->and(PersonNameMatch::matchesText('Lime looked after us all afternoon.', $names))->toBeTrue();
});

it('accepts a shouted name and rejects a substring', function () {
    $names = PersonNameMatch::tokens('Simon Doyle', 'Simon');

    expect(PersonNameMatch::matchesText('SIMON IS THE BEST BARBER IN TOWN', $names))->toBeTrue()
        ->and(PersonNameMatch::matchesText('The Simonsen brothers run it now.', $names))->toBeFalse();
});

it('requires a full name to appear as a proper noun too', function () {
    // BLOCKER (2026-09-01, second pass). The first pass put the capitalisation
    // guard on the LONE-token tier only and then declared, in its residual
    // note, that the guard bounded the descriptors NOT_A_NAME misses. It did
    // not bound them at all above one word: the full tier matched
    // case-insensitively anywhere in the prose. "Lime Tree Bower" clears the
    // lexicon — none of its three words is in it — so every review of the
    // venue that mentioned the lime tree bower published on that person's
    // page, with the STRONGER tier's authority behind it.
    //
    // `first` here is ['lime'], and 'lime' is lower-case in the first string,
    // so only the full tier can decide it — which is what makes this
    // assertion die when the guard comes off that tier.
    $names = PersonNameMatch::tokens('Lime Tree Bower', 'Lime');

    expect($names['full'])->toBe(['lime tree bower'])
        ->and(PersonNameMatch::matchesText('We sat in the lime tree bower out the back all afternoon.', $names))->toBeFalse()
        ->and(PersonNameMatch::matchesText('Lime Tree Bower looked after us all afternoon.', $names))->toBeTrue();
});

it('matches a full name across any run of whitespace, and only as a proper noun', function () {
    // A 2-letter lead token empties `first` (the pre-existing floor), so this
    // reads the full tier alone: the sequence still spans any run of
    // whitespace or hyphens, and shouting still counts, but every word of it
    // has to be capitalised. The all-lower-case review is the chosen cost —
    // it fails closed, and withholding a review is not the harm this class
    // exists to prevent.
    $names = PersonNameMatch::tokens('Jo Malone', 'Jo');

    expect($names['first'])->toBe([])
        ->and(PersonNameMatch::matchesText("booked with Jo\n  Malone again", $names))->toBeTrue()
        ->and(PersonNameMatch::matchesText('JO MALONE IS THE BEST', $names))->toBeTrue()
        ->and(PersonNameMatch::matchesText('booked with jo malone again', $names))->toBeFalse()
        ->and(PersonNameMatch::matchesText('Jo malone did my colour.', $names))->toBeFalse();
});

// ── The staff-attribution tier ──────────────────────────────────────────────

it('matches a structured staff attribution that names the person', function () {
    $names = PersonNameMatch::tokens('Simon Doyle', 'Simon');

    expect(PersonNameMatch::matchesStaffName('Simon', $names))->toBeTrue()
        ->and(PersonNameMatch::matchesStaffName('Simon Doyle', $names))->toBeTrue();
});

it('refuses a structured staff attribution that names someone else', function () {
    // ollies, live tonight: staff_name "Ciel" on the account of Raff McGuiness.
    $names = PersonNameMatch::tokens('ST. ALi Coffee', 'Raff');

    expect(PersonNameMatch::matchesStaffName('Ciel', $names))->toBeFalse()
        ->and(PersonNameMatch::matchesStaffName('Shuki', $names))->toBeFalse()
        ->and(PersonNameMatch::matchesStaffName('Sayuri', $names))->toBeFalse();
});

// ── Anti-drift: one descriptor vocabulary, two consumers ────────────────────

it('refuses every word the write-side name gate already calls a descriptor', function () {
    // NameShapeGate (write side, F3) and this class (read side) both hold a
    // list of words that are not a person's name, and on 2026-09-01 they
    // disagreed about 29 of them — "academy", "cake", "edit", "tension",
    // "physio", "stylist". Every disagreement is a hole in exactly one
    // direction: the gate declines to write the word as a first/last name,
    // then this class reads the same word out of display_name and hunts it in
    // review prose. jay.ink.academy is the live shape — the gate rejects
    // "ACADEMY" as a surname and the matcher would attribute a venue review
    // saying "Academy" to whoever owns that page.
    //
    // The relation is one-way ON PURPOSE and this asserts only that direction:
    // the read side must refuse AT LEAST what the write side refuses. The read
    // side legitimately refuses more (connectives, honorifics, corporate
    // suffixes) because refusing is its fail-closed answer, whereas the gate
    // refusing a word costs a real name a column.
    $gate = (new ReflectionClass(NameShapeGate::class))->getConstant('DESCRIPTORS');
    $read = (new ReflectionClass(PersonNameMatch::class))->getConstant('NOT_A_NAME');

    expect(array_values(array_diff($gate, $read)))->toBe([]);
});
