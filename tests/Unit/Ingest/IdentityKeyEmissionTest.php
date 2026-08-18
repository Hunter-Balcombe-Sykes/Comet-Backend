<?php

use App\Content\Identity\IdentityKey;
use App\Content\Identity\KeyClass;
use App\Ingest\Projection\IdentityKeyDeriver;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Emission, not resolution: ResolverTest already pins what the keys MEAN.
// These assert which keys a projection produces — the gap that left
// content.item_merges at 0 with 2 of 17 classes ever written (log F1).

/** @return list<string> every value emitted for one class */
function emitted(array $projection, KeyClass $class, string $coord = 'x:acct-0:1'): array
{
    $keys = (new IdentityKeyDeriver)->derive($coord, $projection);

    return array_values(array_map(
        fn (IdentityKey $key) => $key->value,
        array_filter($keys, fn (IdentityKey $key) => $key->class === $class),
    ));
}

/** @return list<KeyClass> */
function emittedClasses(array $projection, string $coord = 'x:acct-0:1'): array
{
    return array_values(array_unique(array_map(
        fn (IdentityKey $key) => $key->class,
        (new IdentityKeyDeriver)->derive($coord, $projection),
    ), SORT_REGULAR));
}

it('always emits the coord as a platform object', function () {
    expect(emitted(['kind' => 'video'], KeyClass::PlatformObject, 'youtube:acct-abc:vid1'))
        ->toBe(['youtube:acct-abc:vid1']);
});

it('emits a canonical url only when the projection carries a link', function () {
    $with = ['kind' => 'video', 'facets' => ['f_link' => ['url' => 'https://YouTube.com/watch?v=A']]];

    expect(emitted($with, KeyClass::CanonicalUrl))->toBe(['https://youtube.com/watch?v=a'])
        ->and(emitted(['kind' => 'video'], KeyClass::CanonicalUrl))->toBe([]);
});

it('emits an isrc key for a track that carries one', function () {
    $track = [
        'kind' => 'track',
        'headline' => 'Some Song',
        'facets' => ['f_catalog' => ['isrc' => 'us-rc1-23-45678']],
    ];

    expect(emitted($track, KeyClass::Isrc))->toBe(['USRC12345678']);
});

it('emits no isrc key for a track without one', function () {
    expect(emitted(['kind' => 'track', 'headline' => 'Some Song'], KeyClass::Isrc))->toBe([]);
});

it('never emits a key class for a kind it does not declare', function () {
    // Same ISRC field, wrong kind: Isrc::kinds() is ['track'] only.
    $release = [
        'kind' => 'release',
        'headline' => 'Some Album',
        'facets' => ['f_catalog' => ['isrc' => 'USRC12345678', 'gtin' => '00012345600012']],
    ];

    expect(emitted($release, KeyClass::Isrc))->toBe([])
        ->and(emitted($release, KeyClass::Gtin14))->toBe([])
        ->and(emitted(['kind' => 'video', 'headline' => 'Deep Tissue Massage'], KeyClass::OfferingName))->toBe([]);
});

it('emits a gtin key for a product that carries one', function () {
    $product = [
        'kind' => 'product',
        'headline' => 'A Real Product Name',
        'facets' => ['f_catalog' => ['gtin' => '0001-2345-60001-2']],
    ];

    expect(emitted($product, KeyClass::Gtin14))->toBe(['00012345600012']);
});

it('emits a title-only key above minLength and nothing below it', function () {
    // TitleOnly::minLength() is 12, measured on the CANONICALISED value.
    expect(emitted(['kind' => 'video', 'headline' => 'A Long Enough Title'], KeyClass::TitleOnly))
        ->toBe(['a long enough title'])
        ->and(emitted(['kind' => 'video', 'headline' => 'Short One'], KeyClass::TitleOnly))->toBe([]);
});

it('emits a title-release key from headline and creator, never from the year', function () {
    $release = [
        'kind' => 'release',
        'headline' => 'Cowboy Carter',
        'facets' => [
            'f_authored' => ['creator' => 'Beyoncé'],
            'f_published' => ['published_from' => '2024-03-29'],
        ],
    ];

    expect(emitted($release, KeyClass::TitleRelease))->toBe(['cowboy carter|beyonce'])
        ->and(emitted(['kind' => 'release', 'headline' => 'Cowboy Carter'], KeyClass::TitleRelease))->toBe([]);
});

