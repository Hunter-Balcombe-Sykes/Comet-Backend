<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// M-9 (matrix run 2, tashsultanamerch live): the linktree's
// tashsultanamerch.myshopify.com projected straight to shopify.store and
// Engine 1 bare-applied a 'pending' connection — no storefront row, no
// catalogue, no fill, no auto-select, and nothing that would ever sync.
// Storefronts have a single writer (StoreBrandSeeder via the commerce
// lane), so a scan-lane shop Place now delegates to CommerceProbeJob and
// writes neither a connection nor an intent.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
});

it('delegates a scan-lane shop Place to the commerce probe — no bare connection, no intent', function () {
    Bus::fake([CommerceProbeJob::class]);
    $pro = createTenant('m9-shop-delegate');

    $iri = app(IriCanonicalizer::class)->canonicalize('https://tashsultanamerch.myshopify.com/');
    $out = app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'shopify.store', 'tashsultanamerch'),
        RoutingContext::forUser($pro, 'link_in_bio'),
        $iri,
    );

    Bus::assertDispatched(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'tashsultanamerch.myshopify.com') && $job->userId === (string) $pro->id);

    expect($out['connection_id'])->toBeNull()
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(0);
});

it('keeps the direct-request (paste) path unchanged for shop surfaces', function () {
    Bus::fake([CommerceProbeJob::class]);
    $pro = createTenant('m9-shop-paste');

    $iri = app(IriCanonicalizer::class)->canonicalize('https://tashsultanamerch.myshopify.com/');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'shopify.store', 'tashsultanamerch'),
        RoutingContext::forUser($pro, 'paste'),
        $iri,
    );

    // The paste lane's own controller runs its suggest-only probe; reconcile
    // must not add a second dispatch from here.
    Bus::assertNotDispatched(CommerceProbeJob::class);
});

it('a tenant shop host probes end-to-end: connection + storefront + fill, not a bare connection', function () {
    // The second half of M-9: the delegation above hands the URL to the
    // probe lane, but the probe worker used to REFUSE tenant-scoped hosts
    // ('already_matched') — the projector knew what it was, and the seeder
    // therefore had no path at all. Shop tenant suffixes now probe.
    \Illuminate\Support\Facades\Http::fake([
        '*/meta.json' => \Illuminate\Support\Facades\Http::response(['id' => 99110022, 'name' => 'Tash Sultana Merch', 'currency' => 'AUD'], 200),
        'https://tashsultanamerch.myshopify.com/' => \Illuminate\Support\Facades\Http::response('<html><head><title>Tash Sultana Merch</title></head><body>shop</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => \Illuminate\Support\Facades\Http::response('', 404),
    ]);
    \Illuminate\Support\Facades\Queue::fake();
    $pro = createTenant('m9-tenant-probe');

    app()->call([new CommerceProbeJob((string) $pro->id, 'https://tashsultanamerch.myshopify.com/'), 'handle']);

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($connection)->not->toBeNull()
        ->and(DB::table('content.storefronts')->where('user_id', $pro->id)->exists())->toBeTrue();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Platforms\ShopInitialFillJob::class);
});
