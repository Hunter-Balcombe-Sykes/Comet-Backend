<?php

use App\Services\Accounts\AccountCapabilities;
use App\Services\Setup\SetupPayload;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// The setup dialog resolves seven content pools. Resolving them one at a
// time paid PoolResolver's per-pool pre-reads seven times over; PoolWire
// solved the same N+1 for the public lane in 2026-08-24. These tests pin
// the batched shape by COUNTING the pre-reads, because the wire output is
// identical either way and so proves nothing on its own.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

/** Every SELECT this request issued against site.section_items. */
function setupCurationSelects(callable $run): array
{
    $seen = [];
    DB::listen(function ($query) use (&$seen) {
        $sql = $query->sql;
        if (str_contains($sql, 'section_items') && str_starts_with(trim(strtolower($sql)), 'select')) {
            $seen[] = $sql;
        }
    });

    $run();

    return $seen;
}

it('reads every pool\'s curation in one query, not one per pool', function () {
    $pro = createTenant('setup-batch');
    seedContentItem($pro->id, ['kind' => 'video']);

    $selects = setupCurationSelects(function () use ($pro) {
        actingAsUser($pro)->getJson('/api/site/setup')->assertOk();
    });

    // One whereIn over every section, via PoolResolver::preloadCuration.
    // Before batching this was seven separate section-scoped selects.
    expect($selects)->toHaveCount(1);
});

// The query-count test above proves the shape of the reads, not the content
// of the response — SetupControllerTest never seeds content.items, so every
// item pass it exercises takes the empty-pool-omits-pass branch and never
// touches $resolvedPools['library'] at all. PoolResolver::resolve() is the
// UNCHANGED pre-batching path (Task 1 only added callers of its plan/
// preloadSections/preloadCuration/hydrateItems/assemble halves, never
// touched resolve() itself), so it is a genuine before/after oracle: if
// resolveAllPools() ever returned a library that differs from what looping
// resolve() per pool would have produced, this is the test that catches it.
it('the batched items.watch and items.shop passes match an independent resolve() per pool, order included', function () {
    $pro = createTenant('setup-batch-parity');

    // Two items in one pool (ordering matters) and one in a second pool, so
    // the comparison exercises more than a single-item list and more than a
    // single non-empty pool, per the review's ask.
    $watchA = seedContentItem($pro->id, ['kind' => 'video', 'last_seen_at' => now()->subMinutes(5)]);
    $watchB = seedContentItem($pro->id, ['kind' => 'video', 'last_seen_at' => now()]);
    seedContentItem($pro->id, ['kind' => 'product']);

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->assertOk()->json('passes'));
    $watchPass = $passes->firstWhere('key', 'items.watch');
    $shopPass = $passes->firstWhere('key', 'items.shop');

    expect($watchPass)->not->toBeNull()
        ->and($shopPass)->not->toBeNull();
    // Both items must have made it into the comparison, or this is a
    // one-item test wearing a two-item costume.
    expect($watchPass['items'])->toHaveCount(2);

    $resolver = app(PoolResolver::class);
    $site = $pro->site()->firstOrFail();

    // items.watch and items.shop both take composePass()'s plain item-pool
    // branch (items = resolved['library'] verbatim) — no setupItem()
    // transform, unlike services. That is what makes a direct comparison to
    // resolve()['library'] meaningful.
    expect($watchPass['items'])->toEqual($resolver->resolve($site, 'watch')['library']);
    expect($shopPass['items'])->toEqual($resolver->resolve($site, 'shop')['library']);

    expect(collect($watchPass['items'])->pluck('id')->all())->toEqual([$watchB, $watchA]);
});

it('returns the same pass from forPass as from the full compose', function () {
    $pro = createTenant('setup-onepass');
    seedContentItem($pro->id, ['kind' => 'video']);

    $payload = app(SetupPayload::class);

    $fromAll = collect($payload->for($pro)['passes'])->firstWhere('key', 'items.watch');
    $fromOne = $payload->forPass($pro, 'items.watch');

    expect($fromOne)->toEqual($fromAll);
});

it('provisions every pool\'s section even when composing a single pass', function () {
    $pro = createTenant('setup-provision');
    $expected = DB::connection('pgsql')->table('site.sections')->count();

    app(SetupPayload::class)->for($pro);
    $afterAll = DB::connection('pgsql')->table('site.sections')->count();

    // A second tenant taking the single-pass path must end up with the
    // same number of sections — provisioning is a side effect of
    // preloadSections and must not narrow with the pass being built.
    $other = createTenant('setup-provision-2');
    app(SetupPayload::class)->forPass($other, 'items.watch');
    $afterOne = DB::connection('pgsql')->table('site.sections')->count();

    expect($afterOne - $afterAll)->toBe($afterAll - $expected);
});

it('returns null for a pass the user does not have', function () {
    $pro = createTenant('setup-nopass');

    expect(app(SetupPayload::class)->forPass($pro, 'platforms.ordering'))->toBeNull();
});

/**
 * Mirrors SetupControllerTest's setupSeedIntent — redeclared here under a
 * different name because Pest test files in the same directory share the
 * global function namespace, and redeclaring setupSeedIntent would fatal.
 */
function seedSetupSourceIntentForBatching(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'identifier' => 'someone',
        'canonical_url' => 'https://www.instagram.com/someone',
        'state' => 'proposed',
        'origin' => 'link_in_bio',
        'band' => 'auto',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

// items.watch (above) never exercises forPass()'s prelude guard: it needs
// neither suggestionRows() nor onboarding->for() either way. platforms.social
// is the only branch where for()'s prelude and forPass()'s prelude actually
// differ — forPass() skips both calls unless the key starts with
// 'platforms.' — so it is the one equivalence case that can catch a
// forPass() prelude bug that items.watch cannot.
it('returns the same pass from forPass as from the full compose on the platforms branch too', function () {
    $pro = createTenant('setup-oneplatform');
    seedSetupSourceIntentForBatching($pro->id);

    $payload = app(SetupPayload::class);

    $fromAll = collect($payload->for($pro)['passes'])->firstWhere('key', 'platforms.social');
    $fromOne = $payload->forPass($pro, 'platforms.social');

    // Assert the pass is actually populated before comparing — otherwise
    // this would pass even if forPass() silently skipped suggestions.
    expect($fromAll['suggestions'])->not->toBeEmpty();
    expect($fromOne)->toEqual($fromAll);
});