it('emits a title-duration key only for a track with a duration', function () {
    $track = [
        'kind' => 'track',
        'headline' => 'Some Song Title',
        'facets' => ['f_duration' => ['seconds' => 214]],
    ];
    $video = [
        'kind' => 'video',
        'headline' => 'Some Video Title',
        'facets' => ['f_duration' => ['seconds' => 214]],
    ];

    expect(emitted($track, KeyClass::TitleDuration))->toBe(['some song title|214'])
        ->and(emitted($video, KeyClass::TitleDuration))->toBe([]);
});

it('emits offering-name-in-category when a menu item has a collection, and offering-name when it does not', function () {
    $withCategory = [
        'kind' => 'menu_item',
        'headline' => 'Fries',
        'collections' => [['label' => 'Sides', 'external_ref' => 'c1', 'kind' => 'menu_category']],
    ];
    $without = ['kind' => 'menu_item', 'headline' => 'Fries'];

    expect(emitted($withCategory, KeyClass::OfferingNameInCategory))->toBe(['sides|fries'])
        // "Fries" (5 chars) clears OfferingName's floor since 2026-08-18 (8 → 4):
        // a dish name is scoped to one owner's catalogue.
        ->and(emitted($withCategory, KeyClass::OfferingName))->toBe(['fries'])
        ->and(emitted($without, KeyClass::OfferingNameInCategory))->toBe([])
        ->and(emitted($without, KeyClass::OfferingName))->toBe(['fries']);
});

it('emits one offering-name-in-category key per category a multi-category item belongs to', function () {
    $item = [
        'kind' => 'menu_item',
        'headline' => 'Garlic Naan',
        'collections' => [
            ['label' => 'Sides', 'external_ref' => 'c1'],
            ['label' => 'Breads', 'external_ref' => 'c2'],
            ['label' => 'Sides', 'external_ref' => 'c1'],
        ],
    ];

    expect(emitted($item, KeyClass::OfferingNameInCategory))->toBe(['sides|garlic naan', 'breads|garlic naan']);
});

it('emits an offering-name-spec key from a service duration', function () {
    $service = [
        'kind' => 'service',
        'headline' => 'Deep Tissue Massage',
        'facets' => ['f_duration' => ['seconds' => 3600]],
    ];

    expect(emitted($service, KeyClass::OfferingNameSpec))->toBe(['deep tissue massage|3600'])
        ->and(emitted(['kind' => 'service', 'headline' => 'Deep Tissue Massage'], KeyClass::OfferingNameSpec))->toBe([]);
});

it('emits an event-occurrence key normalised to a UTC instant', function () {
    $local = [
        'kind' => 'event',
        'headline' => 'Winter Warmer Session',
        'facets' => ['f_occurrence' => ['starts_at_local' => '2026-08-20T18:00:00+10:00']],
    ];
    $utc = [
        'kind' => 'event',
        'headline' => 'Winter Warmer Session',
        'facets' => ['f_occurrence' => ['starts_at_utc' => '2026-08-20T08:00:00Z']],
    ];

    // Two platforms spelling the same instant differently must produce the
    // same key, or the corroborating union can never fire.
    expect(emitted($local, KeyClass::EventOccurrence))->toBe(['winter warmer session|2026-08-20T08:00:00Z'])
        ->and(emitted($utc, KeyClass::EventOccurrence))->toBe(['winter warmer session|2026-08-20T08:00:00Z']);
});

it('emits no event-occurrence key when the timestamp cannot be parsed', function () {
    $event = [
        'kind' => 'event',
        'headline' => 'Winter Warmer Session',
        'facets' => ['f_occurrence' => ['starts_at_local' => 'next tuesdayish']],
    ];

    expect(emitted($event, KeyClass::EventOccurrence))->toBe([]);
});

