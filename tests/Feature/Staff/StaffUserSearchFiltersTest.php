<?php

/**
 * OV-A — StaffUserController::index gains sector + account_type filters and
 * q matches sector text.
 */

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    DB::connection('pgsql')->statement('DELETE FROM core.users');
});

function ovaSearchUser(array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(array_merge([
        'id' => $id,
        'handle' => 'search-'.Str::random(8),
        'display_name' => 'Search Target',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

function ovaSearchIds(Request $request): array
{
    $response = app(StaffUserController::class)->index($request);
    $body = json_decode($response->getContent(), true);

    return array_column($body['professionals'], 'id');
}

it('filters by sector exactly', function () {
    $dj = ovaSearchUser(['sector' => 'dj']);
    ovaSearchUser(['sector' => 'hairdresser']);

    $ids = ovaSearchIds(Request::create('/', 'GET', ['sector' => 'dj']));

    expect($ids)->toBe([$dj]);
});

it('filters by account_type; staff is no longer an accepted value', function () {
    ovaSearchUser(['account_type' => 'partna']);
    $biz = ovaSearchUser(['account_type' => 'business']);
    ovaSearchUser(['account_type' => 'partna']);

    expect(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'business'])))->toBe([$biz])
        // 'staff' is no longer an accepted filter → treated like any unknown value → ignored.
        ->and(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'staff'])))->toHaveCount(3)
        ->and(ovaSearchIds(Request::create('/', 'GET', ['account_type' => 'bogus'])))->toHaveCount(3);
});

// NOTE: the q ILIKE path (which now also matches sector) is Postgres-only
// syntax — the SQLite test mirror can't execute it, same as the pre-existing
// handle/email ILIKE branches, so it stays covered by prod behaviour only.
