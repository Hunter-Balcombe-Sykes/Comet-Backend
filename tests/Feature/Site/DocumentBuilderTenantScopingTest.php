<?php

use App\Models\Core\Site\Section;
use App\Services\Content\SectionTracer;
use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Full content schema: SectionCandidates now reads source_items /
    // f_published for every section (disconnect = hide, recency by published
    // date — W2 2026-08-18), exactly as the real DB always has them.
    setupContentTables();
});

/**
 * Pin a row directly, bypassing SectionItemController::findItem()'s ownership
 * check. That check is the reason the API cannot create one of these today —
 * but it is not the only writer. ItemMerger repoints pins by item id, a manual
 * SQL fix writes no ownership check at all, and CLAUDE.md records that any
 * write path bypassing Eloquent is on its own. The builder renders a PUBLIC
 * page, so it is the last line of defence and must scope for itself.
 */
function pinItemDirectly(string $sectionId, string $itemId): void
{
    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(),
        'section_id' => $sectionId,
        'item_id' => $itemId,
        'state' => 'pinned',
        'sort_key' => 1.0,
        'created_at' => now(),
    ]);
}

/** Every item headline the built document rendered, across all pages/sections. */
function renderedHeadlines(string $siteId): array
{
    $document = json_decode(
        (string) DB::table('site.site_documents')
            ->where('site_id', $siteId)
            ->orderByDesc('version')
            ->value('document'),
        true,
    ) ?: [];

    $headlines = [];
    foreach ($document['pages'] ?? [] as $page) {
        foreach ($page['sections'] ?? [] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $headlines[] = $item['headline'] ?? null;
            }
        }
    }

    return $headlines;
}

it('never renders another user\'s pinned item on a hand-picked section', function () {
    // hand_picked consults no rule at all, so the ownership join in
    // ruleCandidates() never runs — the pin IS the entire membership list.
    // This is the path with no scoping whatsoever before the fix.
    [$ownerId, $siteId] = buildTestSite();
    [$intruderId] = buildTestSite();

    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId, ['mode' => 'hand_picked']);

    $mine = addItem($ownerId, 'video', 'My Video');
    $theirs = addItem($intruderId, 'video', 'Their Private Video');

    pinItemDirectly($sectionId, $mine);
    pinItemDirectly($sectionId, $theirs);

    (new DocumentBuilder)->build($siteId);

    expect(renderedHeadlines($siteId))
        ->toContain('My Video')
        ->not->toContain('Their Private Video');
});

it('never renders another user\'s pinned item on an automatic section', function () {
    // Automatic sections merge pins in FRONT of rule candidates. The rule half
    // is scoped by ruleCandidates()' join; the pinned half was not.
    [$ownerId, $siteId] = buildTestSite();
    [$intruderId] = buildTestSite();

    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId);

    addItem($ownerId, 'video', 'My Video');
    $theirs = addItem($intruderId, 'video', 'Their Private Video');

    pinItemDirectly($sectionId, $theirs);

    (new DocumentBuilder)->build($siteId);

    expect(renderedHeadlines($siteId))
        ->toContain('My Video')
        ->not->toContain('Their Private Video');
});

it('does not let a foreign pin consume a limited section\'s slots', function () {
    // The limit is applied while iterating candidates. A foreign pin that is
    // dropped must not still have spent a slot, or the owner silently loses
    // one of their own items off the bottom of the section.
    [$ownerId, $siteId] = buildTestSite();
    [$intruderId] = buildTestSite();

    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId, ['limit_n' => 2]);

    addItem($ownerId, 'video', 'Mine One');
    addItem($ownerId, 'video', 'Mine Two');
    $theirs = addItem($intruderId, 'video', 'Their Private Video');

    pinItemDirectly($sectionId, $theirs);

    (new DocumentBuilder)->build($siteId);

    expect(renderedHeadlines($siteId))
        ->not->toContain('Their Private Video')
        ->toHaveCount(2);
});

it('does not leak another user\'s item through the section trace', function () {
    // SectionTracer mirrors the builder deliberately — "a diagnostic that
    // disagrees with the live page is worse than no diagnostic". It shares the
    // same unscoped itemsById(), so it leaked the foreign headline too.
    [$ownerId, $siteId] = buildTestSite();
    [$intruderId] = buildTestSite();

    $pageId = addPage($siteId, 'watch', 'Watch');
    $sectionId = addSection($siteId, $pageId, ['mode' => 'hand_picked']);

    addItem($ownerId, 'video', 'My Video');
    $theirs = addItem($intruderId, 'video', 'Their Private Video');
    pinItemDirectly($sectionId, $theirs);

    $trace = (new SectionTracer)->trace(Section::query()->findOrFail($sectionId));

    expect(json_encode($trace))->not->toContain('Their Private Video');
});
