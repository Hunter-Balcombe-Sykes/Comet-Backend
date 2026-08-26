<?php

use Illuminate\Support\Facades\DB;

// PATCH /api/site validation + persistence for the unified ordering settings
// (2026-08-23): settings.actions {mode, slots} + settings.pool_order, plus
// the page-order pair (smart_page_order / manual_page_order) this file always
// covered. Atomic list replace rides UpdateSiteAction::LIST_SETTINGS_KEYS.

beforeEach(function () {
    config(['partna.throttle.enabled' => false, 'partna.actions.slots' => 10]);
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
});

function siteSettings(object $tenant): array
{
    $raw = DB::connection('pgsql')->table('site.sites')->where('id', $tenant->site->id)->value('settings');

    return is_string($raw) ? (array) json_decode($raw, true) : [];
}

function patchSettings(object $pro, array $settings)
{
    return actingAsUser($pro)->patchJson('/api/site', ['settings' => $settings]);
}

it('accepts and persists actions (mode + locks) and pool_order', function () {
    $pro = createTenant('as-happy');

    patchSettings($pro, [
        'actions' => ['mode' => 'smart', 'slots' => [['position' => 0, 'id' => 'page:services'], ['position' => 4, 'id' => 'item:0b1e6b2e-2f6f-4c0e-9e4a-1d3a2c7e9f10']]],
        'pool_order' => ['watch' => 'smart', 'custom_links' => 'manual'],
    ])->assertOk();

    $s = siteSettings($pro);
    expect($s['actions'])->toBe(['mode' => 'smart', 'slots' => [['position' => 0, 'id' => 'page:services'], ['position' => 4, 'id' => 'item:0b1e6b2e-2f6f-4c0e-9e4a-1d3a2c7e9f10']]])
        ->and($s['pool_order'])->toBe(['watch' => 'smart', 'custom_links' => 'manual']);
});

it('replaces actions atomically (a shorter write wins, an empty write clears) but merges pool_order BY POOL', function () {
    $pro = createTenant('as-atomic');
    patchSettings($pro, ['actions' => ['mode' => 'manual', 'slots' => [['position' => 0, 'id' => 'page:services'], ['position' => 1, 'id' => 'page:menu']]]])->assertOk();
    patchSettings($pro, ['actions' => ['mode' => 'manual', 'slots' => [['position' => 0, 'id' => 'page:menu']]]])->assertOk();
    expect(siteSettings($pro)['actions']['slots'])->toBe([['position' => 0, 'id' => 'page:menu']]);

    patchSettings($pro, ['actions' => ['mode' => 'newest', 'slots' => []]])->assertOk();
    expect(siteSettings($pro)['actions'])->toBe(['mode' => 'newest', 'slots' => []]);

    // pool_order is a map keyed by pool and the ordering fieldset PATCHes one
    // pool at a time — a sparse write must not reset the others (2026-08-24:
    // it did, and saving services → manual silently put watch back on newest).
    patchSettings($pro, ['pool_order' => ['watch' => 'smart', 'listen' => 'smart']])->assertOk();
    patchSettings($pro, ['pool_order' => ['listen' => 'manual']])->assertOk();
    expect(siteSettings($pro)['pool_order'])->toBe(['watch' => 'smart', 'listen' => 'manual']);
});

it('rejects a bad mode, a missing mode, and a slot count over the cap', function () {
    $pro = createTenant('as-mode');
    patchSettings($pro, ['actions' => ['mode' => 'score']])->assertStatus(422)->assertJsonValidationErrors(['settings.actions.mode']);
    patchSettings($pro, ['actions' => ['slots' => []]])->assertStatus(422)->assertJsonValidationErrors(['settings.actions.mode']);
    $slots = [];
    for ($i = 0; $i < 11; $i++) {
        $slots[] = ['position' => $i, 'id' => 'item:'.$i];
    }
    patchSettings($pro, ['actions' => ['mode' => 'newest', 'slots' => $slots]])->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots']);
});

