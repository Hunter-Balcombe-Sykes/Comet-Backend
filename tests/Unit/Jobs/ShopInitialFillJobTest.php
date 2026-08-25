<?php

use App\Jobs\Platforms\ShopInitialFillJob;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// #JOB-1 — the fill/auto-select tail ShopBrandConnectJob dispatches this job
// FOR (SCALE-4). No DB setup: all three handle() dependencies are
// container-injected mocks, and StoreRecord is a plain readonly value object
// — nothing here needs setupContentTables().

function sifjStore(array $overrides = []): StoreRecord
{
    return (new StoreRecord(
        externalRef: 'brand-1',
        provider: 'shopify',
        collectionId: 'col-1',
        userId: 'user-1',
    ))->with($overrides);
}

it('defines the required queue-hygiene properties', function () {
    $job = new ShopInitialFillJob('col-1');

    expect($job->tries)->toBe(2)
        ->and($job->backoff)->toBe([10])
        ->and($job->timeout)->toBe(240)
        ->and($job->uniqueFor)->toBe(300)
        // Must exceed the job's own $timeout — same HorizonQueueCoverageTest
        // invariant every unique job in this codebase pins. Stops a future
        // "the fill is fast now, drop the timeout" from silently reintroducing
        // SCALE-4 (the 75s ceiling this job exists to escape).
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueId())->toBe('shop-store-fill:col-1')
        ->and($job->queue)->toBe(config('partna.queues.platform_connect'));
});

it('reports AND logs both the fill failure and the auto-select failure independently', function () {
    Exceptions::fake();
    Log::spy();

    $this->mock(ShopConnections::class, fn ($m) => $m->shouldReceive('storeByCollection')
        ->with('col-1')->once()->andReturn(sifjStore()));
    $this->mock(ShopCatalog::class, fn ($m) => $m->shouldReceive('syncLatest')
        ->once()->andThrow(new RuntimeException('fill boom')));
    $this->mock(ShopAutoSelector::class, fn ($m) => $m->shouldReceive('selectInitial')
        ->once()->andThrow(new RuntimeException('select boom')));

    $job = new ShopInitialFillJob('col-1');
    app()->call([$job, 'handle']);

    // Load-bearing: also catches a "fix" that reports the same exception
    // twice, or that converts a catch into a rethrow (which would report via
    // failed() instead, and never reach this line at all under Bus/queue sync).
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'fill boom');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'select boom');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'shop.initial_fill_job.fill_failed'
            && ($context['collection_id'] ?? null) === 'col-1'
            && ($context['user_id'] ?? null) === 'user-1'
            && ($context['error'] ?? null) === 'fill boom')
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'shop.initial_fill_job.auto_select_failed'
            && ($context['collection_id'] ?? null) === 'col-1'
            && ($context['user_id'] ?? null) === 'user-1'
            && ($context['error'] ?? null) === 'select boom')
        ->once();
});

