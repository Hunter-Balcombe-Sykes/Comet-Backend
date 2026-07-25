<?php

/**
 * Stale-metric fix: "section views" → "page views". AnalyticsQueryService::topPages()
 * folds analytics.section_views rows into the 16-page sitepage taxonomy
 * (SitepageId::SECTION_KEY_TO_PAGE) at the QUERY layer — stored section_key rows
 * are never rewritten. This pins the folding (many section_keys → one page),
 * the page-level counts (COUNT(*) views + correct COUNT(DISTINCT) unique viewers),
 * the config-driven page terminology, and the dropping of unmapped section_keys.
 */

use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSectionViewsTable();

    $this->userId = (string) Str::uuid();
    $handle = 'page-views-test-pro';
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $this->userId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Page Views Test Pro',
        'first_name' => 'Page Views Test Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->from = Carbon::now()->subDays(7);
    $this->to = Carbon::now();
    $this->service = app(AnalyticsQueryService::class);
});

function insertSectionView(string $userId, string $sectionKey, string $visitorId): void
{
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'section_key' => $sectionKey,
        'visitor_id' => $visitorId,
        'occurred_at' => now()->subHours(1)->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
    ]);
}

it('folds multiple section_keys into one page with correct views + unique viewers', function () {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $c = (string) Str::uuid();
    $d = (string) Str::uuid();

    // Shop page = 'shop-products' + 'shop' section keys folded together.
    insertSectionView($this->userId, 'shop-products', $a);
    insertSectionView($this->userId, 'shop-products', $b);
    insertSectionView($this->userId, 'shop', $a); // visitor A again → still 1 unique on shop page beyond {A,B}
    // Other pages.
    insertSectionView($this->userId, 'gallery', $c);
    insertSectionView($this->userId, 'instagram', $d); // instagram section_key folds to 'home'
    // Unmapped section_key must be dropped (no page).
    insertSectionView($this->userId, 'player-test', (string) Str::uuid());

    $pages = $this->service->topPages($this->userId, $this->from, $this->to)->all();
    $byKey = collect($pages)->keyBy('key');

    // player-test contributes no page → exactly 3 pages, no null bucket.
    expect($pages)->toHaveCount(3)
        ->and($byKey->keys()->all())->not->toContain('player-test', '');

    expect($byKey['shop']['title'])->toBe('Shop')
        ->and($byKey['shop']['views'])->toBe(3)              // shop-products x2 + shop x1
        ->and($byKey['shop']['unique_viewers'])->toBe(2);    // distinct visitors {A, B}

    expect($byKey['gallery']['title'])->toBe('Gallery')
        ->and($byKey['gallery']['views'])->toBe(1)
        ->and($byKey['home']['title'])->toBe('Home')          // 'instagram' section → Home page
        ->and($byKey['home']['views'])->toBe(1);
});

it('orders pages by views descending', function () {
    foreach (range(1, 5) as $i) {
        insertSectionView($this->userId, 'shop', (string) Str::uuid());
    }
    insertSectionView($this->userId, 'gallery', (string) Str::uuid());

    $pages = $this->service->topPages($this->userId, $this->from, $this->to)->all();

    expect($pages[0]['key'])->toBe('shop')
        ->and($pages[0]['views'])->toBe(5)
        ->and($pages[1]['key'])->toBe('gallery');
});

it('returns an empty collection when there are no section views', function () {
    expect($this->service->topPages($this->userId, $this->from, $this->to)->all())->toBe([]);
});
