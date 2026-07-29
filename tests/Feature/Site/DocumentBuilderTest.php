<?php

use App\Site\Documents\BuildState;
use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionsTables();
});

// buildTestSite()/addPage()/addSection()/addItem() live in tests/Pest.php —
// shared with DocumentBuilderQueryCountTest.php, which must not redeclare
// them regardless of which file PHPUnit loads first.

// ── The build protocol ──────────────────────────────────────────────────────

it('builds a document and marks the site caught up', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId);
    addItem($userId, 'video', 'A Video');
    BuildState::bump($siteId);

    $result = (new DocumentBuilder)->build($siteId);

    expect($result['status'])->toBe('built')
        ->and($result['version'])->toBe(1)
        ->and(BuildState::isStale($siteId))->toBeFalse();
});

it('writes no new version when nothing actually changed', function () {
    // The property that makes a rebuild storm harmless: identical output is
    // not a new version, so nothing is purged and nothing is re-delivered.
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId);
    addItem($userId, 'video', 'A Video');

    $first = (new DocumentBuilder)->build($siteId);
    BuildState::bump($siteId);
    $second = (new DocumentBuilder)->build($siteId);

    expect($first['status'])->toBe('built')
        ->and($second['status'])->toBe('unchanged')
        ->and($second['version'])->toBe($first['version'])
        ->and(DB::table('site.site_documents')->where('site_id', $siteId)->count())->toBe(1);
});

it('writes a new version when the content really changed', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId);
    addItem($userId, 'video', 'First');

    (new DocumentBuilder)->build($siteId);
    addItem($userId, 'video', 'Second');
    $second = (new DocumentBuilder)->build($siteId);

    expect($second['status'])->toBe('built')
        ->and($second['version'])->toBe(2);
});

it('refuses to commit a build whose revision has already moved on', function () {
    // The CAS primitive itself: a builder that read revision N cannot mark the
    // site current if content reached N+1 while it worked. Committing anyway
    // would publish a document that was already out of date when written, and
    // — worse — would mark the site clean so nothing rebuilt it.
    [, $siteId] = buildTestSite();
    BuildState::read($siteId);
    BuildState::bump($siteId);   // a build starts here, reading revision 1
    BuildState::bump($siteId);   // content changes again mid-build

    expect(BuildState::commit($siteId, 1))->toBeFalse()
        ->and(BuildState::isStale($siteId))->toBeTrue()
        ->and(BuildState::commit($siteId, 2))->toBeTrue()
        ->and(BuildState::isStale($siteId))->toBeFalse();
});

it('counts every concurrent bump rather than collapsing them', function () {
    [, $siteId] = buildTestSite();

    BuildState::bump($siteId);
    BuildState::bump($siteId);
    BuildState::bump($siteId);

    expect(BuildState::read($siteId)['content_revision'])->toBe(3);
});

// ── Composition ─────────────────────────────────────────────────────────────

it('puts one navigation entry per page, never one per section', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'menu', 'Menu');
    addSection($siteId, $pageId, ['label' => 'Starters', 'sort_order' => 0]);
    addSection($siteId, $pageId, ['label' => 'Mains', 'sort_order' => 1]);
    addSection($siteId, $pageId, ['label' => 'Desserts', 'sort_order' => 2]);
    addItem($userId, 'video', 'Something');

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    // Three sections, one nav row — the sidebar-explosion guard.
    expect($document['navigation'])->toHaveCount(1)
        ->and($document['navigation'][0]['label'])->toBe('Menu')
        ->and($document['pages'][0]['sections'])->toHaveCount(3);
});

it('hides an empty section when the user asked it to hide', function () {
    [, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId, ['on_empty' => 'hide']);

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    expect($document['pages'][0]['sections'])->toBeEmpty()
        ->and($document['navigation'])->toBeEmpty()
        ->and($document['warnings'][0]['code'])->toBe('empty_page');
});

