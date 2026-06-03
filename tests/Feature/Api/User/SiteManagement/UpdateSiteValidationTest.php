<?php

// TEST-4: functional validation coverage for UpdateSiteRequest.
//
// DesignKitRequestDriftTest only proves a rule KEY exists for every column — it
// compares string sets and never invokes the validator. So a rule could be
// silently weakened (e.g. the hex regex replaced with bare 'string') and the
// drift test would stay green while arbitrary CSS flowed into inline styles on
// the public page. These tests drive the real PATCH /api/site pipeline and
// assert the rules actually reject bad input with a 422 on the right key.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // PATCH /api/site carries throttle:authenticated — disable so repeated runs
    // in one process don't trip the limiter.
    config(['partna.throttle.enabled' => false]);
    // The subdomain closure queries these (the reserved-word branch returns
    // before any DB hit, but a regex-failing value still runs the full closure).
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
});

it('rejects a non-hex design-kit colour', function () {
    $pro = createTenant('hex-pro');

    // A 422 from the FormRequest means the controller action (and writeDesignKit)
    // never runs, so the bad value cannot reach the design_kits table.
    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => ['color_accent' => 'red']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['design_kit.color_accent']);
});

it('rejects an unknown skeleton id', function () {
    $pro = createTenant('skel-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['skeleton_id' => 'skeleton-9'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['skeleton_id']);
});

it('rejects a reserved subdomain', function () {
    $pro = createTenant('reserved-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['subdomain' => 'api'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subdomain']);
});

it('rejects a malformed subdomain', function () {
    // NB: prepareForValidation() lowercases the subdomain before rules run, so
    // an uppercase value would be normalised and PASS. We use an underscore —
    // it survives lowercasing and fails the DNS-safe regex, which is the rule
    // actually under test.
    $pro = createTenant('format-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['subdomain' => 'bad_subdomain'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subdomain']);
});

it('rejects any settings.design.* path (skeleton-cleanup guard)', function () {
    $pro = createTenant('design-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['design' => ['accent' => '#ffffff']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.design']);
});

it('accepts a valid skeleton and settings (negative tests are not over-rejecting)', function () {
    // Guards against false-positive negative tests: prove the same pipeline lets
    // VALID input through. A design_kit write is intentionally omitted — that path
    // needs the information_schema mirror (see WriteDesignKitTest); the bad-hex
    // case above already proves the colour regex fires.
    $pro = createTenant('valid-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', [
            'skeleton_id' => 'skeleton-3',
            'settings' => ['hero_title' => 'Hello'],
        ])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('skeleton_id'))
        ->toBe('skeleton-3');
});
