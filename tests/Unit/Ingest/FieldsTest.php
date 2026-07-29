<?php

// #SLOP-2: single consolidated home for the four connectors' near-duplicate
// firstString() copies (Doordash/Square/UberEats menu connectors, Instagram).

use App\Ingest\Support\Fields;

it('string-coerces an int or float value — the divergent case that used to differ per connector', function () {
    expect(Fields::firstString(['id' => 4021], ['id']))->toBe('4021');
    expect(Fields::firstString(['price' => 12.5], ['price']))->toBe('12.5');
});

it('prefers the trimmed string branch over the numeric fallback', function () {
    expect(Fields::firstString(['id' => ' 12 '], ['id']))->toBe('12');
});

it('treats 0 as a present value, not a falsy skip', function () {
    expect(Fields::firstString(['count' => 0], ['count']))->toBe('0');
});

it('skips empty and whitespace-only strings and falls through to the next path', function () {
    expect(Fields::firstString(['a' => '', 'b' => '   ', 'c' => 'value'], ['a', 'b', 'c']))->toBe('value');
});

it('skips null, bool, and array values as not-present', function () {
    expect(Fields::firstString(['a' => null], ['a']))->toBeNull();
    expect(Fields::firstString(['a' => true], ['a']))->toBeNull();
    expect(Fields::firstString(['a' => []], ['a']))->toBeNull();
});

it('returns the first matching path in order, not the last', function () {
    expect(Fields::firstString(['a' => 'first', 'b' => 'second'], ['a', 'b']))->toBe('first');
    expect(Fields::firstString(['b' => 'second'], ['a', 'b']))->toBe('second');
});

it('resolves a dot path via data_get — proves the Instagram capability survived consolidation', function () {
    $post = ['edge_media_to_caption' => ['edges' => [['node' => ['text' => 'caption text']]]]];

    expect(Fields::firstString($post, ['caption', 'edge_media_to_caption.edges.0.node.text']))->toBe('caption text');
});

it('returns null when no path matches', function () {
    expect(Fields::firstString(['a' => 'x'], ['b', 'c']))->toBeNull();
});

// --- firstInt() (moved from InstagramConnector) -----------------------------

it('int-coerces the first numeric value across paths', function () {
    expect(Fields::firstInt(['followers_count' => '1200'], ['followersCount', 'followers_count']))->toBe(1200);
    expect(Fields::firstInt(['edge_followed_by' => ['count' => 42]], ['followersCount', 'edge_followed_by.count']))->toBe(42);
});

it('firstInt returns null when nothing numeric matches', function () {
    expect(Fields::firstInt(['a' => 'not-a-number'], ['a']))->toBeNull();
    expect(Fields::firstInt([], ['a', 'b']))->toBeNull();
});
