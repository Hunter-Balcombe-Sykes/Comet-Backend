<?php

use App\Site\Sections\SectionCandidates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #SCALE-1 / #SCALE-2 — the ORDER-BY pin.
//
// Both findings ask for the candidate query to be reshaped. The shape is the
// dangerous part: `content.f_published` and `content.f_occurrence` are both
// keyed (item_id, source_id), so an item carried by TWO sources has TWO facet
// rows, and the obvious "just join it" rewrite emits that item twice. The
// existing code uses correlated scalar subqueries for exactly this reason and
// says so in its own comments.
//
// So this file pins the OBSERVABLE OUTPUT of ruleCandidates() — order and
// cardinality — before any rewrite, and is the contract any rewrite has to
// keep. It deliberately asserts on the current behaviour rather than on what
// the behaviour ought to be: its job is to catch a change, not to argue.
//
// Helpers are file-local and uniquely prefixed on purpose. Global test helpers
// shared across files break under `--parallel`.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function candOrderSite(): array
{
    $pro = createTenant('cand-'.Str::lower(Str::random(6)));
    $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    $pageId = (string) Str::uuid();
    DB::table('site.pages')->insert([
        'id' => $pageId, 'site_id' => $siteId, 'key' => 'library', 'label' => 'Library',
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$pro->id, $siteId, $pageId];
}

function candOrderSource(string $userId, int $priority = 100): string
{
    $connectionId = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $userId, 'surface_key' => 'youtube.channel',
        'routing_class' => 'content', 'resource_id' => 'res-'.Str::random(8),
        'payload' => json_encode([]), 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection', 'connection_id' => $connectionId,
        'priority' => $priority, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** An item bound to one or more sources, optionally with a published date per source. */
function candOrderItem(string $userId, string $kind, string $headline, array $sourceIds, ?string $publishedFrom = null, ?string $firstSeenAt = null, ?string $forceId = null): string
{
    $id = $forceId ?? (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'headline_cache' => $headline, 'facets_cache' => '[]',
        'first_seen_at' => $firstSeenAt ?? now()->toDateTimeString(),
        'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($sourceIds as $sourceId) {
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'item_id' => $id,
            'coord' => 'coord-'.Str::random(10), 'kind' => $kind,
            'first_seen_at' => $firstSeenAt ?? now()->toDateTimeString(), 'last_seen_at' => now(),
        ]);

        if ($publishedFrom !== null) {
            DB::table('content.f_published')->insert([
                'item_id' => $id, 'source_id' => $sourceId,
                'published_from' => $publishedFrom, 'updated_at' => now(),
            ]);
        }
    }

    return $id;
}

function candOrderSection(string $siteId, string $pageId, string $orderBy, array $rule = [['op' => 'kind_is', 'values' => ['video']]]): object
{
    $id = (string) Str::uuid();
    DB::table('site.sections')->insert([
        'id' => $id, 'site_id' => $siteId, 'page_id' => $pageId,
        'kind' => 'collection', 'slot' => 'body', 'mode' => 'automatic',
        'render' => 'cards', 'order_by' => $orderBy, 'on_empty' => 'show_anyway',
        'min_items' => 0, 'stale_display' => 'inherit',
        'rule' => json_encode(['all' => $rule]),
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return (object) (array) DB::table('site.sections')->where('id', $id)->first();
}

function candOrderHeadlines(array $ids, array $byId): array
{
    return array_map(fn (string $id) => $byId[$id] ?? '??', $ids);
}

it('orders recency candidates dated-first, newest-first, ties broken on id descending', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $source = candOrderSource($userId);

    // Undated items carry first_seen_at only. X5: an undated item must never
    // outrank a dated one, however recently we happened to see it.
    $undatedSeenToday = candOrderItem($userId, 'video', 'Undated seen today', [$source], null, now()->toDateTimeString());
    $datedOld = candOrderItem($userId, 'video', 'Dated 2020', [$source], '2020-01-01 00:00:00');
    $datedNew = candOrderItem($userId, 'video', 'Dated 2026', [$source], '2026-01-01 00:00:00');

    $byId = [$undatedSeenToday => 'Undated seen today', $datedOld => 'Dated 2020', $datedNew => 'Dated 2026'];
    $got = app(SectionCandidates::class)->ruleCandidates(candOrderSection($siteId, $pageId, 'recency'), []);

    expect(candOrderHeadlines($got, $byId))->toBe(['Dated 2026', 'Dated 2020', 'Undated seen today']);
});

it('breaks an exact recency tie on item id descending, so the order is total', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $source = candOrderSource($userId);

    // A bulk first-ingest stamps one timestamp across a whole catalogue.
    // Without a total order NOTHING is "newer" and the ordering is whatever
    // the heap returned — the bug the id tiebreak exists to close.
    $lo = candOrderItem($userId, 'video', 'Tie A', [$source], '2026-05-05 00:00:00', null, '11111111-1111-4111-8111-111111111111');
    $hi = candOrderItem($userId, 'video', 'Tie B', [$source], '2026-05-05 00:00:00', null, '99999999-9999-4999-8999-999999999999');

    $byId = [$lo => 'Tie A', $hi => 'Tie B'];
    $got = app(SectionCandidates::class)->ruleCandidates(candOrderSection($siteId, $pageId, 'recency'), []);

    expect(candOrderHeadlines($got, $byId))->toBe(['Tie B', 'Tie A']);
});

