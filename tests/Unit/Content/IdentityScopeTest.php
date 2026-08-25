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

// A signature shared by MORE than two coords is one hyperedge, not a chain —
// every carrier of 'wandering star' sits at distance 1 from the touched coord
// (plan §A.1, corrected 2026-08-25: poisoning is always one hop, because a
// signature can only poison A's outcome if A itself carries it). The point of
// this test is that the walk expands the WHOLE bucket, not just the first
// match it finds, so a same-source sibling like ap:acct:t8 — the one
// Resolver::poisonedKeys() needs to see to disable the signature — is present
// in the scoped set for the caller to hand back to the full resolver.
it('expands a shared signature to every member, so a poisoning sibling is present', function () {
    $result = (new IdentityScope)->component([
        scopeItem('sp:acct:t1', 'src-sp', 'track', [scopeKey(KeyClass::TitleOnly, 'Wandering Star')]),
        scopeItem('ap:acct:t9', 'src-ap', 'track', [scopeKey(KeyClass::TitleOnly, 'Wandering Star')]),
        scopeItem('ap:acct:t8', 'src-ap', 'track', [scopeKey(KeyClass::TitleOnly, 'wandering star')]),
    ], [], ['sp:acct:t1']);

    expect($result['coords'])->toHaveCount(3)
        ->and($result['coords'])->toContain('ap:acct:t8');
});

// THE GENUINE ONE-HOP COUNTER-EXAMPLE (plan §A.1, corrected 2026-08-25). D
// shares NO signature with A — it is reached only through the chain a→b (isrc)
// →c (title) →d (url). One hop from a reaches only b; two hops reach b and c;
// only a walk to FIXPOINT reaches d. This is the real reason transitivity is
// required: union-find groups a, b and c on different signatures into one
// group, and bindGroup() binds the whole group to a single item id — a
// one-hop or two-hop implementation would leave d bound elsewhere, splitting a
// group the full resolve keeps whole.
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

    expect($result['coords'])->toHaveCount(4)
        ->and($result['coords'])->toContain('d');
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

// §A.4: a manual source has no connection_id, so
// IdentityDecisionController's reprojection join can silently dispatch
// nothing for a ruling on two hand-added items — neither coord is ever
// "touched". The closure must seed from the ruling itself or the owner's
// verdict would never take effect under the narrowed resolve.
it('seeds from a "same" ruling even when neither side was touched', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'same')], []);

    expect($result['coords'])->toHaveCount(2)
        ->and($result['coords'])->toContain('a')
        ->and($result['coords'])->toContain('b');
});

// A cut only ever SUPPRESSES a union, so it can only matter between coords
// that already share a signature — reachable once either side is touched.
// Seeding from it too would drag unrelated groups into every resolve for
// no benefit (§A.4).
it('does NOT seed from a "different" ruling', function () {
    $result = (new IdentityScope)->component([
        scopeItem('a', 'src-a', 'track', [scopeKey(KeyClass::Isrc, 'USRC17607839')]),
        scopeItem('b', 'src-b', 'track', [scopeKey(KeyClass::Isrc, 'GBAYE0601498')]),
    ], [new Decision('a', 'b', 'different')], []);

    expect($result['coords'])->toBe([])
        ->and($result['capped'])->toBeFalse();
});
