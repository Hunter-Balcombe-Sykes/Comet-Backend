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

// #SEM-5 — the guard above only catches a duplicate spelled the SAME way
// twice. poisonedKeys() used to sign on the RAW value while keyIndex() signs
// on the CANONICAL value, so two different spellings of the same ISRC from
// one source dodged detection entirely and fell through to a false merge.
// This is the test that turns RED without the fix: pre-fix it produces ONE
// group (a:1/a:2/b:1 all merged — worse than a missed poison, an actual
// false union across sources); post-fix it must produce 3 (no merge at all).
it('poisons a key even when the same source spelled it two different ways', function () {
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        idItem('a:2', 'src-a', 'track', [idKey(KeyClass::Isrc, 'us-rc1-76-07839')]), // canonicalises identically
        idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
    ]);

    expect($result->groups)->toHaveCount(3);
});

it('poisons on a case-variant URL spelling too — not ISRC-specific', function () {
    $result = (new Resolver)->resolve([
        idItem('a:1', 'src-a', 'link', [idKey(KeyClass::CanonicalUrl, 'https://X.com/A')]),
        idItem('a:2', 'src-a', 'link', [idKey(KeyClass::CanonicalUrl, 'https://x.com/a')]), // same after lowercasing
        idItem('b:1', 'src-b', 'link', [idKey(KeyClass::CanonicalUrl, 'https://x.com/a')]),
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

// ── Defect A — a stale decision must not resurrect a coord ──────────────────

it('ignores a stored decision naming a coord this run never saw', function () {
    // a:1 and b:1 share a corroborating (not joining) key so step 4 actually
    // runs a union() — that union is what triggers DisjointSet's leak, so a
    // fixture that never unions anything cannot exercise the bug. 'gone:9'
    // stands in for a coord whose source items were deleted after the
    // decision was recorded (identity_decisions has no FK to a coord —
    // content_schema.sql:106-114 — so a stale row like this outlives the
    // items it named).
    $result = (new Resolver)->resolve(
        [
            idItem('a:1', 'src-a', 'track', [idKey(KeyClass::TitleOnly, 'a sufficiently long title')]),
            idItem('b:1', 'src-b', 'track', [idKey(KeyClass::TitleOnly, 'a sufficiently long title')]),
        ],
        [new Decision('a:1', 'gone:9', 'different')],
    );

    $flat = array_merge(...$result->groups);
    sort($flat);

    expect($flat)->toBe(['a:1', 'b:1']);
});

it('applies a user cut even when the key evidence arrived from the same source', function () {
    // Reversed relative to the ':68' test above (which puts the union CHILD
    // second): a:1/b:1 share a joining key, but here the decision names the
    // union ROOT second — the exact ordering DisjointSet::separate() used to
    // silently no-op on. This is the unit-lane, DB-free counterpart of
    // IdentityQueueTest.php:215's "BOTH argument orders" regression.
    $result = (new Resolver)->resolve(
        [
            idItem('a:1', 'src-a', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
            idItem('b:1', 'src-b', 'track', [idKey(KeyClass::Isrc, 'USRC17607839')]),
        ],
        [new Decision('b:1', 'a:1', 'different')],
    );

    expect($result->sameItem('a:1', 'b:1'))->toBeFalse();
});

it('does not poison title_release on same-source EDITION duplicates (same duration), so the Spotify track still unions with the Apple song (W5)', function () {
    // Apple lists "Dracula" on the album AND as a single — same duration.
    $apple1 = idItem('apple:1', 'src-apple', 'track', [idKey(KeyClass::TitleRelease, 'dracula|tame impala'), idKey(KeyClass::TitleDuration, 'dracula|214')]);
    $apple2 = idItem('apple:2', 'src-apple', 'track', [idKey(KeyClass::TitleRelease, 'dracula|tame impala'), idKey(KeyClass::TitleDuration, 'dracula|215')]);
    $spotify = idItem('spotify:1', 'src-spotify', 'track', [idKey(KeyClass::TitleRelease, 'dracula|tame impala'), idKey(KeyClass::TitleDuration, 'dracula|213')]);
    $groups = (new Resolver)->resolve([$apple1, $apple2, $spotify], [])->groups;
    $find = fn (string $coord) => collect($groups)->first(fn ($g) => in_array($coord, $g, true));
    expect($find('spotify:1'))->toContain('apple:1');

    // But a same-name DIFFERENT recording (a 30s intro vs the song) still poisons.
    $appleIntro = idItem('apple:3', 'src-apple', 'track', [idKey(KeyClass::TitleRelease, 'let it happen|tame impala'), idKey(KeyClass::TitleDuration, 'let it happen|30')]);
    $appleSong = idItem('apple:4', 'src-apple', 'track', [idKey(KeyClass::TitleRelease, 'let it happen|tame impala'), idKey(KeyClass::TitleDuration, 'let it happen|467')]);
    $spotify2 = idItem('spotify:2', 'src-spotify', 'track', [idKey(KeyClass::TitleRelease, 'let it happen|tame impala')]);
    $groups2 = (new Resolver)->resolve([$appleIntro, $appleSong, $spotify2], [])->groups;
    $find2 = fn (string $coord) => collect($groups2)->first(fn ($g) => in_array($coord, $g, true));
    expect($find2('spotify:2'))->toBe(['spotify:2']);
});