it('emits ONE candidate row for an item carried by two sources — the invariant a join would break', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $spotify = candOrderSource($userId, 100);
    $apple = candOrderSource($userId, 90);

    // f_published is keyed (item_id, source_id), so this item has TWO facet
    // rows. Joining f_published instead of correlating it would emit this
    // item twice and the section would render a visible duplicate.
    $both = candOrderItem($userId, 'video', 'Carried by two', [$spotify, $apple], '2026-03-03 00:00:00');
    $one = candOrderItem($userId, 'video', 'Carried by one', [$spotify], '2026-02-02 00:00:00');

    expect(DB::table('content.f_published')->where('item_id', $both)->count())->toBe(2);

    $got = app(SectionCandidates::class)->ruleCandidates(candOrderSection($siteId, $pageId, 'recency'), []);

    expect($got)->toHaveCount(2);
    expect($got)->toBe([$both, $one]);
    expect(array_unique($got))->toHaveCount(2);
});

it('orders occurrence candidates soonest-first with undated last, still one row per item', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $a = candOrderSource($userId, 100);
    $b = candOrderSource($userId, 90);

    $soon = candOrderItem($userId, 'video', 'Soon', [$a]);
    $later = candOrderItem($userId, 'video', 'Later', [$a]);
    $twoSourced = candOrderItem($userId, 'video', 'Middle two-sourced', [$a, $b]);
    $undated = candOrderItem($userId, 'video', 'No date', [$a]);

    foreach ([[$soon, $a, '2026-09-01 00:00:00'], [$later, $a, '2026-12-01 00:00:00'],
        [$twoSourced, $a, '2026-10-01 00:00:00'], [$twoSourced, $b, '2026-11-01 00:00:00']] as [$item, $src, $when]) {
        DB::table('content.f_occurrence')->insert([
            'item_id' => $item, 'source_id' => $src, 'starts_at_utc' => $when, 'updated_at' => now(),
        ]);
    }

    $byId = [$soon => 'Soon', $later => 'Later', $twoSourced => 'Middle two-sourced', $undated => 'No date'];
    $got = app(SectionCandidates::class)->ruleCandidates(candOrderSection($siteId, $pageId, 'occurrence'), []);

    // MIN across the two-sourced item's occurrences, so it sorts on 1 Oct.
    expect(candOrderHeadlines($got, $byId))->toBe(['Soon', 'Middle two-sourced', 'Later', 'No date']);
    expect($got)->toHaveCount(4);
});

it('orders alphabetical candidates by headline, unaffected by dates', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $source = candOrderSource($userId);
    $z = candOrderItem($userId, 'video', 'Zebra', [$source], '2026-01-01 00:00:00');
    $a = candOrderItem($userId, 'video', 'Aardvark', [$source], '2020-01-01 00:00:00');

    $byId = [$z => 'Zebra', $a => 'Aardvark'];
    $got = app(SectionCandidates::class)->ruleCandidates(candOrderSection($siteId, $pageId, 'alphabetical'), []);

    expect(candOrderHeadlines($got, $byId))->toBe(['Aardvark', 'Zebra']);
});

it('excludes already-pinned items from the candidate set', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $source = candOrderSource($userId);
    $pinned = candOrderItem($userId, 'video', 'Pinned', [$source], '2026-06-06 00:00:00');
    $free = candOrderItem($userId, 'video', 'Free', [$source], '2026-05-05 00:00:00');

    $got = app(SectionCandidates::class)->ruleCandidates(candOrderSection($siteId, $pageId, 'recency'), [$pinned]);

    expect($got)->toBe([$free]);
});

