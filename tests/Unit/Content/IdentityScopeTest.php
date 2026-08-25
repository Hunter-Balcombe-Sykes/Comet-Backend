<?php

use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\IdentityScope;
use App\Content\Identity\KeyClass;
use App\Content\Identity\SourceItem;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// IdentityScope is pure: no database, no clock, no network. Every test here is
// "these records with these keys, touching THIS one, must pull in THESE".

function scopeItem(string $coord, string $sourceId, string $kind, array $keys = []): SourceItem
{
    return new SourceItem($coord, $sourceId, $kind, $keys);
}

function scopeKey(KeyClass $class, string $value): IdentityKey
{
    return new IdentityKey($class, $value);
}

it('returns just the touched coord when it shares nothing', function () {
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toBe(['sp:acct:t1'])
        ->and($result['capped'])->toBeFalse();
});

it('pulls in an item sharing a signature, canonicalisation included', function () {
    // Punctuation differs; canonicalise() folds them to one signature.
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::Isrc, 'us-rc1-76-07839')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toHaveCount(2)
        ->and($result['coords'])->toContain('ap:acct:t9');
});

// THE ONE-HOP COUNTER-EXAMPLE (plan §A.1). A pulls in B via a shared title.
// B's OWN source carries a second copy of that title on C, which is what
// POISONS the key in Resolver::poisonedKeys(). A one-hop closure omits C, the
// key looks clean, and A merges with B — a merge the full resolve would never
// make, whose loser mergeInto() then HARD-DELETES.
it('pulls in a same-source sibling that poisons a shared key — one hop is not enough', function () {
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::TitleOnly, 'Wandering Star')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::TitleOnly, 'Wandering Star')]),
        scopeItem('ap:acct:t8', 'src-ap', 'track', [scopeKey(KeyClass::TitleOnly, 'wandering star')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toHaveCount(3)
        ->and($result['coords'])->toContain('ap:acct:t8');
});

it('follows the chain transitively to fixpoint', function () {
    // A—B on ISRC, B—C on title, C—D on url: touching A must reach D.
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [
            scopeKey(KeyClass::Isrc, 'USRC17607839'),
            scopeKey(KeyClass::TitleOnly, 'Wandering Star'),
        ]),
        scopeItem('c', 'src-c', 'track', [
            scopeKey(KeyClass::TitleOnly, 'Wandering Star'),
            scopeKey(KeyClass::CanonicalUrl, 'https://x.test/w'),
        ]),
        scopeItem('d', 'src-d', 'track', [scopeKey(KeyClass::CanonicalUrl, 'https://x.test/w')]),
    ], [], ['a']);

    expect($result['coords'])->toHaveCount(4);
});

it('follows a user "same" ruling even with no shared key', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'same')], ['a']);

    expect($result['coords'])->toHaveCount(2);
});

it('ignores a "different" ruling — a cut never widens the component', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'different')], ['a']);

    expect($result['coords'])->toBe(['a']);
});

it('includes weak keys the resolver itself would filter out', function () {
    // 'abc' is under TitleOnly::minLength() (12), so keyIndex() drops it — but
    // poisonedKeys() does NOT filter, so the closure must not either (plan §A.1).
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::TitleOnly, 'abc')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::TitleOnly, 'abc')]),
    ], [], ['a']);

    expect($result['coords'])->toHaveCount(2);
});

it('returns every coord and flags capped when the component is too big', function () {
    $items = [];
    for ($i = 0; $i < 12; $i++) {
        $items[] = scopeItem("c{$i}", 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]);
    }

    $result = (new IdentityScope)->component($items, [], ['c0'], max: 5);

    expect($result['capped'])->toBeTrue()
        ->and($result['coords'])->toHaveCount(12);
});

it('returns nothing when nothing was touched', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
    ], [], []);

    expect($result['coords'])->toBe([])
        ->and($result['capped'])->toBeFalse();
});
