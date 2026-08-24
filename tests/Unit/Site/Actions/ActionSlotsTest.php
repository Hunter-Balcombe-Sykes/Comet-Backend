<?php

use App\Models\Core\Site\Site;
use App\Site\Actions\ActionSettings;
use App\Site\Actions\ActionSlots;

/** 12 candidates c0..c11: c0 newest … c11 oldest; c10, c11 undated. */
function slotCandidates(): array
{
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $out[] = [
            'id' => 'item:c'.$i, 'kind' => 'item', 'label' => 'C'.$i, 'url' => 'https://x/'.$i, 'thumb' => null,
            'connectedAt' => $i >= 10 ? null : sprintf('2026-08-%02dT00:00:00+00:00', 20 - $i),
            'ref' => ['pool' => 'watch', 'itemId' => 'c'.$i], 'meta' => ['pool' => 'watch'],
        ];
    }

    return $out;
}

function settingsOf(array $actions): ActionSettings
{
    return ActionSettings::fromSite(new Site(['settings' => ['actions' => $actions]]));
}

function ids(array $entries): array
{
    return array_map(fn (array $e) => $e['id'], $entries);
}

it('newest orders by connectedAt desc with undated last, caps at the slot count, positions contiguous', function () {
    $r = ActionSlots::resolve(slotCandidates(), [], settingsOf(['mode' => 'newest']));

    expect(ids($r['entries']))->toBe(['item:c0', 'item:c1', 'item:c2', 'item:c3', 'item:c4', 'item:c5', 'item:c6', 'item:c7', 'item:c8', 'item:c9'])
        ->and(array_column($r['entries'], 'position'))->toBe(range(0, 9))
        ->and(array_column($r['entries'], 'locked'))->toBe(array_fill(0, 10, false))
        ->and($r['unavailable'])->toBe([]);
});

it('newest puts undated candidates after every dated one', function () {
    $few = array_slice(slotCandidates(), 8); // c8, c9 dated; c10, c11 not
    $r = ActionSlots::resolve($few, [], settingsOf(['mode' => 'newest']));
    expect(ids($r['entries']))->toBe(['item:c8', 'item:c9', 'item:c10', 'item:c11']);
});

it('smart orders ranked candidates by stored rank asc (rank 1 first), then unranked by newest — RANK-1', function () {
    // Ranks are NOT monotonically consistent with a descending read: 1,2,3
    // assigned to c7,c3,c11 in that order. A comparator that wrongly sorts
    // descending (the old score-desc direction) would emit c11,c3,c7 —
    // the reverse of the expected sequence — so this inversion is the
    // non-vacuity proof for the comparator direction.
    $ranks = ['item:c7' => 1, 'item:c3' => 2, 'item:c11' => 3];
    $r = ActionSlots::resolve(slotCandidates(), $ranks, settingsOf(['mode' => 'smart']));

    expect(ids($r['entries']))->toBe(['item:c7', 'item:c3', 'item:c11', 'item:c0', 'item:c1', 'item:c2', 'item:c4', 'item:c5', 'item:c6', 'item:c8']);
});

it('smart with an empty ranks map reproduces plain newest ordering exactly (every candidate falls through unranked)', function () {
    $r = ActionSlots::resolve(slotCandidates(), [], settingsOf(['mode' => 'smart']));

    expect(ids($r['entries']))->toBe(['item:c0', 'item:c1', 'item:c2', 'item:c3', 'item:c4', 'item:c5', 'item:c6', 'item:c7', 'item:c8', 'item:c9']);
});

it('smart hysteresis pin: two ranked candidates ordered opposite to their connectedAt still come out in rank order', function () {
    // c5 (older, connectedAt 2026-08-15) ranked ABOVE c1 (newer, 2026-08-19) —
    // if the comparator fell through to connectedAt instead of honouring
    // rank, c1 would come first. Rank must win.
    $ranks = ['item:c5' => 1, 'item:c1' => 2];
    $r = ActionSlots::order([slotCandidates()[1], slotCandidates()[5]], $ranks);

    expect(ids($r))->toBe(['item:c5', 'item:c1']);
});

it('smart falls THROUGH on an equal rank to connectedAt desc, in either input order', function () {
    // Reachable in production: actionRanksForSite() passes stored ranks through
    // verbatim, so the narrow window between computeForSite()'s upsert and its
    // stale-key delete can hand this comparator two candidates sharing a rank.
    // The equal-rank arm must NOT short-circuit — it has to reach the existing
    // undated -> connectedAt desc -> id chain, or the order is nondeterministic.
    // Asserted in both input orders so it cannot pass on incidental array order.
    $ranks = ['item:c5' => 3, 'item:c1' => 3];

    expect(ids(ActionSlots::order([slotCandidates()[5], slotCandidates()[1]], $ranks)))
        ->toBe(['item:c1', 'item:c5']);
    expect(ids(ActionSlots::order([slotCandidates()[1], slotCandidates()[5]], $ranks)))
        ->toBe(['item:c1', 'item:c5']);
});