// ── #FU-2 residual 2: LiveSourceScope's own two FK hops ─────────────────────
//
// ruleCandidates() calls LiveSourceScope::apply(), whose whereExists arm walks
// source_items.source_id -> content.sources -> sources.connection_id ->
// site.platform_connections to decide whether an item still has a live source.
// Neither hop was tenanted, so a mislink (a writer bug, an identity merge, a
// hand-run SQL fix) let a STRANGER'S live connection vouch for this owner's
// item. The symptom is not a leak — the subquery selects no column — it is a
// wrong verdict on the owner's own row: an item whose real connection the owner
// disconnected keeps rendering in an automatic section, and "disconnect = hide"
// silently stops meaning anything for that item.
//
// The pin is by COLUMN (lpc.user_id / lsrc.user_id = content.items.user_id),
// which is why this call site is covered without holding a user id: the join to
// site.sites above is what makes content.items.user_id the site owner.

/** A live youtube connection for $userId, with no content.sources row of its own. */
function candOrderConnection(string $userId): string
{
    $connectionId = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $userId, 'surface_key' => 'youtube.channel',
        'routing_class' => 'content', 'resource_id' => 'res-'.Str::random(8),
        'payload' => json_encode([]), 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $connectionId;
}

/** A content.sources row for $userId landing through $connectionId. */
function candOrderSourceOn(string $userId, string $connectionId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection', 'connection_id' => $connectionId,
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('does not let another owner connection keep a candidate live (FU-2)', function () {
    [$userId, $siteId, $pageId] = candOrderSite();
    $source = candOrderSource($userId);
    $item = candOrderItem($userId, 'video', 'A video', [$source], '2026-01-01 00:00:00');
    $section = candOrderSection($siteId, $pageId, 'recency');

    // Control: with the owner's own live connection the item IS a candidate.
    // Without this the assertion below could pass for any reason at all.
    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([$item]);

    // The mislink. content.sources.connection_id is UNIQUE, so the stranger's
    // connection carries no source row of its own to collide with.
    $stranger = createTenant('cfg-'.Str::lower(Str::random(6)));
    DB::table('content.sources')->where('id', $source)
        ->update(['connection_id' => candOrderConnection((string) $stranger->id)]);

    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([]);
});

it('does not let another owner source keep a candidate live (FU-2, first hop)', function () {
    // Deliberately shaped so ONLY the lsrc.user_id predicate can catch it: the
    // stranger's source lands through THIS OWNER'S live connection, so the
    // connection hop's own pin passes and votes the source live. A fixture
    // where both hops are foreign would pass with the source pin deleted.
    [$userId, $siteId, $pageId] = candOrderSite();
    $ownConnection = candOrderConnection($userId);
    $stranger = createTenant('cfs-'.Str::lower(Str::random(6)));
    $foreignSource = candOrderSourceOn((string) $stranger->id, $ownConnection);

    $item = candOrderItem($userId, 'video', 'A video', [$foreignSource], '2026-01-01 00:00:00');
    $section = candOrderSection($siteId, $pageId, 'recency');

    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([]);

    // Control: the same item, additionally landed by the owner's own live
    // source, is a candidate again — so the assertion above is about tenancy,
    // not about the fixture failing to produce a candidate at all.
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => candOrderSource($userId), 'item_id' => $item,
        'coord' => 'coord-'.Str::random(10), 'kind' => 'video',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([$item]);
});

it('keeps a manual-lane candidate whose source carries a NULL connection_id (FU-2 null-safety)', function () {
    // The trap the pin has to avoid: connection_id is NULLABLE and a
    // kind='manual' source always carries NULL, so the predicate lives in the
    // ON clause of the LEFT join. A bare where() there would collapse the join
    // to an INNER one and take every manual-lane item with it.
    [$userId, $siteId, $pageId] = candOrderSite();
    $manualSource = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $manualSource, 'user_id' => $userId, 'kind' => 'manual',
        'connection_id' => null, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $item = candOrderItem($userId, 'video', 'Hand-added', [$manualSource], '2026-01-01 00:00:00');
    $section = candOrderSection($siteId, $pageId, 'recency');

    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([$item]);
});

it('still drops a candidate whose own connection the owner disconnected', function () {
    // The dead-side control for the pair above: the verdict this scope exists
    // to deliver must still be delivered.
    [$userId, $siteId, $pageId] = candOrderSite();
    $source = candOrderSource($userId);
    $item = candOrderItem($userId, 'video', 'A video', [$source], '2026-01-01 00:00:00');
    $section = candOrderSection($siteId, $pageId, 'recency');

    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([$item]);

    $connectionId = (string) DB::table('content.sources')->where('id', $source)->value('connection_id');
    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => now()]);

    expect((new SectionCandidates)->ruleCandidates($section, []))->toBe([]);
});
