<?php

use App\Http\Controllers\Api\Content\LinkPreviewController;
use App\Http\Controllers\Api\Content\PoolController;
use App\Http\Controllers\Api\Content\PoolItemCreateController;
use App\Jobs\Content\EnrichPoolLinkJob;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

// Link cards carry the page's share image and favicon (2026-08-17): the
// dashboard previews a pasted URL, the owner checks the card, and the create
// endpoint stores it — images as borrowed media, read back as `thumbnail`
// (cover) and `favicon` (logo).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

it('previews what a pasted link would become', function () {
    [$pro] = poolTenant();

    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->with('example.com/page')->andReturn('https://example.com/page');
        $m->shouldReceive('snapshotOrMinimal')->with('https://example.com/page')->andReturn([
            'url' => 'https://example.com/page',
            'name' => 'Example page',
            'description' => 'A page about examples.',
            'favicon' => 'https://example.com/favicon.ico',
            'logo' => 'https://example.com/og.png',
        ]);
    });

    $request = Request::create('/api/content/links/preview', 'POST', ['url' => 'example.com/page']);
    $request->attributes->set('professional', $pro);
    $data = app(LinkPreviewController::class)->show($request)->getData(true);

    expect($data)->toMatchArray([
        'url' => 'https://example.com/page',
        'name' => 'Example page',
        'description' => 'A page about examples.',
        'favicon' => 'https://example.com/favicon.ico',
        'logo' => 'https://example.com/og.png',
        'fetched' => true,
        'product' => null,
    ]);
});

it('previews a product page with its price when asked for a product', function () {
    [$pro] = poolTenant();

    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://shop.test/products/hat');
    });
    $this->mock(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->with('https://shop.test/products/hat')->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
            'storeUrl' => null,
            'product' => [
                'title' => 'The Hat', 'description' => 'A hat.', 'url' => 'https://shop.test/products/hat',
                'price' => '45.00', 'currency' => 'AUD', 'available' => false,
                'image' => 'https://shop.test/hat.jpg', 'images' => ['https://shop.test/hat.jpg', 'https://shop.test/hat-2.jpg'],
            ],
        ]);
    });

    $request = Request::create('/api/content/links/preview', 'POST', ['url' => 'shop.test/products/hat', 'intent' => 'product']);
    $request->attributes->set('professional', $pro);
    $data = app(LinkPreviewController::class)->show($request)->getData(true);

    expect($data['name'])->toBe('The Hat')
        ->and($data['logo'])->toBe('https://shop.test/hat.jpg')
        ->and($data['product'])->toBe([
            'price' => '45.00', 'currency' => 'AUD', 'availability' => 'out_of_stock',
            'images' => ['https://shop.test/hat.jpg', 'https://shop.test/hat-2.jpg'],
        ]);
});

it('stores the checked card on create and serves its images on the pool wire', function () {
    [$pro] = poolTenant();

    $request = Request::create('/api/content/pools/custom_links/items', 'POST', [
        'url' => 'https://example.com/page',
        'title' => 'Example page',
        'description' => 'A page about examples.',
        'favicon' => 'https://example.com/favicon.ico',
        'logo' => 'https://example.com/og.png',
    ]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolItemCreateController::class)->store($request, 'custom_links')->getData(true);

    $item = $data['selection'][0];
    expect($item['headline'])->toBe('Example page')
        ->and($item['description'])->toBe('A page about examples.')
        ->and($item['thumbnail'])->toBe('https://example.com/og.png')
        ->and($item['favicon'])->toBe('https://example.com/favicon.ico');

    // The card came with the request, so nothing is dispatched to read it again.
    Queue::assertNotPushed(EnrichPoolLinkJob::class);
});

it('enriches a link added without a card, and never clobbers typed words', function () {
    [$pro] = poolTenant();

    $request = Request::create('/api/content/pools/custom_links/items', 'POST', [
        'url' => 'https://example.com/page',
    ]);
    $request->attributes->set('professional', $pro);
    app(PoolItemCreateController::class)->store($request, 'custom_links');
    Queue::assertPushed(EnrichPoolLinkJob::class);

    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('snapshot')->with('https://example.com/page')->andReturn([
            'url' => 'https://example.com/page',
            'name' => 'Example page',
            'description' => 'A page about examples.',
            'favicon' => 'https://example.com/favicon.ico',
            'logo' => 'https://example.com/og.png',
        ]);
    });
    (new EnrichPoolLinkJob((string) $pro->id, 'https://example.com/page'))
        ->handle(app(LinkCardScraper::class), app(LinkPoolWriter::class));

    $show = Request::create('/api/content/pools/custom_links', 'GET');
    $show->attributes->set('professional', $pro);
    $item = app(PoolController::class)->show($show, 'custom_links')->getData(true)['selection'][0];
    expect($item['headline'])->toBe('Example page')
        ->and($item['description'])->toBe('A page about examples.')
        ->and($item['thumbnail'])->toBe('https://example.com/og.png')
        ->and($item['favicon'])->toBe('https://example.com/favicon.ico');

    // A second run against a now-titled item leaves the words alone but the
    // images still land (they are the page's, not the owner's).
    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('snapshot')->andReturn([
            'url' => 'https://example.com/page',
            'name' => 'Some other title',
            'description' => 'Other words.',
            'favicon' => 'https://example.com/favicon2.ico',
            'logo' => 'https://example.com/og2.png',
        ]);
    });
    (new EnrichPoolLinkJob((string) $pro->id, 'https://example.com/page'))
        ->handle(app(LinkCardScraper::class), app(LinkPoolWriter::class));
    $item = app(PoolController::class)->show($show, 'custom_links')->getData(true)['selection'][0];
    expect($item['headline'])->toBe('Example page')
        ->and($item['description'])->toBe('A page about examples.')
        ->and($item['thumbnail'])->toBe('https://example.com/og2.png');
});

it('reads the page for any lane that writes a bare link, and never loops on a title-only page', function () {
    // Owner, 2026-08-19. Every writer used to decide enrichment for itself and
    // most did not, so a link pooled by the ordering fallback or the Google
    // harvest sat with no favicon, no picture and no words beside a
    // dashboard-added link that had all three. The decision moved into
    // LinkPoolWriter::add(), which is the one door they all come through.
    [$pro] = poolTenant();

    app(LinkPoolWriter::class)->add($pro, 'https://harvested.example/store');
    Queue::assertPushed(EnrichPoolLinkJob::class, fn ($job) => $job->url === 'https://harvested.example/store');

    // A caller that already read the page brings its own words and images —
    // fetching again would learn nothing.
    app(LinkPoolWriter::class)->add(
        $pro, 'https://checked.example/page', 'Checked', 'Already described.',
        'https://checked.example/favicon.ico', 'https://checked.example/og.png',
    );
    Queue::assertNotPushed(EnrichPoolLinkJob::class, fn ($job) => $job->url === 'https://checked.example/page');

    // The job's OWN write-back must not re-dispatch: a page that yields a
    // title and nothing else would otherwise queue the job that just ran, for
    // ever. `enrich: false` is that guard.
    app(LinkPoolWriter::class)->add($pro, 'https://titleonly.example/x', 'Title only', null, null, null, enrich: false);
    Queue::assertNotPushed(EnrichPoolLinkJob::class, fn ($job) => $job->url === 'https://titleonly.example/x');
});
