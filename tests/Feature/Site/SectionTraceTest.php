<?php

use App\Site\Documents\DocumentBuilder;
use App\Site\Sections\RuleOperator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupContentCurationTables();
    // #PGR-36: section writes now route through SiteCacheLanes::bust(),
    // which dispatches CloudflareCachePurgeJob. QUEUE_CONNECTION=sync in
    // phpunit.xml means an unfaked queue runs the job inline, including its
    // self-dispatched delayed follow-ups (sync ignores delay()) — four
    // executions per bust. Faked so this file's tests measure trace
    // behaviour, not job side effects.
    Queue::fake();
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

it('reports an executed operator as applied rather than inventing a gap', function () {
    // A diagnostic that disagrees with the live page is worse than none — and
    // this test used to be the disagreement. It asserted tagged_with was
    // 'ignored' and raised an unexecuted_operators gap, while
    // DocumentBuilderRuleOpsTest proved the builder filters on it. The tracer
    // kept a private list naming only kind_is and published_within, the
    // builder grew five more operators, and nothing reconciled them.
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
        ->and($byOp['tagged_with']['status'])->toBe('applied')
        ->and(array_column($trace['gaps'], 'code'))->not->toContain('unexecuted_operators');
});

it('keeps every declared operator in step with the builder', function () {
    // The guard that stops this drifting a third time. A new RuleOperator case
    // added without a matching applyPredicate() arm fails HERE, where the
    // decision is made, instead of silently producing a trace that claims an
    // operator is applied while the page ignores it.
    $declared = array_map(fn (RuleOperator $op) => $op->value, RuleOperator::cases());

    expect(DocumentBuilder::EXECUTED_OPERATORS)->toEqualCanonicalizing($declared);
});

it('describes published_within by the column the builder actually filters on', function () {
    // The same misreport in miniature: the reason string said "when the item
    // was last seen", conflating the filter with the recency ORDERING.
    // applyPredicate() filters on the published facet and falls back to
    // first_seen_at; last_seen_at is what orderBy('recency') uses.
    $pro = createTenant('trace-published');
    [, $sectionId] = seedPageWithSection($pro->site->id, [
        'rule' => json_encode(['all' => [['op' => 'published_within', 'values' => ['30']]]]),
    ]);

    $reason = traceOf($pro, $sectionId)['rule']['predicates'][0]['reason'];

    expect($reason)->toContain('first seen')
        ->and($reason)->not->toContain('last seen');
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
