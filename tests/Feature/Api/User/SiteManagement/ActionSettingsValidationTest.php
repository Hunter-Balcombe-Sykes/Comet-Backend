<?php

use Illuminate\Support\Facades\DB;

// PATCH /api/site validation + persistence for the actions-system settings:
// smart_page_order / manual_page_order / smart_actions / manual_actions
// (strict per-kind entry shapes incl. custom {label,url}), plus the atomic
// list-replace semantics in UpdateSiteAction.

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
                ['kind' => 'page', 'ref' => 'book'],
                ['kind' => 'item', 'ref' => 'service:9b2f1c34-aaaa-bbbb-cccc-121212121212'],
                ['kind' => 'button', 'ref' => 'instagram'],
                ['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'https://gifts.example/cards'],
            ],
        ]])
        ->assertOk();

    // 'book' is the legacy page-id for the Services page (2026-07-13 rename) —
    // it is accepted and persisted normalized to 'services'.
    $settings = siteSettings($pro);
    expect($settings['smart_page_order'])->toBeFalse()
        ->and($settings['manual_page_order'])->toBe(['services', 'shop', 'links'])
        ->and($settings['smart_actions'])->toBeFalse()
        ->and($settings['manual_actions'][3])->toBe(['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'https://gifts.example/cards']);
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
            ['kind' => 'button', 'ref' => 'instagram', 'label' => 'Relabelled'],
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

it('rejects malformed refs per kind (unknown page, bad button slug, bad item shape)', function () {
    $pro = createTenant('as-badref');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'page', 'ref' => 'checkout']]]])
        ->assertStatus(422);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'button', 'ref' => 'Insta Gram!']]]])
        ->assertStatus(422);

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [['kind' => 'item', 'ref' => 'no-colon-here']]]])
        ->assertStatus(422);
});

it('rejects duplicate action refs (customs exempt)', function () {
    $pro = createTenant('as-dupref');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => [
            ['kind' => 'button', 'ref' => 'instagram'],
            ['kind' => 'button', 'ref' => 'instagram'],
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

it('caps list sizes (manual_page_order ≤ 16, manual_actions ≤ 12)', function () {
    $pro = createTenant('as-caps');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['manual_actions' => array_fill(0, 13, ['kind' => 'page', 'ref' => 'book'])]])
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
        ->patchJson('/api/site', ['settings' => ['smart_actions' => false, 'manual_actions' => [['kind' => 'page', 'ref' => 'book']]]])
        ->assertOk();

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['show_branding' => true]])
        ->assertOk();

    // 'book' (legacy) persists normalized to the 'services' page-id.
    $settings = siteSettings($pro);
    expect($settings['smart_actions'])->toBeFalse()
        ->and($settings['manual_actions'])->toBe([['kind' => 'page', 'ref' => 'services']]);
});
