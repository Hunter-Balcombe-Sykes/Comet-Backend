<?php

use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\KeyClass;
use App\Content\Identity\Resolver;
use App\Content\Identity\SourceItem;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// The resolver is pure: no database, no clock, no network. Every test here is
// "these records with these keys, plus these human rulings, group like THIS".

function idItem(string $coord, string $sourceId, string $kind, array $keys = []): SourceItem
{
    return new SourceItem($coord, $sourceId, $kind, $keys);
}

function idKey(KeyClass $class, string $value): IdentityKey
{
    return new IdentityKey($class, $value);
}

// ── Joining keys ────────────────────────────────────────────────────────────

it('merges two records that share a joining key', function () {
    $result = (new Resolver)->resolve([
        idItem('spotify:acct:track1', 'src-spotify', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('apple:acct:t-99', 'src-apple', 'track', [idKey(KeyClass::Isrc, 'us-rc1-76-07839')]),
    ]);

    // Canonicalisation strips the punctuation, so these are the same ISRC.
    expect($result->sameItem('spotify:acct:track1', 'apple:acct:t-99'))->toBeTrue()
        ->and($result->groups)->toHaveCount(1);
});

it('keeps records apart when their joining keys differ', function () {
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ]);

    expect($result->groups)->toHaveCount(2);
});

it('never unions a key across item kinds it is not declared for', function () {
    // A product GTIN and a track must not merge just because a value collides.
    $result = (new Resolver)->resolve([
        idItem('shop:1', 'src-shop', 'product', [idKey(KeyClass::Gtin14, '09312345678907')]),
        idItem('music:1', 'src-music', 'track', [idKey(KeyClass::Gtin14, '09312345678907')]),
    ]);

    expect($result->groups)->toHaveCount(2);
});

it('lets anything absorb a bare link, the one sanctioned cross-kind union', function () {
    $result = (new Resolver)->resolve([
        idItem('paste:1', 'src-manual', 'link', [idKey(KeyClass::CanonicalUrl, 'https://open.spotify.com/track/abc')]),
        idItem('spotify:1', 'src-spotify', 'track', [idKey(KeyClass::CanonicalUrl, 'https://open.spotify.com/track/abc')]),
    ]);

    expect($result->sameItem('paste:1', 'spotify:1'))->toBeTrue();
});

// ── The user outranks the machine (C8) ──────────────────────────────────────

it('separates records the user called different, even against a joining key', function () {
    $result = (new Resolver)->resolve(
        [
            idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
            idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        ],
        [new Decision('a:1', 'b:1', 'different')],
    );

    // A shared ISRC would normally be identity. The user said otherwise.
    expect($result->sameItem('a:1', 'b:1'))->toBeFalse()
        ->and($result->groups)->toHaveCount(2);
});

it('merges records the user called the same even with no key evidence at all', function () {
    $result = (new Resolver)->resolve(
        [idItem('a:1', 'src-a', 'video'), idItem('b:1', 'src-b', 'video')],
        [new Decision('a:1', 'b:1', 'same')],
    );

    expect($result->sameItem('a:1', 'b:1'))->toBeTrue();
});

it('does not let a later corroborating key undo the user separating a pair', function () {
    $result = (new Resolver)->resolve(
        [
            idItem('a:1', 'src-a', 'track', [idKey(KeyClass::TitleOnly, 'the very long song title')]),
            idItem('b:1', 'src-b', 'track', [idKey(KeyClass::TitleOnly, 'the very long song title')]),
        ],
        [new Decision('a:1', 'b:1', 'different')],
    );

    expect($result->sameItem('a:1', 'b:1'))->toBeFalse();
});

// ── Corroborating keys ──────────────────────────────────────────────────────

it('merges on a corroborating key only across different sources', function () {
    $sameSource = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::TitleOnly, 'a sufficiently long title')]),
        idItem('a:2', 'src-a', 'track', [idKey(KeyClass::TitleOnly, 'a sufficiently long title')]),
    ]);

    // Two tracks on ONE platform sharing a title are two tracks; that platform
    // would have merged them itself if they were one.
    expect($sameSource->groups)->toHaveCount(2);

    $crossSource = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::TitleOnly, 'a sufficiently long title')]),
        idItem('b:1', 'src-b', 'track', [idKey(KeyClass::TitleOnly, 'a sufficiently long title')]),
    ]);

    expect($crossSource->groups)->toHaveCount(1);
});

