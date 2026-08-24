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

it('rejects an out-of-vocabulary selection on every selection column', function () {
    // SEC-5 extension. border_color and icon_color used to be tested here —
    // they carried the same hex-only regex as color_* because they landed in
    // the same inline-CSS surface. Both columns went with the 2026-08-09
    // preset-only migration, and color_accent is the only free-form value the
    // kit still holds (covered by the test above).
    //
    // What replaces them is the four SELECTIONS. Nothing but the request layer
    // enforces their vocabulary — there is no CHECK constraint behind any of
    // them (see DesignKitValidationRules' header) — so a weakened `in:` rule
    // would let a junk token reach the column and, from there, the design
    // system's preset lookup. This is the only tooth that vocabulary has.
    $pro = createTenant('selection-vocab-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['design_kit' => [
            'text_size' => 'gigantic',
            'spacing' => 'cosy',
            'corners' => 'pill',
            'border_thickness' => '2px',   // the OLD length grammar, now invalid
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'design_kit.text_size',
            'design_kit.spacing',
            'design_kit.corners',
            'design_kit.border_thickness',
        ]);
});

// The accept side of the same vocabulary lives in WriteDesignKitTest — it
// needs the seeded information_schema mirror, because a request that passes
// validation goes on to run writeDesignKit().

it('writes a valid architecture_id to the column', function () {
    // Back on the wire 2026-08-24 (reopens the 2026-08-20 lockdown): a
    // recognised value — 'scroll', the second architecture — now persists.
    $pro = createTenant('scroll-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['architecture_id' => 'scroll'])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('scroll');
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
            'settings' => ['booking_mode' => 'manual'],
        ])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});

it('rejects a genuinely unknown architecture id with a 422', function () {
    // Rule::in(Site::ARCHITECTURE_IDS) is the tooth: an out-of-vocabulary
    // value 422s rather than silently getting dropped, so a typo in a client
    // never reads back as a quiet no-op.
    $pro = createTenant('unknown-architecture');

    actingAsUser($pro)
        ->patchJson('/api/site', ['architecture_id' => 'brutalist'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['architecture_id']);

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});

it('rejects retired legacy architecture ids, still ignores the dropped skeleton_id field', function () {
    // The skeleton_id field alias and LEGACY_ARCHITECTURE_IDS collapse were
    // removed 2026-08-05 and stay gone — 'skeleton_id' is still an unknown
    // key, accepted and dropped. A legacy VALUE under the real field name is
    // different now (2026-08-24): architecture_id is validated again, so an
    // old id like 'bento' 422s instead of silently no-op'ing.
    $pro = createTenant('legacy-architecture-pro');

    actingAsUser($pro)
        ->patchJson('/api/site', ['architecture_id' => 'bento'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['architecture_id']);

    actingAsUser($pro)
        ->patchJson('/api/site', ['skeleton_id' => 'bento'])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});
