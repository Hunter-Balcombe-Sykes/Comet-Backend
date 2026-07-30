<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Authz\AuthzTestCase;

// Declared per FILE, not via a directory-level Pest.php: verified 2026-07-30
// that Pest auto-loads only the ROOT tests/Pest.php, so a tests/Authz/Pest.php
// carrying `uses(...)->in('Authz')` never executes and every test in this lane
// silently runs against PHPUnit\Framework\TestCase with no application booted.
// Every file in tests/Authz/ must carry this line.
uses(AuthzTestCase::class);

it('runs against a real Postgres with migrations applied', function () {
    expect(DB::connection('pgsql')->getDriverName())->toBe('pgsql');

    $ok = DB::connection('pgsql')->selectOne(
        "SELECT to_regclass('core.users') IS NOT NULL AS ok"
    );

    expect($ok->ok)->toBeTrue();
});

it('can authenticate a request via actingAsUser (risk 1 spike)', function () {
    // core.users.auth_user_id is a real FK onto auth.users, which the CI shim
    // stands up as an id+email table. SQLite never enforced this, so the
    // factory's random uuid is fine there and a 23503 here. Seed the parent row.
    $authUserId = (string) Str::uuid();
    DB::connection('pgsql')->table('auth.users')->insert([
        'id' => $authUserId,
        'email' => 'smoke@authz.test',
    ]);

    $user = User::factory()->create(['auth_user_id' => $authUserId]);

    $response = actingAsUser($user)->getJson('/api/me');

    // The assertion is about REACHABILITY, not the payload: any status other
    // than 401 proves the middleware stubs were applied and the route ran as
    // this user. A 401 means actingAsUser() did not take effect in this lane.
    expect($response->status())->not->toBe(401);
});
