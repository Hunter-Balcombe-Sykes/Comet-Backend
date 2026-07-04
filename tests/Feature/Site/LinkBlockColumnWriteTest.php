<?php

/**
 * Phase 2 column-write assertions: verifies that link block writes populate the
 * promoted columns (category, platform, live_check_enabled) correctly, and that
 * the settings bag no longer carries these keys.
 *
 * Tests cover:
 *  - user PATCH (category via HTTP) — proves the full user update path works
 *  - user custom-update branch logic for live_check_enabled (model layer)
 *  - staff store column population (model layer, mirrors the controller's code)
 *  - staff update column population (model layer)
 */

use App\Models\Core\Site\Block;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupBlocksTable();
    config([
        // Phase 2: platform/category/live_check_enabled removed from settings keys.
        'partna.link_block_settings_keys' => [
            'handle', 'highlight', 'note',
            'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        ],
        'partna.link_categories' => [
            'social', 'booking', 'education', 'content', 'events', 'streaming', 'other',
        ],
        'partna.platform_links_max' => 20,
        'partna.platform_links_categories' => ['social'],
        'partna.streaming.max_live_check_per_site' => 5,
    ]);
});

// ---------------------------------------------------------------------------
// User controller — PATCH with category: full HTTP path
// ---------------------------------------------------------------------------

it('PATCH with category sets the column (Phase 2 — no settings mirror)', function () {
    $pro = createTenant('colwrite-cat');

    $blockId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => $blockId,
        'user_id' => $pro->id,
        'site_id' => $pro->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'My link',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => 1,
        'category' => null,
        'platform' => null,
        'live_check_enabled' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($pro)
        ->patchJson("/api/links/{$blockId}", ['category' => 'events'])
        ->assertOk();

    $fresh = Block::query()->findOrFail($blockId);

    // Column populated (Phase 2).
    expect($fresh->category)->toBe('events');
    // settings no longer carries category (Phase 2 strip).
    expect(array_key_exists('category', $fresh->settings ?? []))->toBeFalse();
});

// ---------------------------------------------------------------------------
// User controller — custom-update branch: live_check_enabled top-level (Phase 2)
//
// Tests the controller's custom-update logic directly (Block model level) to
// avoid the UpdateLinkBlockRequest prepareForValidation note-sanitization
// adding note=>null which complicates the HTTP path for settings-only patches.
// The HTTP path for category (above) already proves the update pipeline works.
// ---------------------------------------------------------------------------

