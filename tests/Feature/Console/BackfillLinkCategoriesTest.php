<?php

use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill command tests. Uses direct DB inserts to avoid a factory dependency
 * for Professional/Site — the backfill only cares about site.blocks rows; the
 * parent rows exist solely to satisfy FK constraints (SQLite doesn't enforce
 * them, but the helper keeps the test data coherent).
 *
 * Phase 2: the command writes to promoted columns (platform, category) instead
 * of settings JSONB. Idempotency is checked against the column values.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
});

function createBackfillFixtureIds(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    // Minimal Professional row — all columns nullable in the SQLite test schema.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$userId, $siteId];
}

it('backfills platform + category columns for pre-existing instagram links', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

    $block = Block::create([
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'My IG',
        'url' => 'https://instagram.com/someone',
        'icon_key' => 'instagram',
        'sort_order' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    Artisan::call('partna:backfill-social-links');

    $block->refresh();
    // Phase 2: reads promoted columns.
    expect($block->platform)->toBe('instagram');
    expect($block->category)->toBe('social');
});

it('backfills category=other column for custom (icon_key=link) blocks', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

    $block = Block::create([
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'My custom',
        'url' => 'https://example.com',
        'icon_key' => 'link',
        'sort_order' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    Artisan::call('partna:backfill-social-links');

    $block->refresh();
    // Phase 2: category column set; no platform (custom link).
    expect($block->category)->toBe('other');
    expect($block->platform)->toBeNull();
});

it('is idempotent — existing column values are preserved on re-run', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

    // Pre-set both columns (the idempotency gate reads columns in Phase 2).
    $block = Block::create([
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Already set',
        'url' => 'https://instagram.com/someone',
        'icon_key' => 'instagram',
        'sort_order' => 0,
        'is_active' => true,
        'platform' => 'instagram',
        'category' => 'events', // manually overridden
        'settings' => [],
    ]);

    Artisan::call('partna:backfill-social-links');

    $block->refresh();
    // Phase 2: column preserved (not overwritten on re-run).
    expect($block->category)->toBe('events');
    expect($block->platform)->toBe('instagram');
});
