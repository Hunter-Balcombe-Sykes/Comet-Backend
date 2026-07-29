<?php

use App\Content\Identity\DisjointSet;

// Pure PHP, no Laravel — deliberately no uses(TestCase::class) (see
// tests/Pest.php:38-42 and tests/Unit/Enums/EnquiryStatusTest.php for the
// pattern). Booting the framework for a union-find algorithm is waste on a
// suite that already OOMs at 512M.
//
// Helper is uniquely prefixed (dsSorted, not sorted()/groups()) because
// tests/Unit/Content/ResolverTest.php already defines idItem()/idKey() in
// the same global Pest function table — a colliding name here is a fatal
// that aborts the whole suite, not a single test failure.

function dsSorted(DisjointSet $set): array
{
    $g = array_map(function (array $group) {
        sort($group);

        return implode(',', $group);
    }, $set->groups());
    sort($g);

    return $g;
}

it('starts with every element in its own group', function () {
    $set = new DisjointSet(['a', 'b', 'c']);

    expect(dsSorted($set))->toBe(['a', 'b', 'c']);
});

it('joins two elements and their transitive neighbours', function () {
    $set = new DisjointSet(['a', 'b', 'c', 'd']);
    $set->union('a', 'b');
    $set->union('b', 'c');

    expect(dsSorted($set))->toBe(['a,b,c', 'd']);
});

it('is a no-op when both elements are already in one group', function () {
    $set = new DisjointSet(['a', 'b']);
    $set->union('a', 'b');
    $set->union('a', 'b');

    expect(dsSorted($set))->toBe(['a,b'])
        ->and($set->find('a'))->toBe($set->find('b'));
});

it('splits the pair when the second argument is the group root', function () {
    // The historical bug: separate() always re-rooted its SECOND argument, a
    // no-op whenever that argument already WAS the union root. union('a','b')
    // makes 'a' the root here, so separate('b','a') puts the root second —
    // exactly the shape that used to silently fail to split.
    $set = new DisjointSet(['a', 'b']);
    $set->union('a', 'b');
    $set->separate('b', 'a');

    expect(dsSorted($set))->toBe(['a', 'b'])
        ->and($set->find('a'))->not->toBe($set->find('b'));
});

it('splits the pair when the second argument is the non-root child', function () {
    $set = new DisjointSet(['a', 'b']);
    $set->union('a', 'b');
    $set->separate('a', 'b');

    expect(dsSorted($set))->toBe(['a', 'b'])
        ->and($set->find('a'))->not->toBe($set->find('b'));
});

it('splits the pair when neither element is the group root', function () {
    $set = new DisjointSet(['r', 'a', 'b']);
    $set->union('r', 'a');
    $set->union('r', 'b');
    $set->separate('a', 'b');

    // Only assert the pair itself is apart, and that 'r' ends up with
    // exactly one of them — NOT which one, since that's the union-order
    // accident this class's separate() documents (detach pulls the path
    // that ran through it along too).
    expect($set->find('a'))->not->toBe($set->find('b'));
    $groups = $set->groups();
    $withR = array_filter($groups, fn (array $g) => in_array('r', $g, true));
    expect($withR)->toHaveCount(1);
    $rGroup = array_values($withR)[0];
    $hasA = in_array('a', $rGroup, true);
    $hasB = in_array('b', $rGroup, true);
    expect($hasA xor $hasB)->toBeTrue();
});

it('ignores a union that would re-join a separated pair', function () {
    $set = new DisjointSet(['a', 'b']);
    $set->separate('a', 'b');
    $set->union('a', 'b');

    expect(dsSorted($set))->toBe(['a', 'b']);
});

it('blocks a union that would re-join a separated pair transitively', function () {
    $set = new DisjointSet(['a', 'b', 'c']);
    $set->separate('a', 'b');
    $set->union('a', 'c');
    $set->union('c', 'b');

    expect(dsSorted($set))->toBe(['a,c', 'b']);
});

