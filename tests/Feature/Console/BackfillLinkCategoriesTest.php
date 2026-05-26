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

it('backfills settings.category=social for pre-existing instagram links', function () {
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
    expect($block->settings['platform'] ?? null)->toBe('instagram');
    expect($block->settings['category'] ?? null)->toBe('social');
});

it('backfills settings.category=other for custom (icon_key=link) blocks', function () {
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
    expect($block->settings['category'] ?? null)->toBe('other');
});

it('is idempotent — existing category is preserved on re-run', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

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
        'settings' => ['platform' => 'instagram', 'category' => 'events'], // manually overridden
    ]);

    Artisan::call('partna:backfill-social-links');

    $block->refresh();
    expect($block->settings['category'] ?? null)->toBe('events'); // preserved
});