it('emits a content digest from the first media entry, for media kinds only', function () {
    $media = [
        'kind' => 'media',
        'media' => [
            ['role' => 'cover', 'url' => 'https://cdn/a.jpg', 'ref' => 'instagram:ABC:0'],
            ['role' => 'gallery', 'url' => 'https://cdn/b.jpg', 'ref' => 'instagram:ABC:1'],
        ],
    ];
    $video = ['kind' => 'video', 'headline' => 'A Long Enough Title', 'media' => [['url' => 'https://cdn/a.jpg']]];

    // One digest, not one per carousel frame: a shared gallery frame must not
    // union two different posts on the joining tier.
    expect(emitted($media, KeyClass::ContentDigest))->toBe(['url-'.sha1('instagram:ABC:0')])
        ->and(emitted($video, KeyClass::ContentDigest))->toBe([]);
});

it('emits no content digest when the media entry has neither ref nor url', function () {
    expect(emitted(['kind' => 'media', 'media' => [['role' => 'cover']]], KeyClass::ContentDigest))->toBe([]);
});

it('emits an enclosure url for an episode that carries a playable stream', function () {
    $episode = [
        'kind' => 'episode',
        'headline' => 'Episode Ninety Nine',
        'facets' => ['f_playable' => ['stream_url' => 'https://Feeds.example/EP99.mp3']],
    ];

    expect(emitted($episode, KeyClass::EnclosureUrl))->toBe(['https://feeds.example/ep99.mp3'])
        ->and(emitted(['kind' => 'episode', 'headline' => 'Episode Ninety Nine'], KeyClass::EnclosureUrl))->toBe([]);
});

it('emits a loose title with every bracketed segment stripped', function () {
    $bracketed = ['kind' => 'video', 'headline' => 'Some Song Title (Live at Wembley)'];
    $plain = ['kind' => 'video', 'headline' => 'Some Song Title'];

    // Both sides must emit, or the pair can never surface as a candidate —
    // the whole point of the loose key is matching the decorated against the
    // undecorated, so suppressing it when loose == strict would disarm it.
    expect(emitted($bracketed, KeyClass::TitleLoose))->toBe(['some song title'])
        ->and(emitted($plain, KeyClass::TitleLoose))->toBe(['some song title'])
        // TitleLoose::minLength() is 10, on the canonicalised value.
        ->and(emitted(['kind' => 'video', 'headline' => 'Short (Live)'], KeyClass::TitleLoose))->toBe([]);
});

it('emits a name-price-band key from the cheapest offer', function () {
    $service = [
        'kind' => 'service',
        'headline' => 'Deep Tissue Massage',
        'offers' => [
            ['amount_minor' => 12000, 'currency' => 'AUD'],
            ['amount_minor' => 5800, 'currency' => 'AUD'],
        ],
    ];

    expect(emitted($service, KeyClass::NamePriceBand))->toBe(['deep tissue massage|55-60'])
        ->and(emitted(['kind' => 'service', 'headline' => 'Deep Tissue Massage'], KeyClass::NamePriceBand))->toBe([]);
});

it('emits an opaque author-date-body key for a review', function () {
    $review = [
        'kind' => 'review',
        'headline' => null,
        'facets' => ['f_review' => [
            'author_name' => 'Jane Doe',
            'reviewed_at' => '2026-07-01T10:00:00Z',
            'text' => 'Absolutely brilliant, would come back.',
        ]],
    ];

    $emitted = emitted($review, KeyClass::AuthorDateBody);

    // Hashed on purpose: the reviewer's name is PII and identity_candidates
    // surfaces key values for human review (LEGAL-2 / PRIV-2 still open).
    expect($emitted)->toHaveCount(1)
        ->and($emitted[0])->toMatch('/^[0-9a-f]{40}$/')
        ->and($emitted[0])->not->toContain('Jane');
});

it('emits no author-date-body key when the review carries no text', function () {
    $review = [
        'kind' => 'review',
        'facets' => ['f_review' => ['author_name' => 'Jane Doe', 'reviewed_at' => '2026-07-01T10:00:00Z']],
    ];

    expect(emitted($review, KeyClass::AuthorDateBody))->toBe([]);
});

it('emits nothing beyond the platform object for a kind no key class declares', function () {
    // `channel` appears in no KeyClass::kinds() list — it must stay a
    // platform-scoped singleton however rich its projection is.
    $channel = [
        'kind' => 'channel',
        'headline' => 'A Long Enough Channel Name',
        'facets' => ['f_channel' => ['handle' => 'someone', 'followers' => 10]],
    ];

    expect(emittedClasses($channel))->toBe([KeyClass::PlatformObject]);
});