it('never invents a group for an element it was never given', function () {
    // DEFECT A: a decision naming a coord this run never saw must not
    // resurrect that coord as a phantom singleton group. separate() records
    // the pair; the FIRST union() afterwards used to call isSeparated(),
    // which called find() on the unknown element and auto-vivified it into
    // $parent — so groups() emitted a ghost entry for a coord that doesn't
    // exist in this run at all.
    $set = new DisjointSet(['a', 'b']);
    $set->separate('a', 'GHOST');
    $set->union('a', 'b');

    expect(dsSorted($set))->toBe(['a,b']);
});

it('keeps a cut it was given before either element existed', function () {
    $set = new DisjointSet([]);
    $set->separate('a', 'b');
    // Legitimate vivification: find() is the sanctioned way an unknown
    // element enters $parent, once it's actually a member of this run.
    $set->find('a');
    $set->find('b');
    $set->union('a', 'b');

    expect($set->find('a'))->not->toBe($set->find('b'));
});

it('does not lose a cut when the same pair is separated twice', function () {
    $set = new DisjointSet(['a', 'b']);
    $set->union('a', 'b');
    $set->separate('a', 'b');
    $set->separate('a', 'b');
    $set->union('a', 'b');

    expect(dsSorted($set))->toBe(['a', 'b']);
});

// ── Defect B (FU-1): RESOLVED — the tie-break is now a ruling, not an accident ──
// Previously the cut tie-break was order-dependent: with a,b,c all mutually
// unioned and a cut on (a,b), which side 'c' landed on depended on which union
// happened to root first, so the two orders below disagreed ({a,c},{b} versus
// {a},{b,c}). Closing it needed the three things this comment used to list as
// out of scope: recorded union edges, a constraint-respecting rebuild, and a
// product ruling. The ruling: THE CONTESTED ELEMENT STAYS WITH THE
// LEXICOGRAPHICALLY-SMALLER COORDINATE. These tests pin it in both directions.
it('gives the contested element to the smaller coordinate, whatever the union order', function () {
    $orderOne = new DisjointSet(['a', 'b', 'c']);
    $orderOne->union('a', 'b');
    $orderOne->union('a', 'c');
    $orderOne->union('b', 'c');
    $orderOne->separate('a', 'b');

    $orderTwo = new DisjointSet(['a', 'b', 'c']);
    $orderTwo->union('b', 'a');
    $orderTwo->union('b', 'c');
    $orderTwo->union('a', 'c');
    $orderTwo->separate('a', 'b');

    // Both orders now agree, and agree on the ruling: 'a' < 'b', so 'c' stays
    // with 'a'. The old code produced {a},{b,c} for orderTwo.
    foreach ([$orderOne, $orderTwo] as $set) {
        expect(dsSorted($set))->toBe(['a,c', 'b']);
    }
});

it('applies the ruling by coordinate value, not by argument position', function () {
    // separate() is called with the LARGER coordinate first here. If the
    // tie-break keyed off argument order rather than value, 'c' would follow
    // 'b'. Stored decisions arrive with coords sorted, but nothing in the type
    // guarantees a caller does that, so the ruling must not depend on it.
    $set = new DisjointSet(['a', 'b', 'c']);
    $set->union('a', 'c');
    $set->union('b', 'c');
    $set->separate('b', 'a');

    expect(dsSorted($set))->toBe(['a,c', 'b']);
});

it('is unchanged by replaying the same unions in a shuffled order', function () {
    // The property the ruling exists to give: the grouping is a function of
    // the SET of unions and cuts, not of the sequence they arrived in.
    $edges = [['b', 'd'], ['a', 'c'], ['c', 'd'], ['a', 'b']];

    $forward = new DisjointSet(['a', 'b', 'c', 'd']);
    foreach ($edges as [$x, $y]) {
        $forward->union($x, $y);
    }
    $forward->separate('a', 'd');

    $backward = new DisjointSet(['a', 'b', 'c', 'd']);
    foreach (array_reverse($edges) as [$x, $y]) {
        $backward->union($x, $y);
    }
    $backward->separate('a', 'd');

    expect(dsSorted($backward))->toBe(dsSorted($forward))
        ->and($forward->find('a'))->not->toBe($forward->find('d'));
});