// Over-correction guard — a "fix" that converts either catch into a
// $this->fail()/rethrow makes this red: a fill failure would then skip the
// select entirely instead of still attempting it.
it('stays best-effort: a fill failure does not throw, and the auto-select still runs', function () {
    Exceptions::fake();

    $this->mock(ShopConnections::class, fn ($m) => $m->shouldReceive('storeByCollection')
        ->with('col-1')->once()->andReturn(sifjStore()));
    $this->mock(ShopCatalog::class, fn ($m) => $m->shouldReceive('syncLatest')
        ->once()->andThrow(new RuntimeException('fill boom')));
    $this->mock(ShopAutoSelector::class, fn ($m) => $m->shouldReceive('selectInitial')
        ->once()->andReturn(0));

    $job = new ShopInitialFillJob('col-1');

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

// #TEST-18: the two collaborators handle() delegates to (ShopCatalog::syncLatest,
// ShopAutoSelector::selectInitial) are each idempotent on their own — but that is
// a property this job only INHERITS, never enforces itself: handle() carries no
// state of its own to be idempotent over. Unlike the three tests above, this one
// therefore does NOT mock ShopCatalog/ShopAutoSelector/ShopConnections — a mock
// call-count would pass even if the second run wrote duplicates. It runs the
// job against real content.*/site.* rows (mirrors ShopAutoSelectTest and
// ShopRelationalStorageTest's idiom) and only fakes the network boundary
// (ShopifyScraper), then diffs the persisted end-state across two handle() calls.
function sifjEndState(string $collectionId, string $userId): array
{
    $sectionId = DB::table('site.sections')
        ->join('site.sites', 'site.sites.id', '=', 'site.sections.site_id')
        ->where('site.sites.user_id', $userId)
        ->where('site.sections.key', 'pool:shop')
        ->value('site.sections.id');

    return [
        'collectionItems' => DB::table('content.collection_items')
            ->where('collection_id', $collectionId)
            ->orderBy('position')
            ->pluck('position', 'item_id')->all(),
        // Total content.items of kind=product for this user, not just the ones
        // linked to $collectionId — catches a second run minting duplicate item
        // rows that a broken coord dedupe then fails to re-link.
        'productItemCount' => DB::table('content.items')
            ->where('user_id', $userId)->where('kind', 'product')->count(),
        'pinnedItemIds' => $sectionId === null ? [] : DB::table('site.section_items')
            ->where('section_id', $sectionId)->where('state', 'pinned')
            ->orderBy('sort_key')->pluck('item_id')->all(),
        'autoselectedAt' => DB::table('content.storefronts')
            ->where('collection_id', $collectionId)->value('products_autoselected_at'),
    ];
}

it('running handle() twice leaves an identical content.*/pin end-state (idempotent)', function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();

    [$pro] = poolTenant();
    // shopStore() (defined in tests/Helpers/PoolTestHelpers.php) does NOT
    // denormalise user_id onto content.storefronts by default — pass it
    // explicitly, or storeByCollection() reads it back null and handle()
    // takes its early-return branch (store->userId === null) with no error.
    $collectionId = shopStore($pro->id, ['url' => 'https://sifj-store.example.com', 'user_id' => $pro->id]);

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('fetchProducts')->andReturn([
        ['productId' => 'p1', 'title' => 'One', 'url' => 'https://sifj-store.example.com/p1', 'createdAt' => '2026-01-03T00:00:00Z'],
        ['productId' => 'p2', 'title' => 'Two', 'url' => 'https://sifj-store.example.com/p2', 'createdAt' => '2026-01-02T00:00:00Z'],
        ['productId' => 'p3', 'title' => 'Three', 'url' => 'https://sifj-store.example.com/p3', 'createdAt' => '2026-01-01T00:00:00Z'],
    ]));

    app()->call([new ShopInitialFillJob($collectionId), 'handle']);
    $afterFirstRun = sifjEndState($collectionId, $pro->id);

    // Same catalogue fetched again (a duplicate dispatch, or a worker retry
    // after a post-commit crash) must not double-write. Travel forward first
    // so a broken compare-and-set (re-stamping products_autoselected_at on
    // every call instead of once) is visible as a changed timestamp rather
    // than hidden by two now() calls landing in the same DB-precision tick.
    $this->travel(1)->hour();
    app()->call([new ShopInitialFillJob($collectionId), 'handle']);
    $afterSecondRun = sifjEndState($collectionId, $pro->id);

    expect($afterFirstRun['productItemCount'])->toBe(3);
    expect($afterFirstRun['pinnedItemIds'])->toHaveCount(3);
    expect($afterFirstRun['autoselectedAt'])->not->toBeNull();

    expect($afterSecondRun['collectionItems'])->toBe($afterFirstRun['collectionItems']);
    expect($afterSecondRun['productItemCount'])->toBe($afterFirstRun['productItemCount']);
    expect($afterSecondRun['pinnedItemIds'])->toBe($afterFirstRun['pinnedItemIds']);
    expect($afterSecondRun['autoselectedAt'])->toBe($afterFirstRun['autoselectedAt']);
});
