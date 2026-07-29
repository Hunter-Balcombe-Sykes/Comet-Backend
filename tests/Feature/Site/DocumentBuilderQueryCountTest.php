<?php

use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Pins SCALE-9's fix: resolveSection()/itemPayload() must issue one keyed
 * `content.items` fetch per section, not one per candidate item (plan
 * §unit-12: DocumentBuilder was supposed to mirror SectionTracer::itemsById()
 * but drifted into a per-item query). Feature lane, not tests/Postgres/ —
 * this needs a query-count assertion, not Postgres transaction-abort
 * semantics; tests/Feature/Site/BatchCheckQueryCountTest.php is the in-repo
 * precedent for this style of test.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionsTables();
});

function countItemsQueries(array $queries): int
{
    return count(array_filter($queries, fn (string $sql) => str_contains($sql, 'from "content"."items"')));
}

it('issues exactly one content.items query per section, not one per candidate item', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId, ['limit_n' => null]);

    for ($i = 0; $i < 30; $i++) {
        addItem($userId, 'video', "Item {$i}");
    }

    $queries = [];
    DB::connection('pgsql')->listen(function ($q) use (&$queries) {
        $queries[] = strtolower(trim((string) $q->sql));
    });

    (new DocumentBuilder)->build($siteId);

    // One ruleCandidates() query + one itemsById() batch fetch = 2. Before
    // the fix this was 1 (ruleCandidates) + 30 (one itemPayload() per item)
    // = 31.
    expect(countItemsQueries($queries))->toBe(2);
    expect(count($queries))->toBeLessThan(12);
});

it('holds at 4 content.items queries across two sections, proving the count scales with sections not items', function () {
    [$userId, $siteId] = buildTestSite();
    $pageId = addPage($siteId, 'watch', 'Watch');
    addSection($siteId, $pageId, ['limit_n' => null, 'sort_order' => 0]);
    addSection($siteId, $pageId, ['limit_n' => null, 'sort_order' => 1]);

    for ($i = 0; $i < 30; $i++) {
        addItem($userId, 'video', "Item {$i}");
    }

    $queries = [];
    DB::connection('pgsql')->listen(function ($q) use (&$queries) {
        $queries[] = strtolower(trim((string) $q->sql));
    });

    (new DocumentBuilder)->build($siteId);

    // 2 sections × (1 ruleCandidates + 1 itemsById) = 4. Before the fix this
    // was 2 sections × (1 ruleCandidates + 30 itemPayload) = 62.
    expect(countItemsQueries($queries))->toBe(4);
});