it('rejects duplicate positions, duplicate ids, a position past the last slot, and a non-grammar id', function () {
    $pro = createTenant('as-slots');
    patchSettings($pro, ['actions' => ['mode' => 'newest', 'slots' => [['position' => 1, 'id' => 'page:menu'], ['position' => 1, 'id' => 'page:shop']]]])
        ->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots.0.position']);
    patchSettings($pro, ['actions' => ['mode' => 'newest', 'slots' => [['position' => 0, 'id' => 'page:menu'], ['position' => 1, 'id' => 'page:menu']]]])
        ->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots.0.id']);
    patchSettings($pro, ['actions' => ['mode' => 'newest', 'slots' => [['position' => 10, 'id' => 'page:menu']]]])
        ->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots.0.position']);
    foreach (['instagram', 'custom:https://x', 'ordering:abc', 'page:'] as $bad) {
        patchSettings($pro, ['actions' => ['mode' => 'newest', 'slots' => [['position' => 0, 'id' => $bad]]]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots.0.id']);
    }
});

it('manual slots must be contiguous from 0; smart/newest locks may be sparse', function () {
    $pro = createTenant('as-contig');
    patchSettings($pro, ['actions' => ['mode' => 'manual', 'slots' => [['position' => 0, 'id' => 'page:menu'], ['position' => 2, 'id' => 'page:shop']]]])
        ->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots']);
    patchSettings($pro, ['actions' => ['mode' => 'smart', 'slots' => [['position' => 0, 'id' => 'page:menu'], ['position' => 2, 'id' => 'page:shop']]]])
        ->assertOk();
});

it('rejects pool_order for events, an unknown pool, and an unknown mode', function () {
    $pro = createTenant('as-pool');
    patchSettings($pro, ['pool_order' => ['events' => 'smart']])->assertStatus(422)->assertJsonValidationErrors(['settings.pool_order']);
    patchSettings($pro, ['pool_order' => ['posts' => 'smart']])->assertStatus(422)->assertJsonValidationErrors(['settings.pool_order']);
    patchSettings($pro, ['pool_order' => ['watch' => 'score']])->assertStatus(422)->assertJsonValidationErrors(['settings.pool_order.watch']);
});

it('never persists the retired keys (smart_actions / manual_actions / manual_order_pools)', function () {
    $pro = createTenant('as-legacy');
    $r = patchSettings($pro, ['smart_actions' => false, 'manual_actions' => [['kind' => 'action', 'ref' => 'menu']], 'manual_order_pools' => ['links']]);
    expect(in_array($r->status(), [200, 422], true))->toBeTrue();
    $s = siteSettings($pro);
    expect($s)->not->toHaveKey('smart_actions')->not->toHaveKey('manual_actions')->not->toHaveKey('manual_order_pools');
});

it('still accepts the page-order pair and normalises a legacy page id', function () {
    $pro = createTenant('as-pages');
    patchSettings($pro, ['smart_page_order' => false, 'manual_page_order' => ['book', 'shop', 'links']])->assertOk();
    $s = siteSettings($pro);
    expect($s['smart_page_order'])->toBeFalse()->and($s['manual_page_order'])->toBe(['services', 'shop', 'links']);
});

it('accepts and persists display_gallery_page, rejecting non-booleans', function () {
    $pro = createTenant('as-gallery');
    patchSettings($pro, ['display_gallery_page' => false])->assertOk();
    expect(siteSettings($pro)['display_gallery_page'])->toBeFalse();
    patchSettings($pro, ['display_gallery_page' => 'sometimes'])->assertStatus(422)->assertJsonValidationErrors(['settings.display_gallery_page']);
});

it('rejects unknown or duplicate page ids in manual_page_order', function () {
    $pro = createTenant('as-badpages');
    patchSettings($pro, ['manual_page_order' => ['services', 'checkout']])->assertStatus(422)->assertJsonValidationErrors(['settings.manual_page_order.1']);
    patchSettings($pro, ['manual_page_order' => ['services', 'services']])->assertStatus(422)->assertJsonValidationErrors(['settings.manual_page_order.0']);
});

it('an actions write advances sites.updated_at so the public payload cache key rotates', function () {
    $pro = createTenant('as-bust');
    $past = now()->subMinutes(5)->toISOString();
    DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->update(['updated_at' => $past]);
    patchSettings($pro, ['actions' => ['mode' => 'smart', 'slots' => []]])->assertOk();
    $after = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('updated_at');
    expect(strtotime((string) $after))->toBeGreaterThan(strtotime($past));
});

it('accepts pool_locks per pool, replaces the map atomically, and rejects bad shapes', function () {
    $pro = createTenant('as-locks');
    patchSettings($pro, ['pool_locks' => ['watch' => [['position' => 0, 'id' => 'item-a'], ['position' => 3, 'id' => 'item-b']], 'listen' => [['position' => 1, 'id' => 'item-c']]]])->assertOk();
    expect(siteSettings($pro)['pool_locks'])->toBe(['watch' => [['position' => 0, 'id' => 'item-a'], ['position' => 3, 'id' => 'item-b']], 'listen' => [['position' => 1, 'id' => 'item-c']]]);

    patchSettings($pro, ['pool_locks' => ['watch' => []]])->assertOk();
    expect(siteSettings($pro)['pool_locks'])->toBe(['watch' => []]);

    patchSettings($pro, ['pool_locks' => ['events' => [['position' => 0, 'id' => 'x']]]])->assertStatus(422)->assertJsonValidationErrors(['settings.pool_locks']);
    patchSettings($pro, ['pool_locks' => ['watch' => [['position' => 0, 'id' => 'a'], ['position' => 0, 'id' => 'b']]]])->assertStatus(422)->assertJsonValidationErrors(['settings.pool_locks.watch']);
    // Category pools (menus / services): a position is the index WITHIN the
    // item's category (D4), so two categories may each hold a #0.
    patchSettings($pro, ['pool_locks' => ['services' => [['position' => 0, 'id' => 'a'], ['position' => 0, 'id' => 'b']]]])->assertOk();
    patchSettings($pro, ['pool_locks' => ['watch' => [['position' => 0, 'id' => 'a'], ['position' => 1, 'id' => 'a']]]])->assertStatus(422)->assertJsonValidationErrors(['settings.pool_locks.watch.0.id']);
    patchSettings($pro, ['pool_locks' => ['watch' => [['position' => -1, 'id' => 'a']]]])->assertStatus(422);
});

// SEM-8: the contiguity closure gated on is_int($slot['position']), but the
// sibling rule validates position with Laravel's NON-strict 'integer', which
// accepts the string "1". String positions therefore passed the sibling rule,
// contributed nothing to the closure's $positions, and the `$positions !== []`
// guard then skipped the contiguity check entirely.
it('enforces manual-slot contiguity for STRING positions too (SEM-8)', function () {
    $pro = createTenant('as-sem8-reject');

    // Non-contiguous (0, 2) sent as STRINGS — used to slip through untouched.
    patchSettings($pro, ['actions' => ['mode' => 'manual', 'slots' => [
        ['position' => '0', 'id' => 'page:menu'],
        ['position' => '2', 'id' => 'page:shop'],
    ]]])->assertStatus(422)->assertJsonValidationErrors(['settings.actions.slots']);
});

it('still ACCEPTS contiguous string positions — the fix must cast, not just admit (SEM-8)', function () {
    // The other direction, and the one a naive is_numeric() fix breaks: the
    // closure compares against range(), which yields ints, so collecting "0","1"
    // uncast makes ["0","1"] !== [0,1] and rejects VALID input.
    $pro = createTenant('as-sem8-accept');

    patchSettings($pro, ['actions' => ['mode' => 'manual', 'slots' => [
        ['position' => '0', 'id' => 'page:menu'],
        ['position' => '1', 'id' => 'page:shop'],
    ]]])->assertOk();
});
