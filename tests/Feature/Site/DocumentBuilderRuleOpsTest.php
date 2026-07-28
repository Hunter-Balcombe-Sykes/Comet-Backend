<?php

use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The complete rule DSL, one operator at a time (plan §7/§8): every op must
// select, reject, and negate against the TYPED tables — no cache columns, no
// silent widening. Each test builds a real document and asserts membership by
// headline, because the document is the artefact users see.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function ruleOpsSite(): array
{
    $pro = createTenant('ops-'.Str::lower(Str::random(6)));
    $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    $pageId = (string) Str::uuid();
    DB::table('site.pages')->insert([
        'id' => $pageId, 'site_id' => $siteId, 'key' => 'library', 'label' => 'Library',
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$pro->id, $siteId, $pageId];
}

function ruleOpsSection(string $siteId, string $pageId, array $rule, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('site.sections')->insert(array_merge([
        'id' => $id, 'site_id' => $siteId, 'page_id' => $pageId,
        'kind' => 'collection', 'slot' => 'body', 'mode' => 'automatic',
        'render' => 'cards', 'order_by' => 'recency', 'on_empty' => 'show_anyway',
        'min_items' => 0, 'stale_display' => 'inherit',
        'rule' => json_encode(['all' => $rule]),
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides));

    return $id;
}

function ruleOpsItem(string $userId, string $kind, string $headline): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function ruleOpsContentSource(string $userId, string $kind = 'connection'): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'connection_id' => $kind === 'connection' ? (string) Str::uuid() : null,
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function ruleOpsHeadlines(string $siteId): array
{
    (new DocumentBuilder)->build($siteId);
    $document = json_decode((string) DB::table('site.site_documents')->where('site_id', $siteId)->orderByDesc('version')->value('document'), true);

    $headlines = [];
    foreach ($document['pages'] as $page) {
        foreach ($page['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $headlines[] = $item['headline'];
            }
        }
    }
    sort($headlines);

    return $headlines;
}

// ── kind_is ─────────────────────────────────────────────────────────────────

it('kind_is selects the named kinds and its negation excludes them', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    ruleOpsItem($userId, 'video', 'A Video');
    ruleOpsItem($userId, 'track', 'A Track');

    ruleOpsSection($siteId, $pageId, [['op' => 'kind_is', 'values' => ['video']]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['A Video']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['video'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['A Track']);
});

// ── has_facet ───────────────────────────────────────────────────────────────

it('has_facet matches items carrying any named facet, via the typed tables', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $sourceId = ruleOpsContentSource($userId);
    $withEmbed = ruleOpsItem($userId, 'video', 'Embedded');
    ruleOpsItem($userId, 'video', 'Plain');

    DB::table('content.f_embed')->insert([
        'item_id' => $withEmbed, 'source_id' => $sourceId,
        'provider' => 'youtube', 'embed_key' => 'x', 'updated_at' => now(),
    ]);

    ruleOpsSection($siteId, $pageId, [['op' => 'has_facet', 'values' => ['f_embed']]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Embedded']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'has_facet', 'values' => ['f_embed'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Plain']);
});

it('has_facet with an off-map facet name matches nothing rather than everything', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    ruleOpsItem($userId, 'video', 'Anything');

    ruleOpsSection($siteId, $pageId, [['op' => 'has_facet', 'values' => ['pg_catalog.pg_tables']]]);

    expect(ruleOpsHeadlines($siteId))->toBe([]);
});

// ── from_source ─────────────────────────────────────────────────────────────

it('from_source matches by exact source id and by channel kind, ignoring retired contributions', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $connectionSource = ruleOpsContentSource($userId, 'connection');
    $manualSource = ruleOpsContentSource($userId, 'manual');

    $synced = ruleOpsItem($userId, 'product', 'Synced');
    $manual = ruleOpsItem($userId, 'product', 'Handmade');
    $retired = ruleOpsItem($userId, 'product', 'Retired');

    foreach ([[$connectionSource, $synced, null], [$manualSource, $manual, null], [$connectionSource, $retired, now()]] as [$source, $item, $removedAt]) {
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $source, 'coord' => 'c-'.$item,
            'kind' => 'product', 'item_id' => $item, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'removed_at' => $removedAt,
        ]);
    }

    ruleOpsSection($siteId, $pageId, [['op' => 'from_source', 'values' => [$connectionSource]]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Synced']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'from_source', 'values' => ['manual']]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Handmade']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'from_source', 'values' => ['manual'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Retired', 'Synced']);
});

