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

it('rejects a non-hex border / icon colour', function () {
    // SEC-5 extension: border_color and icon_color flow into the same
    // inline-CSS surface as color_*, so they carry the hex-only regex too.
    // rgba()/named colours must 422 before reaching writeDesignKit.
    // (border_focus_color was dropped with its column — 2026-07-07 trait regen.)
    $pro = createTenant('hex-border-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => [
            'border_color' => 'red',
            'icon_color' => 'blue',
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'design_kit.border_color',
            'design_kit.icon_color',
        ]);
});

it('rejects an unknown architecture id', function () {
    $pro = createTenant('skel-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['architecture_id' => 'skeleton-9'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['architecture_id']);
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

it('accepts a valid architecture and settings (negative tests are not over-rejecting)', function () {
    // Guards against false-positive negative tests: prove the same pipeline lets
    // VALID input through. A design_kit write is intentionally omitted — that path
    // needs the information_schema mirror (see WriteDesignKitTest); the bad-hex
    // case above already proves the colour regex fires.
    $pro = createTenant('valid-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', [
            'architecture_id' => 'staple',
            'settings' => ['booking_mode' => 'manual'],
        ])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});

it('rejects a genuinely unknown architecture id', function () {
    $pro = createTenant('unknown-architecture');

    actingAsUser($pro)
        ->patchJson('/api/site', ['architecture_id' => 'brutalist'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['architecture_id']);
});

it('collapses every historical architecture id to one on write', function () {
    // The platform is single-architecture (2026-07-10). Any stale dashboard/chat
    // build sending an old id must succeed and store 'staple' — never 422, never
    // persist a layout that no longer renders.
    $pro = createTenant('legacy-architecture-pro');

    foreach (['skeleton-3', 'hub', 'sheet', 'bento', 'deck', 'atlas'] as $legacy) {
        actingAsUser($pro)
            ->patchJson('/api/site', ['architecture_id' => $legacy])
            ->assertOk();

        expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
            ->toBe('staple', "legacy id {$legacy} must collapse to 'staple'");
    }
});

it('accepts the legacy skeleton_id field name and collapses it (transition alias)', function () {
    // Old clients (pre-rename dashboards) still send skeleton_id. prepareForValidation
    // merges it into architecture_id, so the write must succeed and store 'staple'.
    $pro = createTenant('legacy-field-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['skeleton_id' => 'bento'])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});
