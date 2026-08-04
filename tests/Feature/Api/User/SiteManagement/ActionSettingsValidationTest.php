<?php

use Illuminate\Support\Facades\DB;

// PATCH /api/site validation + persistence for the actions-system settings
// (2026-07-23 rebuild): smart_page_order / manual_page_order / smart_actions
// / manual_actions (strict per-kind entry shapes: {kind: action, ref:
// <ActionVocabulary id>} | {kind: custom, label, url}), plus the legacy
// page/button/item shape migration in SiteOrderingValidationRules and the
// atomic list-replace semantics in UpdateSiteAction.

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
});

function siteSettings(object $tenant): array
{
    $raw = DB::connection('pgsql')->table('site.sites')->where('id', $tenant->site->id)->value('settings');

    return is_string($raw) ? (array) json_decode($raw, true) : [];
}

it('accepts and persists the four ordering settings', function () {
    $pro = createTenant('as-happy');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => [
            'smart_page_order' => false,
            'manual_page_order' => ['book', 'shop', 'links'],
            'smart_actions' => false,
            'manual_actions' => [
                ['kind' => 'action', 'ref' => 'menu'],
                ['kind' => 'action', 'ref' => 'instagram'],
                ['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'https://gifts.example/cards'],
            ],
        ]])
        ->assertOk();

    // 'book' is the legacy page-id for the Services page (2026-07-13 rename) —
    // it is accepted and persisted normalized to 'services'. (manual_page_order
    // uses the SitepageId taxonomy, untouched by the actions rebuild.)
    $settings = siteSettings($pro);
    expect($settings['smart_page_order'])->toBeFalse()
        ->and($settings['manual_page_order'])->toBe(['services', 'shop', 'links'])
        ->and($settings['smart_actions'])->toBeFalse()
        ->and($settings['manual_actions'][2])->toBe(['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'https://gifts.example/cards']);
});

// manual_order_pools (2026-08-04): the dashboard's per-pool Smart order
// switch. Sparse list of pool keys whose owner turned smart order OFF —
// absent = every pool smart. List-valued, so it replaces atomically like the
// other two manual lists.
it('accepts, persists and atomically replaces manual_order_pools', function () {
    $pro = createTenant('as-pools');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => [
            'manual_order_pools' => ['links', 'sell'],
        ]])
        ->assertOk();
    expect(siteSettings($pro)['manual_order_pools'])->toBe(['links', 'sell']);

    // A shorter incoming list must REPLACE, not positionally merge.
    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_order_pools' => ['watch']]])
        ->assertOk();
    expect(siteSettings($pro)['manual_order_pools'])->toBe(['watch']);

    // And emptying it returns every pool to smart order.
    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_order_pools' => []]])
        ->assertOk();
    expect(siteSettings($pro)['manual_order_pools'])->toBe([]);
});

it('rejects unknown or duplicate pool keys in manual_order_pools', function () {
    $pro = createTenant('as-badpool');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_order_pools' => ['links', 'checkout']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_order_pools.1']);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_order_pools' => ['links', 'links']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_order_pools.0']);
});

it('rejects unknown page ids in manual_page_order', function () {
    $pro = createTenant('as-badpage');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_page_order' => ['book', 'checkout']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_page_order.1']);
});

it('rejects duplicate pages in manual_page_order', function () {
    $pro = createTenant('as-duppage');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_page_order' => ['book', 'book']]])
        ->assertStatus(422);
});

it('rejects an unknown action kind', function () {
    $pro = createTenant('as-badkind');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'widget', 'ref' => 'x']]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0.kind']);
});

it('rejects a custom action without url, and with a bad url scheme', function () {
    $pro = createTenant('as-custom');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'custom', 'label' => 'X']]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0']);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'custom', 'label' => 'X', 'url' => 'javascript:alert(1)'],
        ]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0.url']);
});

it('rejects label/url on non-custom entries and ref on custom entries', function () {
    $pro = createTenant('as-strict');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'action', 'ref' => 'instagram', 'label' => 'Relabelled'],
        ]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0']);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'custom', 'label' => 'X', 'url' => 'https://x.example', 'ref' => 'sneaky'],
        ]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0']);
});

