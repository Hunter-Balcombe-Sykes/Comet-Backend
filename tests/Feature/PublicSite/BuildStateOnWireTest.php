<?php

// F2 (2026-08-31 audit): a build still PENDING published a page carrying the
// person's name and nothing else — 8-10 minutes under a batch. The sitepage's
// preparing surface reads this key; without it the surface is inert, which is
// exactly the state this test was written to end.

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    Config::set('partna.throttle.enabled', false);
    Cache::flush();
});

function seedBuildStateUser(string $handle, ?string $buildState): void
{
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-0000000000b1',
        'auth_user_id' => null,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Build State',
        'first_name' => 'Build',
        'account_type' => 'partna',
        'status' => 'unclaimed',
    ]);

    if ($buildState !== null) {
        DB::connection('pgsql')->table('core.pre_account_builds')->insert([
            'id' => '00000000-0000-0000-0000-0000000000b2',
            'user_id' => '00000000-0000-0000-0000-0000000000b1',
            'source_type' => 'instagram',
            'source_ref' => $handle,
            'source_ref_lc' => $handle,
            'built_via' => 'staff',
            'build_state' => $buildState,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('exposes a pending build state on the public wire', function () {
    seedBuildStateUser('buildpending', 'pending');

    $this->getJson('/api/public/profiles/buildpending')
        ->assertOk()
        ->assertJsonPath('data.buildState', 'pending');
});

it('reports ready once the build completes', function () {
    seedBuildStateUser('buildready', 'ready');

    $this->getJson('/api/public/profiles/buildready')
        ->assertOk()
        ->assertJsonPath('data.buildState', 'ready');
});

it('is null for an account with no build at all', function () {
    seedBuildStateUser('buildnone', null);

    $this->getJson('/api/public/profiles/buildnone')
        ->assertOk()
        ->assertJsonPath('data.buildState', null);
});
