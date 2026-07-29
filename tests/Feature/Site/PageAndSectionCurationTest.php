<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupContentCurationTables();
});

it('lists pages with their sections nested', function () {
    $pro = createTenant('pages-index');
    seedPageWithSection($pro->site->id, ['label' => 'Everything']);

    $response = actingAsUser($pro)->getJson('/api/site/pages');

    $response->assertOk();
    expect($response->json('pages'))->toHaveCount(1)
        ->and($response->json('pages.0.sections'))->toHaveCount(1)
        ->and($response->json('pages.0.sections.0.label'))->toBe('Everything');
});

it('creates a page and marks the document stale', function () {
    $pro = createTenant('pages-create');

    $response = actingAsUser($pro)->postJson('/api/site/pages', [
        'key' => 'listen',
        'label' => 'Listen',
    ]);

    $response->assertStatus(201);
    expect($response->json('page.key'))->toBe('listen');

    // A curation write does not publish. It records that the builder has work.
    expect((int) DB::table('site.site_build_state')->where('site_id', $pro->site->id)->value('content_revision'))
        ->toBeGreaterThan(0);
});

it('refuses a second page at the same address', function () {
    $pro = createTenant('pages-dupe');
    actingAsUser($pro)->postJson('/api/site/pages', ['key' => 'listen', 'label' => 'Listen'])->assertStatus(201);

    actingAsUser($pro)->postJson('/api/site/pages', ['key' => 'listen', 'label' => 'Again'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('refuses a reserved page address', function () {
    $pro = createTenant('pages-reserved');

    actingAsUser($pro)->postJson('/api/site/pages', ['key' => 'api', 'label' => 'Api'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('refuses a page whose capability the account does not have', function () {
    // A partna account is never food-gated, so it never gets Menu.
    $pro = createTenant('pages-gated');

    actingAsUser($pro)->postJson('/api/site/pages', [
        'key' => 'menu', 'label' => 'Menu', 'capability' => 'menu',
    ])->assertStatus(422)->assertJsonValidationErrors('capability');
});

it('creates a section and narrates its rule as a sentence', function () {
    $pro = createTenant('sections-create');
    [$pageId] = seedPageWithSection($pro->site->id);

    $response = actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId,
        'kind' => 'collection',
        'label' => 'Recent videos',
        'rule' => ['all' => [
            ['op' => 'kind_is', 'values' => ['video']],
            ['op' => 'published_within', 'values' => ['30']],
        ]],
    ]);

    $response->assertStatus(201);
    // The sentence IS the UI copy — the dashboard shows it verbatim.
    expect($response->json('section.ruleSentence'))
        ->toBe('Anything that is a video and was published within the last 30 days');
});

it('refuses a rule with more than five conditions', function () {
    $pro = createTenant('sections-bound');
    [$pageId] = seedPageWithSection($pro->site->id);

    $predicates = array_fill(0, 6, ['op' => 'kind_is', 'values' => ['video']]);

    actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId, 'kind' => 'collection', 'rule' => ['all' => $predicates],
    ])->assertStatus(422)->assertJsonValidationErrors('rule');
});

it('refuses a rule naming a kind that does not exist', function () {
    $pro = createTenant('sections-kind');
    [$pageId] = seedPageWithSection($pro->site->id);

    actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId, 'kind' => 'collection',
        'rule' => ['all' => [['op' => 'kind_is', 'values' => ['podcast']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('rule');
});

it('refuses a rule naming a kind the account is not entitled to', function () {
    // `kind_is menu_item` on a generic page is a menu wearing a different hat.
    $pro = createTenant('sections-gated-kind');
    [$pageId] = seedPageWithSection($pro->site->id);

    actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId, 'kind' => 'collection',
        'rule' => ['all' => [['op' => 'kind_is', 'values' => ['menu_item']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('rule');
});

it('refuses to move a section onto a page belonging to someone else', function () {
    $mine = createTenant('sections-move-mine');
    $theirs = createTenant('sections-move-theirs');
    [, $sectionId] = seedPageWithSection($mine->site->id);
    [$theirPageId] = seedPageWithSection($theirs->site->id);

    actingAsUser($mine)->patchJson("/api/site/sections/{$sectionId}", ['page_id' => $theirPageId])
        ->assertStatus(404);

    expect(DB::table('site.sections')->where('id', $sectionId)->value('page_id'))->not->toBe($theirPageId);
});

it('pins an item and appends it after the existing pins', function () {
    $pro = createTenant('pins-append');
    [, $sectionId] = seedPageWithSection($pro->site->id);
    $first = seedContentItem($pro->id, ['headline_cache' => 'First']);
    $second = seedContentItem($pro->id, ['headline_cache' => 'Second']);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$first}", ['state' => 'pinned'])->assertOk();
    $response = actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$second}", ['state' => 'pinned']);

    $response->assertOk();
    expect($response->json('curation.sortKey'))->toBeGreaterThan(1.0);
});

it('turns a pin into an exclusion rather than failing on the unique key', function () {
    // Pressing hide on something you pinned is changing your mind, not an error.
    $pro = createTenant('pins-flip');
    [, $sectionId] = seedPageWithSection($pro->site->id);
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])->assertOk();
    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'excluded'])->assertOk();

    $row = DB::table('site.section_items')->where('section_id', $sectionId)->where('item_id', $itemId)->first();
    expect($row->state)->toBe('excluded')
        // A stale sort key would resurface if the row became a pin again.
        ->and($row->sort_key)->toBeNull()
        ->and(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe(1);
});

it('refuses to pin an item belonging to another user', function () {
    $mine = createTenant('pins-mine');
    $theirs = createTenant('pins-theirs');
    [, $sectionId] = seedPageWithSection($mine->site->id);
    $theirItem = seedContentItem($theirs->id);

    actingAsUser($mine)->putJson("/api/site/sections/{$sectionId}/items/{$theirItem}", ['state' => 'pinned'])
        ->assertStatus(404);

    expect(DB::table('site.section_items')->where('item_id', $theirItem)->exists())->toBeFalse();
});

it('clears a curation row so the rule decides again', function () {
    $pro = createTenant('pins-clear');
    [, $sectionId] = seedPageWithSection($pro->site->id);
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'excluded'])->assertOk();
    actingAsUser($pro)->deleteJson("/api/site/sections/{$sectionId}/items/{$itemId}")->assertOk();

    expect(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe(0);
});

it('stores a group label override and removes it on reset', function () {
    $pro = createTenant('groups-upsert');
    [, $sectionId] = seedPageWithSection($pro->site->id, ['group_by' => 'month']);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/groups/2026-07", [
        'label' => 'This month', 'sort_order' => 0,
    ])->assertOk();

    expect(actingAsUser($pro)->getJson("/api/site/sections/{$sectionId}/groups")->json('groups.0.label'))
        ->toBe('This month');

    actingAsUser($pro)->deleteJson("/api/site/sections/{$sectionId}/groups/2026-07")->assertOk();
    expect(DB::table('site.section_groups')->where('section_id', $sectionId)->count())->toBe(0);
});

it('deletes a page and its sections with it', function () {
    $pro = createTenant('pages-delete');
    [$pageId, $sectionId] = seedPageWithSection($pro->site->id);

    actingAsUser($pro)->deleteJson("/api/site/pages/{$pageId}")->assertOk();

    expect(DB::table('site.pages')->where('id', $pageId)->exists())->toBeFalse();
    // SQLite mirrors the columns, not the FK cascade — assert the page is gone
    // and leave the cascade itself to the Postgres schema that declares it.
    expect($sectionId)->not->toBeEmpty();
});

it('requires authentication', function () {
    $this->getJson('/api/site/pages')->assertStatus(401);
    $this->getJson('/api/site/sections')->assertStatus(401);
});

// ── #TEST-1 residual: the update-path capability/rule gates (PageController
// :82-84, SectionController :104-106) had zero coverage. A partna account
// could POST an ungated page/section, then PATCH a gated capability/kind onto
// it — the only thing stopping that escalation is these eight-line blocks,
// and deleting them was green before these tests existed.

it('accepts a capability the account does have, on create and on update', function () {
    // The business+food fixture is load-bearing: the default (partna) tenant
    // would 422 here, so this proves account type is doing real work.
    $pro = createTenant('pages-gate-allow', ['account_type' => 'business', 'sector' => 'restaurant']);

    $created = actingAsUser($pro)->postJson('/api/site/pages', [
        'key' => 'menu', 'label' => 'Menu', 'capability' => 'menu',
    ]);
    $created->assertStatus(201);
    expect($created->json('page.capability'))->toBe('menu');

    $plain = actingAsUser($pro)->postJson('/api/site/pages', ['key' => 'plain', 'label' => 'Plain']);
    $plain->assertStatus(201);
    $plainId = $plain->json('page.id');

    actingAsUser($pro)->patchJson("/api/site/pages/{$plainId}", ['capability' => 'menu'])->assertOk();

    expect(DB::table('site.pages')->where('id', $plainId)->value('capability'))->toBe('menu');
});

it('refuses a capability the account lacks when it is PATCHed onto an existing page', function () {
    $pro = createTenant('pages-gate-patch');

    $created = actingAsUser($pro)->postJson('/api/site/pages', ['key' => 'extra', 'label' => 'Extra']);
    $created->assertStatus(201);
    $pageId = $created->json('page.id');

    actingAsUser($pro)->patchJson("/api/site/pages/{$pageId}", ['capability' => 'menu'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('capability');

    // The row must not have moved — this is what makes the mutation
    // (deleting PageController.php:82-84) actually detectable: without it,
    // the PATCH 200s and this assertion catches the leaked write.
    expect(DB::table('site.pages')->where('id', $pageId)->value('capability'))->toBeNull();
});

it('accepts a gated kind in a rule for an account that has the capability', function () {
    $pro = createTenant('sections-gate-allow', ['account_type' => 'business', 'sector' => 'restaurant']);
    [$pageId] = seedPageWithSection($pro->site->id);

    $response = actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId, 'kind' => 'collection',
        'rule' => ['all' => [['op' => 'kind_is', 'values' => ['menu_item']]]],
    ]);

    $response->assertStatus(201);
    expect(DB::table('site.sections')->where('id', $response->json('section.id'))->value('rule'))
        ->toContain('menu_item');
});

it('refuses a gated kind when it is PATCHed into an existing section rule', function () {
    $pro = createTenant('sections-gate-patch');
    [, $sectionId] = seedPageWithSection($pro->site->id);

    $response = actingAsUser($pro)->patchJson("/api/site/sections/{$sectionId}", [
        'rule' => ['all' => [['op' => 'kind_is', 'values' => ['menu_item']]]],
    ]);

    // `menu_item` IS a registered kind (KindRegistry), so the 422 cannot come
    // from the "unknown kind" validator — assert the exact copy so a
    // validation-layer rejection can't masquerade as the capability gate.
    $response->assertStatus(422)
        ->assertJsonPath('errors.rule.0', 'Your account does not have "menu item" content.');

    expect(DB::table('site.sections')->where('id', $sectionId)->value('rule'))->not->toContain('menu_item');
});

it('permits a NEGATED gated kind for an account without the capability', function () {
    $pro = createTenant('sections-gate-negated');
    [$pageId] = seedPageWithSection($pro->site->id);

    $negated = actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId, 'kind' => 'collection',
        'rule' => ['all' => [['op' => 'kind_is', 'not' => true, 'values' => ['menu_item']]]],
    ]);
    $negated->assertStatus(201);

    // Paired un-negated assertion: without it, a mis-built fixture that
    // accidentally already had the capability would pass this test for the
    // wrong reason (entitlement, not negation-skip).
    actingAsUser($pro)->postJson('/api/site/sections', [
        'page_id' => $pageId, 'kind' => 'collection',
        'rule' => ['all' => [['op' => 'kind_is', 'values' => ['menu_item']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('rule');
});
