<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Tenant isolation — curation surface (plan §5/§6/§7)
|--------------------------------------------------------------------------
| Every route added by the curation API, exercised as user B against user A's
| rows. The assertion is always the same pair: B gets a 404 (never a 403 —
| confirming existence is an enumeration oracle) AND A's row is unchanged.
|
| The second half matters more than the first. A 404 with the write applied is
| the worst outcome available, and it is exactly what an ownership check that
| runs after the mutation produces.
*/

beforeEach(function () {
    setupContentCurationTables();
});

it('never lists another user\'s pages or sections', function () {
    [$a, $b] = createTwoTenants();
    seedPageWithSection($a->site->id, ['label' => 'Secret A']);
    seedPageWithSection($b->site->id, ['label' => 'B section']);

    $labels = collect(actingAsUser($b)->getJson('/api/site/sections')->json('sections'))->pluck('label');

    expect($labels)->toContain('B section')
        ->and($labels)->not->toContain('Secret A');
});

it('never reads, edits or deletes another user\'s page', function () {
    [$a, $b] = createTwoTenants();
    [$pageId] = seedPageWithSection($a->site->id);

    actingAsUser($b)->patchJson("/api/site/pages/{$pageId}", ['label' => 'Pwned'])->assertStatus(404);
    actingAsUser($b)->deleteJson("/api/site/pages/{$pageId}")->assertStatus(404);

    expect(DB::table('site.pages')->where('id', $pageId)->value('label'))->toBe('Work');
});

it('never reads, edits or deletes another user\'s section', function () {
    [$a, $b] = createTwoTenants();
    [, $sectionId] = seedPageWithSection($a->site->id, ['label' => 'Secret A']);

    actingAsUser($b)->getJson("/api/site/sections/{$sectionId}")->assertStatus(404);
    actingAsUser($b)->patchJson("/api/site/sections/{$sectionId}", ['label' => 'Pwned'])->assertStatus(404);
    actingAsUser($b)->deleteJson("/api/site/sections/{$sectionId}")->assertStatus(404);

    expect(DB::table('site.sections')->where('id', $sectionId)->value('label'))->toBe('Secret A');
});

it('never curates another user\'s section', function () {
    [$a, $b] = createTwoTenants();
    [, $sectionId] = seedPageWithSection($a->site->id);
    $itemId = seedContentItem($a->id);

    actingAsUser($b)->getJson("/api/site/sections/{$sectionId}/items")->assertStatus(404);
    actingAsUser($b)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'excluded'])
        ->assertStatus(404);
    actingAsUser($b)->deleteJson("/api/site/sections/{$sectionId}/items/{$itemId}")->assertStatus(404);

    expect(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe(0);
});

it('never overrides a group label on another user\'s section', function () {
    [$a, $b] = createTwoTenants();
    [, $sectionId] = seedPageWithSection($a->site->id, ['group_by' => 'month']);

    actingAsUser($b)->putJson("/api/site/sections/{$sectionId}/groups/2026-07", ['label' => 'Pwned'])
        ->assertStatus(404);
    actingAsUser($b)->getJson("/api/site/sections/{$sectionId}/groups")->assertStatus(404);

    expect(DB::table('site.section_groups')->where('section_id', $sectionId)->count())->toBe(0);
});

it('never traces another user\'s section', function () {
    // The trace names item headlines, so leaking it leaks their library.
    [$a, $b] = createTwoTenants();
    [, $sectionId] = seedPageWithSection($a->site->id);

    actingAsUser($b)->getJson("/api/site/sections/{$sectionId}/trace")->assertStatus(404);
});

it('never pins another user\'s item into its own section', function () {
    // The dangerous direction: B's own section, A's item. Publishing someone
    // else's content on your page is the failure this closes.
    [$a, $b] = createTwoTenants();
    [, $bSection] = seedPageWithSection($b->site->id);
    $aItem = seedContentItem($a->id, ['headline_cache' => 'Secret A']);

    actingAsUser($b)->putJson("/api/site/sections/{$bSection}/items/{$aItem}", ['state' => 'pinned'])
        ->assertStatus(404);

    expect(DB::table('site.section_items')->where('item_id', $aItem)->exists())->toBeFalse();
});

it('never reads or writes overrides on another user\'s item', function () {
    [$a, $b] = createTwoTenants();
    $aItem = seedContentItem($a->id);

    actingAsUser($b)->getJson("/api/content/items/{$aItem}/overrides")->assertStatus(404);
    actingAsUser($b)->putJson("/api/content/items/{$aItem}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Pwned',
    ])->assertStatus(404);
    actingAsUser($b)->deleteJson("/api/content/items/{$aItem}/overrides/f_text/headline")->assertStatus(404);

    expect(DB::table('content.manual_overrides')->where('item_id', $aItem)->exists())->toBeFalse();
});

it('never lists or rules on another user\'s duplicates', function () {
    [$a, $b] = createTwoTenants();
    $left = seedContentItem($a->id, ['first_seen_at' => now()->subDay()]);
    $right = seedContentItem($a->id);
    $candidateId = (string) Str::uuid();

    DB::table('content.identity_candidates')->insert([
        'id' => $candidateId,
        'user_id' => $a->id,
        'left_item_id' => $left,
        'right_item_id' => $right,
        'score' => 50,
        'evidence' => '{}',
        'created_at' => now(),
    ]);

    expect(actingAsUser($b)->getJson('/api/content/identity/candidates')->json('candidates'))->toBeEmpty();

    actingAsUser($b)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])
        ->assertStatus(404);
    actingAsUser($b)->postJson("/api/content/identity/candidates/{$candidateId}/dismiss")->assertStatus(404);

    // The merge must not have run: both of A's items still exist, and nothing
    // was written to the decision log.
    expect(DB::table('content.items')->whereIn('id', [$left, $right])->count())->toBe(2)
        ->and(DB::table('content.identity_decisions')->count())->toBe(0)
        ->and(DB::table('content.identity_candidates')->where('id', $candidateId)->value('dismissed_at'))->toBeNull();
});