it('rejects a ref that is not a recognised action id', function () {
    $pro = createTenant('as-badref');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'action', 'ref' => 'checkout']]]])
        ->assertStatus(422);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'action', 'ref' => 'Insta Gram!']]]])
        ->assertStatus(422);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'action', 'ref' => 'custom:']]]])
        ->assertStatus(422);
});

it('accepts a dynamic-family ref (ordering:<id> / custom:<key>)', function () {
    $pro = createTenant('as-familyref');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'action', 'ref' => 'ordering:order-abc123'],
            ['kind' => 'action', 'ref' => 'custom:9b2f1c34-aaaa-bbbb-cccc-121212121212'],
        ]]])
        ->assertOk();

    expect(siteSettings($pro)['manual_actions'])->toBe([
        ['kind' => 'action', 'ref' => 'ordering:order-abc123'],
        ['kind' => 'action', 'ref' => 'custom:9b2f1c34-aaaa-bbbb-cccc-121212121212'],
    ]);
});

it('rejects duplicate action refs (customs exempt)', function () {
    $pro = createTenant('as-dupref');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'action', 'ref' => 'instagram'],
            ['kind' => 'action', 'ref' => 'instagram'],
        ]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions']);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'custom', 'label' => 'A', 'url' => 'https://a.example'],
            ['kind' => 'custom', 'label' => 'B', 'url' => 'https://b.example'],
        ]]])
        ->assertOk();
});

it('caps list sizes (manual_page_order ≤ 16, manual_actions ≤ 26)', function () {
    $pro = createTenant('as-caps');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => array_fill(0, 27, ['kind' => 'custom', 'label' => 'X', 'url' => 'https://x.example'])]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions']);
});

it('replaces list settings atomically instead of positionally merging', function () {
    $pro = createTenant('as-replace');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_page_order' => ['book', 'shop', 'links']]])
        ->assertOk();

    // A shorter incoming list must fully replace the stored one —
    // array_replace_recursive alone would yield ['links','shop','links'].
    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_page_order' => ['links']]])
        ->assertOk();

    expect(siteSettings($pro)['manual_page_order'])->toBe(['links']);
});

it('leaves ordering settings untouched by unrelated settings PATCHes', function () {
    $pro = createTenant('as-patch');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['smart_actions' => false, 'manual_actions' => [['kind' => 'action', 'ref' => 'menu']]]])
        ->assertOk();

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['show_branding' => true]])
        ->assertOk();

    $settings = siteSettings($pro);
    expect($settings['smart_actions'])->toBeFalse()
        ->and($settings['manual_actions'])->toBe([['kind' => 'action', 'ref' => 'menu']]);
});

// ── Legacy shape migration (pre-2026-07-23 dashboard / stale client) ────────

it('migrates a legacy page-kind action ref to the new booking-services id', function () {
    $pro = createTenant('as-legacy-page');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'page', 'ref' => 'book'],     // legacy page-id, folds to 'services' first
            ['kind' => 'page', 'ref' => 'shop'],     // 1:1 with the current static id
        ]]])
        ->assertOk();

    expect(siteSettings($pro)['manual_actions'])->toBe([
        ['kind' => 'action', 'ref' => 'booking-services'],
        ['kind' => 'action', 'ref' => 'shop'],
    ]);
});

it('migrates a legacy button-kind booking ref to booking-services, and a social ref 1:1', function () {
    $pro = createTenant('as-legacy-button');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'button', 'ref' => 'booking'],
            ['kind' => 'button', 'ref' => 'instagram'],
        ]]])
        ->assertOk();

    expect(siteSettings($pro)['manual_actions'])->toBe([
        ['kind' => 'action', 'ref' => 'booking-services'],
        ['kind' => 'action', 'ref' => 'instagram'],
    ]);
});

it('drops a legacy item-kind entry and a legacy button ref with no current mapping', function () {
    $pro = createTenant('as-legacy-drop');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'item', 'ref' => 'service:9b2f1c34-aaaa-bbbb-cccc-121212121212'],
            ['kind' => 'button', 'ref' => 'fresha'],  // no current action for this platform
            ['kind' => 'action', 'ref' => 'menu'],    // survives untouched
        ]]])
        ->assertOk();

    expect(siteSettings($pro)['manual_actions'])->toBe([
        ['kind' => 'action', 'ref' => 'menu'],
    ]);
});
