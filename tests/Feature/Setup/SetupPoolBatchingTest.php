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
    $active = true;
    DB::listen(function ($query) use (&$seen, &$active) {
        if (! $active) {
            return; // this call's window has closed — never collect into a stale array
        }
        $sql = $query->sql;
        if (str_contains($sql, 'section_items') && str_starts_with(trim(strtolower($sql)), 'select')) {
            $seen[] = $sql;
        }
    });

    $run();
    $active = false;

    return $seen;
}

/** Every SELECT this request issued against a given table fragment. */
function setupSelectsAgainst(string $needle, callable $run): array
{
    $seen = [];
    $active = true;
    DB::listen(function ($query) use (&$seen, &$active, $needle) {
        if (! $active) {
            return;
        }
        $sql = $query->sql;
        if (str_contains($sql, $needle) && str_starts_with(trim(strtolower($sql)), 'select')) {
            $seen[] = $sql;
        }
    });

    $run();
    $active = false;

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

// The test above counts section_items selects, which stay at 1 even if
// resolveAllPools() is reverted to call hydrateItems() once PER pool (that
// half of the batching survives untouched via preloadSections/preloadCuration).
// The property this branch actually exists for — ONE shared hydrate instead of
// one per pool — is only visible by counting the query hydrateItems() itself
// issues. content.identity_candidates is read exactly once per hydrateItems()
// call (PoolResolver::itemPayloads(), gated on withDuplicateCandidates, which
// the setup lane always passes true), so seeding content in two different
// pools turns a per-pool hydrate into 2+ reads and a shared hydrate into 1.
it('hydrates every pool\'s items in one shared call, not one per pool', function () {
    $pro = createTenant('setup-batch-hydrate');
    seedContentItem($pro->id, ['kind' => 'video']); // items.watch
    seedContentItem($pro->id, ['kind' => 'product']); // items.shop

    $selects = setupSelectsAgainst('identity_candidates', function () use ($pro) {
        actingAsUser($pro)->getJson('/api/site/setup')->assertOk();
    });

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

    expect($fromAll)->not->toBeNull();
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
    // sector: a personal-trainer's sector suggestions are ['booking', 'strava']
    // (OnboardingSuggestions::SECTOR_SUGGESTIONS) — strava's registry category
    // is Content, one of platforms.social's GROUP_CATEGORIES, so topFor()
    // actually returns something for this sector. Without a sector,
    // OnboardingSuggestions::for() returns suggestions=[] and topFor() always
    // returns [] whether $onboarding is real or the [] a skipping forPass()
    // would pass — leaving the 'top' half of this test vacuous.
    $pro = createTenant('setup-oneplatform', ['sector' => 'personal-trainer']);
    seedSetupSourceIntentForBatching($pro->id);

    $payload = app(SetupPayload::class);

    $fromAll = collect($payload->for($pro)['passes'])->firstWhere('key', 'platforms.social');
    $fromOne = $payload->forPass($pro, 'platforms.social');

    // Assert both halves are actually populated before comparing — otherwise
    // this would pass even if forPass() silently skipped suggestions or
    // onboarding.
    expect($fromAll['suggestions'])->not->toBeEmpty();
    expect($fromAll['top'])->not->toBeEmpty();
    expect($fromOne)->toEqual($fromAll);
});

// Task 3's brief claims duplicateCandidates is dead weight on the setup wire
// because setupItem() never reads it — but items.watch/items.shop/media/links
// put resolvedPools['library'] rows on the wire VERBATIM, bypassing
// setupItem() entirely (composePass(), the item-pool branch). This test is
// the decisive check: it proves the field is actually populated on the setup
// wire TODAY, on unmodified code, so it can serve as a before/after oracle
// for whatever resolveAllPools()'s withDuplicateCandidates flag ends up
// being.
it('the setup wire carries a populated duplicateCandidates today', function () {
    $pro = createTenant('setup-dupes');
    $itemA = seedContentItem($pro->id, ['kind' => 'video']);
    $itemB = seedContentItem($pro->id, ['kind' => 'video']);

    DB::connection('pgsql')->table('content.identity_candidates')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'left_item_id' => $itemA,
        'right_item_id' => $itemB,
        'score' => 80,
        'evidence' => json_encode(['key' => 'phone']),
        'created_at' => now(),
    ]);

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->assertOk()->json('passes'));
    $watchPass = $passes->firstWhere('key', 'items.watch');

    expect($watchPass)->not->toBeNull();
    $wireItemA = collect($watchPass['items'])->firstWhere('id', $itemA);
    expect($wireItemA)->not->toBeNull()
        ->and($wireItemA['duplicateCandidates'])->not->toBeEmpty();
});

// watch/shop (above) both take composePass()'s verbatim-library branch —
// resolvedPools['library'] rows go straight onto the wire, unchanged. services
// is the ONE pool whose output is transformed on the way out (setupItem() plus
// the category grouping in servicesPass()), and it had no equivalence test at
// all: against a byte-identical bar, that transform is exactly where a
// batching bug could hide undetected. The expectation is derived from
// resolve() — the unchanged pre-batching path — run through the real
// servicesPass() transform, not a hardcoded shape.
it('the services pass groups an independently-resolved library the same way', function () {
    $pro = createTenant('setup-batch-services');
    seedContentItem($pro->id, ['kind' => 'service']);
    seedContentItem($pro->id, ['kind' => 'service']);

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->assertOk()->json('passes'));
    $servicesPass = $passes->firstWhere('key', 'services');
    expect($servicesPass)->not->toBeNull();

    $site = $pro->site()->firstOrFail();
    $resolved = app(PoolResolver::class)->resolve($site, 'services');
    // Both seeded items must have made it into the library, or comparing
    // categories below would trivially agree over an empty set.
    expect($resolved['library'])->toHaveCount(2);

    $servicesPassMethod = new ReflectionMethod(SetupPayload::class, 'servicesPass');
    $servicesPassMethod->setAccessible(true);
    $expected = $servicesPassMethod->invoke(app(SetupPayload::class), $pro, $site, ['services' => $resolved]);

    expect($servicesPass['categories'])->toEqual($expected['categories']);
});
