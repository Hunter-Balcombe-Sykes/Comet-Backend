<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    // LIFE-2: requestBuild() now takes a pg_advisory_xact_lock inside the build
    // transaction for every signup-path build (no staff actor) — without the shim
    // this errors on SQLite (no such function).
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

it('accepts a valid signup build and returns 202 with a build id', function () {
    $res = $this->postJson('/api/public/signup/build', [
        'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => '@JaneDoe',
    ]);

    $res->assertStatus(202)->assertJsonStructure(['build_id', 'build_state']);
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
});

it('re-serves an existing live build with 200 and its original account_type', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe'])->assertStatus(202);

    $res = $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'JaneDoe']);
    $res->assertStatus(200)->assertJsonPath('account_type', 'partna');
});

it('rejects a bad pairing with 422', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'google_business', 'source_ref' => 'ChIJx', 'source_name' => 'Cafe'])
        ->assertStatus(422)->assertJsonPath('code', 'SOURCE_PAIRING_INVALID');
});

it('requires source_name for google_business builds', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'business', 'source_type' => 'google_business', 'source_ref' => 'ChIJx'])
        ->assertStatus(422);
});

it('403s with WAITLIST_ONLY when the waitlist gate is on (moved from bootstrap)', function () {
    config(['partna.waitlist.enabled' => true]);
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe'])
        ->assertStatus(403)->assertJsonPath('code', 'WAITLIST_ONLY');
});

it('polls a build through its lifecycle and exposes subdomain only when ready', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe']);
    $build = PreAccountBuild::firstOrFail();

    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()->assertJsonPath('build_state', 'pending')->assertJsonMissingPath('subdomain');

    $build->update(['build_state' => PreAccountBuild::STATE_READY]);
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()->assertJsonPath('subdomain', $build->user->site->subdomain);
});

it('404s an unknown build id (public enumeration-safe)', function () {
    $this->getJson('/api/public/signup/builds/'.Str::uuid())->assertStatus(404);
});