it('ignores a corroborating value too short to mean anything', function () {
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'menu_item', [idKey(KeyClass::OfferingName, 'Fries')]),
        idItem('b:1', 'src-b', 'menu_item', [idKey(KeyClass::OfferingName, 'Fries')]),
    ]);

    // 'Fries' is under OfferingName's 8-character floor.
    expect($result->groups)->toHaveCount(2);
});

it('merges a short dish name when its category corroborates it', function () {
    // The exact regression the min-length rule would otherwise cause: this is
    // why OfferingNameInCategory exists with a lower floor.
    $result = (new Resolver)->resolve([
        idItem('ubereats:1', 'src-ue', 'menu_item', [idKey(KeyClass::OfferingNameInCategory, 'sides|fries')]),
        idItem('doordash:1', 'src-dd', 'menu_item', [idKey(KeyClass::OfferingNameInCategory, 'sides|fries')]),
    ]);

    expect($result->sameItem('ubereats:1', 'doordash:1'))->toBeTrue();
});

// ── Ambiguity ───────────────────────────────────────────────────────────────

it('ignores a key one source attached to two of its own records', function () {
    // If a platform reports the same ISRC on two of its tracks, its ISRC data
    // is unreliable — that value cannot identify anything anywhere.
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('a:2', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
    ]);

    expect($result->groups)->toHaveCount(3);
});

// ── Evidential keys ─────────────────────────────────────────────────────────

it('never merges on an evidential key, offering it as a candidate instead', function () {
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'menu_item', [idKey(KeyClass::NamePriceBand, 'burger|10-15')]),
        idItem('b:1', 'src-b', 'menu_item', [idKey(KeyClass::NamePriceBand, 'burger|10-15')]),
    ]);

    expect($result->groups)->toHaveCount(2)
        ->and($result->candidates)->toHaveCount(1)
        ->and($result->candidates[0]->left)->toBe('a:1');
});

it('does not offer a candidate for a pair the user already separated', function () {
    $result = (new Resolver)->resolve(
        [
            idItem('a:1', 'src-a', 'menu_item', [idKey(KeyClass::NamePriceBand, 'burger|10-15')]),
            idItem('b:1', 'src-b', 'menu_item', [idKey(KeyClass::NamePriceBand, 'burger|10-15')]),
        ],
        [new Decision('a:1', 'b:1', 'different')],
    );

    // Re-asking a question the user already answered is how a queue becomes
    // noise that gets ignored.
    expect($result->candidates)->toBeEmpty();
});

it('does not offer a candidate for records already merged', function () {
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'menu_item', [
            idKey(KeyClass::OfferingNameInCategory, 'mains|burger'),
            idKey(KeyClass::NamePriceBand, 'burger|10-15'),
        ]),
        idItem('b:1', 'src-b', 'menu_item', [
            idKey(KeyClass::OfferingNameInCategory, 'mains|burger'),
            idKey(KeyClass::NamePriceBand, 'burger|10-15'),
        ]),
    ]);

    expect($result->groups)->toHaveCount(1)
        ->and($result->candidates)->toBeEmpty();
});

// ── Determinism ─────────────────────────────────────────────────────────────

it('produces the same grouping regardless of input order', function () {
    $items = [
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('c:1', 'src-c', 'track', [idKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ];

    $forward = (new Resolver)->resolve($items);
    $reversed = (new Resolver)->resolve(array_reverse($items));

    $normalise = function ($resolution) {
        $groups = array_map(function ($g) {
            sort($g);

            return implode(',', $g);
        }, $resolution->groups);
        sort($groups);

        return $groups;
    };

    expect($normalise($forward))->toBe($normalise($reversed));
});

it('is idempotent — resolving twice changes nothing', function () {
    $items = [
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
    ];

    $first = (new Resolver)->resolve($items);
    $second = (new Resolver)->resolve($items);

    expect($first->groups)->toBe($second->groups);
});

it('canonicalises away vendor decoration before comparing titles', function () {
    $result = (new Resolver)->resolve([
        idItem('yt:1', 'src-yt', 'video', [idKey(KeyClass::TitleOnly, 'Midnight Drive (Official Video)')]),
        idItem('vimeo:1', 'src-vimeo', 'video', [idKey(KeyClass::TitleOnly, 'midnight drive')]),
    ]);

    // "(Official Video)" is formatting, not identity.
    expect($result->sameItem('yt:1', 'vimeo:1'))->toBeTrue();
});