it('never includes an item the user excluded from a section', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId);
    $keep = addItem($userId, 'video', 'Keep');
    $drop = addItem($userId, 'video', 'Drop');

    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $sectionId,
        'item_id' => $drop, 'state' => 'excluded', 'created_at' => now(),
    ]);

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    $headlines = array_column($document['pages'][0]['sections'][0]['items'], 'headline');
    expect($headlines)->toContain('Keep')->not->toContain('Drop');
});

it('puts pinned items first, in the order the user pinned them', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId);
    addItem($userId, 'video', 'Ordinary');
    $pinned = addItem($userId, 'video', 'Pinned');

    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $sectionId,
        'item_id' => $pinned, 'state' => 'pinned', 'sort_key' => 1.0, 'created_at' => now(),
    ]);

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    expect($document['pages'][0]['sections'][0]['items'][0]['headline'])->toBe('Pinned');
});

it('gives a hand-picked section exactly its pins and nothing else', function () {
    // A user who curated by hand must never get surprise additions.
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId, ['mode' => 'hand_picked']);
    addItem($userId, 'video', 'Not Chosen');
    $chosen = addItem($userId, 'video', 'Chosen');

    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $sectionId,
        'item_id' => $chosen, 'state' => 'pinned', 'sort_key' => 1.0, 'created_at' => now(),
    ]);

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    $headlines = array_column($document['pages'][0]['sections'][0]['items'], 'headline');
    expect($headlines)->toBe(['Chosen']);
});

it('respects a section limit', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId, ['limit_n' => 2]);
    foreach (['A', 'B', 'C', 'D'] as $title) {
        addItem($userId, 'video', $title);
    }

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    expect($document['pages'][0]['sections'][0]['items'])->toHaveCount(2);
});

it('never includes an item the user deleted outright', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId);
    addItem($userId, 'video', 'Visible');
    $removed = addItem($userId, 'video', 'Deleted');
    DB::table('content.items')->where('id', $removed)->update(['removed_at' => now()]);

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    $headlines = array_column($document['pages'][0]['sections'][0]['items'], 'headline');
    expect($headlines)->toBe(['Visible']);
});

it('excludes an item whose kind the rule does not ask for', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId, ['rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video']]]])]);
    addItem($userId, 'video', 'A Video');
    addItem($userId, 'track', 'A Track');

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    $headlines = array_column($document['pages'][0]['sections'][0]['items'], 'headline');
    expect($headlines)->toBe(['A Video']);
});

it('skips a pinned item that no longer exists', function () {
    // A section_items row can pin an id with no matching content.items row
    // (e.g. a hard-deleted item never cleaned up). That must be skipped
    // silently, not fatal — and the rest of the section still renders.
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId);
    addItem($userId, 'video', 'Still Here');

    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $sectionId,
        'item_id' => (string) Str::uuid(), 'state' => 'pinned', 'sort_key' => 1.0, 'created_at' => now(),
    ]);

    $result = (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    $headlines = array_column($document['pages'][0]['sections'][0]['items'], 'headline');
    expect($result['status'])->toBe('built')
        ->and($headlines)->toBe(['Still Here']);
});

it('does not let a deleted pinned item consume a limit slot', function () {
    // limit_n counts ADMITTED items, not candidates: a pinned-then-deleted
    // item must be skipped before the limit check, not counted against it.
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId, ['limit_n' => 2]);
    addItem($userId, 'video', 'A');
    addItem($userId, 'video', 'B');
    addItem($userId, 'video', 'C');
    $removedPin = addItem($userId, 'video', 'Deleted Pin');
    DB::table('content.items')->where('id', $removedPin)->update(['removed_at' => now()]);

    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $sectionId,
        'item_id' => $removedPin, 'state' => 'pinned', 'sort_key' => 1.0, 'created_at' => now(),
    ]);

    (new DocumentBuilder)->build($siteId);
    $document = json_decode(DB::table('site.site_documents')->where('site_id', $siteId)->value('document'), true);

    $headlines = array_column($document['pages'][0]['sections'][0]['items'], 'headline');
    expect($headlines)->toHaveCount(2)
        ->and($headlines)->not->toContain('Deleted Pin');
});