it('a lock holds its position in smart and newest and is not duplicated from the ranking', function () {
    $settings = settingsOf(['mode' => 'newest', 'slots' => [['position' => 2, 'id' => 'item:c9']]]);
    $r = ActionSlots::resolve(slotCandidates(), [], $settings);

    expect(ids($r['entries']))->toBe(['item:c0', 'item:c1', 'item:c9', 'item:c2', 'item:c3', 'item:c4', 'item:c5', 'item:c6', 'item:c7', 'item:c8'])
        ->and($r['entries'][2]['locked'])->toBeTrue()
        ->and($r['entries'][1]['locked'])->toBeFalse();

    $settings = settingsOf(['mode' => 'smart', 'slots' => [['position' => 0, 'id' => 'item:c9']]]);
    $r = ActionSlots::resolve(slotCandidates(), ['item:c1' => 1], $settings);
    expect(ids($r['entries'])[0])->toBe('item:c9')->and(ids($r['entries'])[1])->toBe('item:c1');
});

it('a lock beyond the filled length lands at the end and positions renumber contiguously', function () {
    $five = array_slice(slotCandidates(), 0, 5);
    $settings = settingsOf(['mode' => 'newest', 'slots' => [['position' => 9, 'id' => 'item:c2']]]);
    $r = ActionSlots::resolve($five, [], $settings);

    expect(ids($r['entries']))->toBe(['item:c0', 'item:c1', 'item:c3', 'item:c4', 'item:c2'])
        ->and(array_column($r['entries'], 'position'))->toBe(range(0, 4));
});

it('an unavailable lock is skipped, reported, and its slot filled from the ranking', function () {
    $settings = settingsOf(['mode' => 'newest', 'slots' => [['position' => 0, 'id' => 'item:gone'], ['position' => 1, 'id' => 'item:c5']]]);
    $r = ActionSlots::resolve(slotCandidates(), [], $settings);

    expect(ids($r['entries'])[0])->toBe('item:c0')
        ->and(ids($r['entries'])[1])->toBe('item:c5')
        ->and($r['unavailable'])->toBe(['item:gone']);
});

it('manual places only the slots, in position order, nothing auto-filled, all locked', function () {
    $settings = settingsOf(['mode' => 'manual', 'slots' => [
        ['position' => 1, 'id' => 'item:c4'], ['position' => 0, 'id' => 'item:c11'], ['position' => 2, 'id' => 'item:gone'], ['position' => 3, 'id' => 'item:c0'],
    ]]);
    $r = ActionSlots::resolve(slotCandidates(), ['item:c1' => 1], $settings);

    expect(ids($r['entries']))->toBe(['item:c11', 'item:c4', 'item:c0'])
        ->and(array_column($r['entries'], 'position'))->toBe([0, 1, 2])
        ->and(array_column($r['entries'], 'locked'))->toBe([true, true, true])
        ->and($r['unavailable'])->toBe(['item:gone']);
});

it('manual with no slots yields no entries', function () {
    $r = ActionSlots::resolve(slotCandidates(), [], settingsOf(['mode' => 'manual']));
    expect($r['entries'])->toBe([]);
});

it('the limit is respected after locks are placed', function () {
    $settings = settingsOf(['mode' => 'newest', 'slots' => [['position' => 0, 'id' => 'item:c11'], ['position' => 9, 'id' => 'item:c10']]]);
    $r = ActionSlots::resolve(slotCandidates(), [], $settings, limit: 4);

    expect(ids($r['entries']))->toBe(['item:c11', 'item:c0', 'item:c1', 'item:c2'])
        ->and($r['unavailable'])->toBe([]);
});

it('entries carry the wire shape and nothing else', function () {
    $r = ActionSlots::resolve(array_slice(slotCandidates(), 0, 1), [], settingsOf(['mode' => 'newest']));
    expect(array_keys($r['entries'][0]))->toBe(['position', 'id', 'kind', 'label', 'url', 'thumb', 'locked', 'ref']);
});

it('newest puts undated synced items after dated ones even when seen more recently (X5)', function () {
    $dated = slotCandidates()[5]; // connectedAt 2026-08-15
    $undated = slotCandidates()[0]; // connectedAt 2026-08-20 but flagged undated
    $undated['meta']['undated'] = true;
    $r = ActionSlots::resolve([$undated, $dated], [], settingsOf(['mode' => 'newest']));
    expect(ids($r['entries']))->toBe(['item:c5', 'item:c0']);
});
