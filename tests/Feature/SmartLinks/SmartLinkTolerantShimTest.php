<?php

use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Phase-1 tolerant shim: SmartLinks is removed, but the 7 dashboard endpoints
// stay registered returning empty success shapes so the live frontend never
// 404/500s during the cutover. Phase 2 deletes these endpoints + this test.
//
// Routes live at the bare `/api/smart-links` prefix (routes/api/user.php is
// mounted at `/api`, NOT `/api/professional`). actingAsUser() stubs the
// Supabase JWT middleware to inject the resolved user (Auth::user() is null
// under Supabase auth).
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function shimUser(string $handle): User
{
    return createTenant($handle);
}

it('GET /smart-links returns an empty list', function () {
    actingAsUser(shimUser('shimidx'))->getJson('/api/smart-links')
        ->assertOk()
        ->assertExactJson(['smart_links' => []]);
});

it('POST /smart-links/preview is a tolerant no-op', function () {
    actingAsUser(shimUser('shimprev'))->postJson('/api/smart-links/preview', ['url' => 'https://example.com'])
        ->assertOk()
        ->assertExactJson(['smart_link' => null]);
});

it('POST /smart-links is a tolerant no-op', function () {
    actingAsUser(shimUser('shimstore'))->postJson('/api/smart-links', ['url' => 'https://example.com'])
        ->assertOk()
        ->assertExactJson(['smart_link' => null]);
});

it('POST /smart-links/reorder is a tolerant no-op', function () {
    actingAsUser(shimUser('shimreorder'))->postJson('/api/smart-links/reorder', ['family' => 'commerce', 'ids' => []])
        ->assertOk()
        ->assertExactJson(['smart_link' => null]);
});

it('PATCH /smart-links/{id} is a tolerant no-op', function () {
    actingAsUser(shimUser('shimupd'))->patchJson('/api/smart-links/'.Str::uuid())
        ->assertOk()
        ->assertExactJson(['smart_link' => null]);
});

it('POST /smart-links/{id}/refresh is a tolerant no-op', function () {
    actingAsUser(shimUser('shimref'))->postJson('/api/smart-links/'.Str::uuid().'/refresh')
        ->assertOk()
        ->assertExactJson(['smart_link' => null]);
});

it('DELETE /smart-links/{id} returns 204', function () {
    actingAsUser(shimUser('shimdel'))->deleteJson('/api/smart-links/'.Str::uuid())
        ->assertNoContent();
});