it('respects appliesTo and minLength for every key it emits', function () {
    // Property check across a fat projection: nothing may be emitted for a
    // kind the class does not declare, or below its own minimum length.
    foreach (['track', 'release', 'video', 'episode', 'service', 'menu_item', 'product', 'event', 'media', 'review'] as $kind) {
        $projection = [
            'kind' => $kind,
            'headline' => 'A Sufficiently Long Headline',
            'facets' => [
                'f_link' => ['url' => 'https://example.test/a'],
                'f_catalog' => ['isrc' => 'USRC12345678', 'gtin' => '00012345600012'],
                'f_duration' => ['seconds' => 300],
                'f_authored' => ['creator' => 'Some Creator'],
                'f_playable' => ['stream_url' => 'https://example.test/a.mp3'],
                'f_occurrence' => ['starts_at_utc' => '2026-08-20T08:00:00Z'],
                'f_review' => ['author_name' => 'Jane', 'reviewed_at' => '2026-07-01', 'text' => 'Great'],
            ],
            'media' => [['ref' => 'r1']],
            'offers' => [['amount_minor' => 1000, 'currency' => 'AUD']],
            'collections' => [['label' => 'Some Category', 'external_ref' => 'c1']],
        ];

        foreach ((new IdentityKeyDeriver)->derive('x:acct-0:1', $projection) as $key) {
            expect($key->class->appliesTo($kind))->toBeTrue("{$key->class->value} emitted for {$kind}")
                ->and(mb_strlen($key->class->canonicalise($key->value)))
                ->toBeGreaterThanOrEqual($key->class->minLength(), "{$key->class->value} below minLength");
        }
    }
});

it('folds accented latin to ascii the same way on every platform', function () {
    // Regression on convergence F26. This was iconv ASCII//TRANSLIT, which is
    // a C-library behaviour: macOS libiconv gave "beyonc'e" and glibc under
    // the container's POSIX locale gave "beyonc?" — so "bjork" arrived on dev
    // as the two-token "bj rk", and the same title produced different identity
    // keys depending on which machine derived it. Asserting the ASCII output
    // is what makes that a test failure rather than a live-data surprise.
    $cases = [
        'Beyoncé' => 'beyonce',
        'Björk' => 'bjork',
        'Señor Naan' => 'senor naan',
        'Motörhead' => 'motorhead',
        'Straße' => 'strasse',
        'Ångström' => 'angstrom',
        'naïve' => 'naive',
        'Œuvre' => 'oeuvre',
        'Crème Brûlée' => 'creme brulee',
        // Not a decoration to strip — an apostrophe is a spelling difference,
        // so it folds rather than splitting the word in two.
        "Don't Stop" => 'dont stop',
        'Don’t Stop' => 'dont stop',
    ];

    foreach ($cases as $input => $expected) {
        expect(KeyClass::normalizeText((string) $input))->toBe($expected, "normalising {$input}");
    }
});

it('emits identity keys that survive an accented artist name', function () {
    $release = [
        'kind' => 'release',
        'headline' => 'Cowboy Carter',
        'facets' => ['f_authored' => ['creator' => 'Beyoncé']],
    ];

    expect(emitted($release, KeyClass::TitleRelease))->toBe(['cowboy carter|beyonce']);
});

it('emits stable values across repeated derivation', function () {
    $projection = [
        'kind' => 'release',
        'headline' => 'Cowboy Carter',
        'facets' => ['f_authored' => ['creator' => 'Beyoncé'], 'f_link' => ['url' => 'https://example.test/a']],
        'media' => [['ref' => 'r1']],
    ];

    $first = (new IdentityKeyDeriver)->derive('x:acct-0:1', $projection);
    $second = (new IdentityKeyDeriver)->derive('x:acct-0:1', $projection);

    expect(array_map(fn (IdentityKey $k) => [$k->class->value, $k->value], $first))
        ->toBe(array_map(fn (IdentityKey $k) => [$k->class->value, $k->value], $second));
});