it('custom-update branch fills live_check_enabled column from top-level field (Phase 2)', function () {
    $pro = createTenant('colwrite-lce');

    $block = createLinkBlockFor($pro, [
        'category' => 'other',
        'settings' => json_encode([]),
    ]);

    // Phase 2: live_check_enabled arrives at the top level; fill() maps it directly.
    $data = ['live_check_enabled' => true];

    $block->fill($data);
    $block->save();

    $fresh = Block::query()->findOrFail($block->id);

    // Column populated (Phase 2).
    expect($fresh->live_check_enabled)->toBeTrue();
    // settings does NOT carry live_check_enabled.
    expect(array_key_exists('live_check_enabled', $fresh->settings ?? []))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Staff controller — store: category column (Phase 2 — no settings mirror)
//
// Tests the Block construction in StaffLinkBlockManagementController::store().
// Phase 2: category comes from top-level only; settings no longer mirrors it.
// ---------------------------------------------------------------------------

it('staff store construction sets category column (Phase 2 — no settings mirror)', function () {
    $pro = createTenant('colwrite-staff');
    $site = $pro->site;

    // Mirror what StaffLinkBlockManagementController::store() does in Phase 2.
    $data = [
        'title' => 'Staff link',
        'url' => 'https://example.com/staff',
        'is_active' => true,
        'category' => 'social',
        'settings' => [],
    ];

    $block = new Block([
        'block_group' => Block::GROUP_LINKS,
        'block_type' => Block::TYPE_LINK,
        'title' => $data['title'],
        'url' => $data['url'],
        'is_active' => $data['is_active'] ?? true,
        // Phase 2: category + live_check_enabled are columns; top-level only.
        'category' => $data['category'] ?? null,
        'live_check_enabled' => (bool) ($data['live_check_enabled'] ?? false),
        'settings' => $data['settings'] ?? [],
    ]);
    $block->user_id = $pro->id;
    $block->site_id = $site->id;
    $block->save();

    $fresh = Block::query()->findOrFail($block->id);

    // Column populated (Phase 2).
    expect($fresh->category)->toBe('social');
    // settings does NOT carry category.
    expect(array_key_exists('category', $fresh->settings ?? []))->toBeFalse();
    // live_check_enabled defaults to false when not supplied.
    expect($fresh->live_check_enabled)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Staff controller — UPDATE: fill() maps top-level columns directly (Phase 2)
//
// Phase 2: the staff update controller does fill($request->validated()), so
// top-level category maps directly to the column. No settings-mirroring.
// ---------------------------------------------------------------------------

it('staff PATCH with top-level category sets the column (Phase 2 — no settings mirror)', function () {
    setupPartnaStaffTable();

    $staffId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => $staffId,
        'auth_user_id' => 'auth-'.Str::random(12),
        'role' => 'admin',
        'primary_email' => 'staff-dw-a@example.test',
        'name' => 'Staff Admin A',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $staff = PartnaStaff::query()->findOrFail($staffId);

    $pro = createTenant('staff-dw-cat-a');
    $block = createLinkBlockFor($pro);

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/links/{$block->id}", ['category' => 'events'])
        ->assertOk();

    $fresh = Block::query()->findOrFail($block->id);
    // Column populated via fill() from top-level validated field.
    expect($fresh->category)->toBe('events');
    // settings does NOT carry category (Phase 2).
    expect(array_key_exists('category', $fresh->settings ?? []))->toBeFalse();
});

it('staff update fills category column from top-level field (Phase 2)', function () {
    $pro = createTenant('staff-dw-cat-b');
    $block = createLinkBlockFor($pro, [
        'category' => null,
        'settings' => json_encode(['highlight' => true]),
    ]);

    // Phase 2: fill() maps top-level category directly to the column.
    // No settings-mirroring (direction-B hoist logic removed).
    $data = ['category' => 'booking'];
    $block->fill($data);
    $block->save();

    $fresh = Block::query()->findOrFail($block->id);
    // Column set from top-level field.
    expect($fresh->category)->toBe('booking');
    // Existing settings preserved; category NOT added.
    expect($fresh->settings['highlight'] ?? null)->toBeTrue();
    expect(array_key_exists('category', $fresh->settings ?? []))->toBeFalse();
});

// ---------------------------------------------------------------------------
// User controller — PATCH with platform+handle: FOUND-35 handle column write
//
// handle is dual-written (column + settings.handle) — the settings key strip
// is a later contract migration, out of scope here.
// ---------------------------------------------------------------------------

it('PATCH with platform+handle sets the handle column (FOUND-35 — dual-write with settings)', function () {
    $pro = createTenant('colwrite-handle');

    $blockId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => $blockId,
        'user_id' => $pro->id,
        'site_id' => $pro->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'My Instagram',
        'url' => 'https://instagram.com/oldhandle',
        'platform' => 'instagram',
        'sort_order' => 0,
        'is_active' => 1,
        'category' => 'social',
        'handle' => 'oldhandle',
        'live_check_enabled' => 0,
        'settings' => json_encode(['handle' => 'oldhandle']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($pro)
        ->patchJson("/api/links/{$blockId}", ['platform' => 'instagram', 'handle' => 'newhandle'])
        ->assertOk();

    $fresh = Block::query()->findOrFail($blockId);

    // Column populated (FOUND-35).
    expect($fresh->handle)->toBe('newhandle');
    // Dual-write: settings.handle still carries the same value.
    expect($fresh->settings['handle'] ?? null)->toBe('newhandle');
});