// ── in_collection ───────────────────────────────────────────────────────────

it('in_collection matches by collection id and by label', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'label' => 'Mains',
        'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $inside = ruleOpsItem($userId, 'menu_item', 'Steak');
    ruleOpsItem($userId, 'menu_item', 'Loose Chip');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $inside, 'position' => 0,
    ]);

    ruleOpsSection($siteId, $pageId, [['op' => 'in_collection', 'values' => [$collectionId]]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Steak']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'in_collection', 'values' => ['Mains']]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Steak']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'in_collection', 'values' => ['Mains'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Loose Chip']);
});

// ── tagged_with ─────────────────────────────────────────────────────────────

it('tagged_with matches items carrying any of the named tags', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $sourceId = ruleOpsContentSource($userId);
    $tagged = ruleOpsItem($userId, 'release', 'Psych Album');
    ruleOpsItem($userId, 'release', 'Untagged Album');

    DB::table('content.item_tags')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $tagged, 'source_id' => $sourceId,
        'tag' => 'Psychedelic', 'tag_type' => 'genre',
    ]);

    ruleOpsSection($siteId, $pageId, [['op' => 'tagged_with', 'values' => ['Psychedelic', 'Ambient']]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Psych Album']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'tagged_with', 'values' => ['Psychedelic'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Untagged Album']);
});

// ── has_action ──────────────────────────────────────────────────────────────

it('has_action matches items carrying any of the named intents', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $sourceId = ruleOpsContentSource($userId);
    $bookable = ruleOpsItem($userId, 'service', 'Bookable Cut');
    ruleOpsItem($userId, 'service', 'Display Only');

    DB::table('content.f_action')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $bookable, 'source_id' => $sourceId,
        'intent' => 'book', 'url' => 'https://book.example', 'position' => 0,
    ]);

    ruleOpsSection($siteId, $pageId, [['op' => 'has_action', 'values' => ['book', 'reserve']]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Bookable Cut']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'has_action', 'values' => ['book'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Display Only']);
});

// ── published_within ────────────────────────────────────────────────────────

it('published_within prefers the published facet and falls back to first-seen only for undated items', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $sourceId = ruleOpsContentSource($userId);

    // Freshly seen but PUBLISHED years ago: the facet must win over first_seen.
    $oldRelease = ruleOpsItem($userId, 'release', 'Back Catalogue');
    DB::table('content.f_published')->insert([
        'item_id' => $oldRelease, 'source_id' => $sourceId,
        'published_from' => now()->subYears(3)->toDateTimeString(), 'updated_at' => now(),
    ]);

    $fresh = ruleOpsItem($userId, 'release', 'New Drop');
    DB::table('content.f_published')->insert([
        'item_id' => $fresh, 'source_id' => $sourceId,
        'published_from' => now()->subDays(2)->toDateTimeString(), 'updated_at' => now(),
    ]);

    // No published facet at all: first_seen (now) is the only honest date.
    ruleOpsItem($userId, 'release', 'Undated');

    ruleOpsSection($siteId, $pageId, [['op' => 'published_within', 'values' => ['30']]]);
    expect(ruleOpsHeadlines($siteId))->toBe(['New Drop', 'Undated']);

    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'published_within', 'values' => ['30'], 'not' => true]]]),
    ]);
    expect(ruleOpsHeadlines($siteId))->toBe(['Back Catalogue']);
});

// ── Composition ─────────────────────────────────────────────────────────────

it('ANDs multiple predicates, and an unknown op is ignored rather than emptying the section', function () {
    [$userId, $siteId, $pageId] = ruleOpsSite();
    $sourceId = ruleOpsContentSource($userId);
    $match = ruleOpsItem($userId, 'video', 'Embedded Video');
    ruleOpsItem($userId, 'video', 'Plain Video');
    ruleOpsItem($userId, 'track', 'Embedded Track');

    foreach ([$match, DB::table('content.items')->where('headline_cache', 'Embedded Track')->value('id')] as $itemId) {
        DB::table('content.f_embed')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'provider' => 'youtube', 'embed_key' => 'k-'.$itemId, 'updated_at' => now(),
        ]);
    }

    ruleOpsSection($siteId, $pageId, [
        ['op' => 'kind_is', 'values' => ['video']],
        ['op' => 'has_facet', 'values' => ['f_embed']],
        ['op' => 'sort_by_vibes', 'values' => ['immaculate']],
    ]);

    expect(ruleOpsHeadlines($siteId))->toBe(['Embedded Video']);
});
