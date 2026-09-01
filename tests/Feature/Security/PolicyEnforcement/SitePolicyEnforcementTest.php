<?php

use App\Models\Core\Site\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
    setupSubdomainAliasesTable();
});

// The three gallery-image cases retired with UserGalleryController (Wave 6,
// 2026-09-02) — SitePolicy's owner/non-owner/pending-deletion enforcement
// stays pinned by the Menu cases below on the same policy methods.
it('allows the owner to update their own Menu via SitePolicy', function () {
    $owner = createTenant('menu-policy-owner');
    $menu = Menu::create(['user_id' => $owner->id, 'content_source' => 'scan']);

    expect(Gate::forUser($owner)->allows('update', $menu))->toBeTrue();
});

it('blocks a non-owner from updating another user\'s Menu with 404', function () {
    $owner = createTenant('menu-policy-owner-2');
    $intruder = createTenant('menu-policy-intruder');
    $menu = Menu::create(['user_id' => $owner->id, 'content_source' => 'scan']);

    $response = Gate::forUser($intruder)->inspect('update', $menu);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('blocks a pending-deletion owner from updating their own Menu with 423', function () {
    $owner = createTenant('menu-policy-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();
    $menu = Menu::create(['user_id' => $owner->id, 'content_source' => 'scan']);

    $response = Gate::forUser($owner)->inspect('update', $menu);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
