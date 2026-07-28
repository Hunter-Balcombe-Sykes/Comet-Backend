<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupContentCurationTables();
});

function traceOf(object $pro, string $sectionId): array
{
    return actingAsUser($pro)->getJson("/api/site/sections/{$sectionId}/trace")->json('trace');
}

it('explains why a matched item is on the page', function () {
    $pro = createTenant('trace-match');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]]),
    ]);
    seedContentItem($pro->id, ['kind' => 'video', 'headline_cache' => 'A video']);

    $trace = traceOf($pro, $sectionId);

    expect($trace['candidates'])->toHaveCount(1)
        ->and($trace['candidates'][0]['verdict'])->toBe('included')
        ->and($trace['candidates'][0]['reason'])->toBe('Matched the rule.')
        ->and($trace['rule']['sentence'])->toBe('Anything that is a video');
});

it('explains why a hidden item is missing', function () {
    $pro = createTenant('trace-hidden');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]]),
    ]);
    $itemId = seedContentItem($pro->id, ['kind' => 'video']);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'excluded'])->assertOk();

    $trace = traceOf($pro, $sectionId);
    $row = collect($trace['candidates'])->firstWhere('itemId', $itemId);

    expect($row['verdict'])->toBe('excluded')
        ->and($row['reason'])->toContain('Hidden by you');
});

it('reports a pin ahead of the rule and names its position', function () {
    $pro = createTenant('trace-pin');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]]),
    ]);
    seedContentItem($pro->id, ['kind' => 'video', 'headline_cache' => 'Rule pick']);
    $pinned = seedContentItem($pro->id, ['kind' => 'video', 'headline_cache' => 'My pick']);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$pinned}", ['state' => 'pinned'])->assertOk();

    $trace = traceOf($pro, $sectionId);

    expect($trace['candidates'][0]['itemId'])->toBe($pinned)
        ->and($trace['candidates'][0]['verdict'])->toBe('pinned')
        ->and($trace['candidates'][0]['reason'])->toContain('position 1');
});

it('says an item fell off the end rather than silently dropping it', function () {
    $pro = createTenant('trace-limit');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]]),
        'limit_n' => 1,
    ]);
    seedContentItem($pro->id, ['kind' => 'video', 'headline_cache' => 'One']);
    seedContentItem($pro->id, ['kind' => 'video', 'headline_cache' => 'Two']);

    $verdicts = collect(traceOf($pro, $sectionId)['candidates'])->pluck('verdict')->all();

    expect($verdicts)->toBe(['included', 'over_limit']);
});

it('names the operators the builder does not execute instead of pretending it applied them', function () {
    // A diagnostic that disagrees with the live page is worse than none.
    $pro = createTenant('trace-gap');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'rule' => json_encode(['all' => [
            ['op' => 'kind_is', 'values' => ['video']],
            ['op' => 'tagged_with', 'values' => ['live']],
        ]]),
    ]);

    $trace = traceOf($pro, $sectionId);
    $byOp = collect($trace['rule']['predicates'])->keyBy('op');

    expect($byOp['kind_is']['status'])->toBe('applied')
        ->and($byOp['tagged_with']['status'])->toBe('ignored')
        ->and($trace['gaps'][0]['code'])->toBe('unexecuted_operators')
        ->and($trace['gaps'][0]['detail'])->toContain('tagged_with');
});

it('consults no rule at all for a hand-picked section', function () {
    $pro = createTenant('trace-handpicked');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'mode' => 'hand_picked',
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]]),
    ]);
    seedContentItem($pro->id, ['kind' => 'video', 'headline_cache' => 'Not picked']);

    expect(traceOf($pro, $sectionId)['candidates'])->toBe([]);
});

it('reports whether the built page has caught up', function () {
    $pro = createTenant('trace-buildstate');
    [, $sectionId] = seedPageWithSection($pro->site->id);
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])->assertOk();

    $state = traceOf($pro, $sectionId)['buildState'];

    expect($state['stale'])->toBeTrue()
        ->and($state['contentRevision'])->toBeGreaterThan($state['builtRevision']);
});

it('shows a deleted item as deleted rather than omitting it', function () {
    $pro = createTenant('trace-removed');
    [, $sectionId] = seedPageWithSection($pro->site->id);
    $itemId = seedContentItem($pro->id, ['kind' => 'video']);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])->assertOk();
    DB::table('content.items')->where('id', $itemId)->update(['removed_at' => now()]);

    $row = collect(traceOf($pro, $sectionId)['candidates'])->firstWhere('itemId', $itemId);

    expect($row['verdict'])->toBe('removed')
        ->and($row['reason'])->toContain('deleted');
});

it('404s a section belonging to another user', function () {
    $mine = createTenant('trace-mine');
    $theirs = createTenant('trace-theirs');
    [, $theirSection] = seedPageWithSection($theirs->site->id);

    actingAsUser($mine)->getJson("/api/site/sections/{$theirSection}/trace")->assertStatus(404);
});

it('404s a section id that does not exist', function () {
    $pro = createTenant('trace-missing');

    actingAsUser($pro)->getJson('/api/site/sections/'.Str::uuid().'/trace')->assertStatus(404);
});
