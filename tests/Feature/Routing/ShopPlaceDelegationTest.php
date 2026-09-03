<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\ShopInitialFillJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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
    //
    // Since 2026-09-03 nothing a harvester finds auto-connects (decide()
    // mints Place only for `$context->isConfirmedByUser()`), and the
    // delegation arm above always probes with suggestOnly: true — so this
    // job, run the way the delegation arm actually dispatches it, resolves
    // to a SUGGESTION, not a connection. The end-to-end connection +
    // storefront + fill this test pins now happens only after the user
    // ACCEPTS that suggestion: SuggestionsController::accept() re-dispatches
    // CommerceProbeJob with acceptedIntentId set, which is what sets
    // `confirmed` on the routing context and is the only thing that lets
    // decide() mint Place.
    Http::fake([
        '*/meta.json' => Http::response(['id' => 99110022, 'name' => 'Tash Sultana Merch', 'currency' => 'AUD'], 200),
        'https://tashsultanamerch.myshopify.com/' => Http::response('<html><head><title>Tash Sultana Merch</title></head><body>shop</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);
    Queue::fake();
    $pro = createTenant('m9-tenant-probe');

    // 1. The discovery probe, suggest-only exactly as the delegation arm
    //    dispatches it: a proposed intent, no connection yet.
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://tashsultanamerch.myshopify.com/', suggestOnly: true), 'handle']);

    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);

    // 2. Accept → the controller re-dispatches CommerceProbeJob with
    //    acceptedIntentId, the confirmation signal.
    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intent->id}/accept")->assertStatus(202);
    Queue::assertPushed(CommerceProbeJob::class, fn ($j) => $j->userId === (string) $pro->id && $j->category === 'shop' && $j->acceptedIntentId === $intent->id);

    // 3. Running that accepted job is what actually places the store.
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://tashsultanamerch.myshopify.com/', 'shop', acceptedIntentId: $intent->id), 'handle']);

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($connection)->not->toBeNull()
        ->and(DB::table('content.storefronts')->where('user_id', $pro->id)->exists())->toBeTrue();

    Queue::assertPushed(ShopInitialFillJob::class);
});

it('a dismissed tenant-store suggestion tombstones BOTH identifier schemes', function () {
    // M-9 critic: probe-placed tenant stores carry the NUMERIC storefront id
    // as identifier while the pure projector keys the same host by tenant
    // label — a numeric-only tombstone never matched the projector-side
    // check, so every re-scan re-probed the refused store.
    $pro = createTenant('m9-ts-both');
    $intentId = (string) Str::uuid();
    DB::table('routing.source_intents')->insert([
        'id' => $intentId,
        'user_id' => $pro->id,
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'identifier' => '79173517335',
        'canonical_url' => 'https://tashsultanamerch.myshopify.com/',
        'state' => 'proposed',
        'block_reason' => 'needs_confirmation',
        'origin' => 'commerce_probe',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/dismiss")->assertOk();

    $refs = DB::table('routing.item_tombstones')->where('user_id', $pro->id)->pluck('source_ref');
    expect($refs)->toContain('shopify.store:79173517335')
        ->and($refs)->toContain('shopify.store:tashsultanamerch');
});
