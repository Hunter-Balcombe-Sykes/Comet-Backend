<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupIntegrationConnectionsTable(); // site.platform_connections — IntegrationConnection teardown step
    setupSiteMediaTable(); // AccountDeletionService::purgeMediaArtifacts() queries site.site_media
    setupSubdomainAliasesTable(); // SiteCacheService::invalidateSite() reads site_subdomain_aliases

    // QUEUE_CONNECTION=sync in tests — without faking, CloudflareCachePurgeJob
    // would fire a real CloudflarePurgeService HTTP call and DeleteMirroredMediaJob/
    // SyncSubdomainToKvJob would run for real.
    Queue::fake();
});

function makeExpiredBuild(): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'handle' => 'stale']);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'stale']);
    $build = PreAccountBuild::factory()->make(['expires_at' => now()->subDay()]);
    $build->user()->associate($user);
    $build->save();

    return [$user, $site, $build];
}

it('hard-deletes an expired unclaimed build and its user/site', function () {
    [$user, $site] = makeExpiredBuild();

    $this->artisan('builds:prune-expired')->assertSuccessful();

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(PreAccountBuild::query()->count())->toBe(0);

    // site.sites.user_id is ON DELETE CASCADE (migration 20260526000000) — the
    // row is gone on Postgres, but the SQLite test schema (tests/Pest.php) has
    // no FK enforcement, so the cascade itself can't be exercised here. This is
    // the same limitation AccountDeletionService::purge()'s precedent test
    // (PurgePendingDeletionTest) accepts for the same table — it never asserts
    // Site::find() either. The CloudflareCachePurgeJob dispatch below is the
    // SQLite-safe proxy: it only fires from inside the `if ($site)` branch, so
    // seeing it proves the command actually reached and processed the site.
    Queue::assertPushed(CloudflareCachePurgeJob::class, fn (CloudflareCachePurgeJob $job) => $job->handle === 'stale');
});

it('prunes failed builds older than the failed window, keeps fresh ones', function () {
    [$u1, , $b1] = makeExpiredBuild();
    // Direct attribute assignment (not update()) — 'updated_at' isn't in
    // PreAccountBuild::$fillable, so update()'s mass-assignment would silently
    // drop it and Eloquent's own updateTimestamps() would re-stamp it to "now"
    // (isDirty() only skips the auto-stamp when the column was actually set).
    $b1->expires_at = now()->addDays(20);
    $b1->build_state = PreAccountBuild::STATE_FAILED;
    $b1->updated_at = now()->subHours(30);
    $b1->save();

    $u2 = User::factory()->create(['status' => 'unclaimed', 'handle' => 'fresh']);
    Site::factory()->create(['user_id' => $u2->id, 'subdomain' => 'fresh']);
    $b2 = PreAccountBuild::factory()->make(['expires_at' => now()->addDays(29)]);
    $b2->user()->associate($u2);
    $b2->save();

    $this->artisan('builds:prune-expired')->assertSuccessful();

    expect(User::query()->find($u1->id))->toBeNull()
        ->and(User::query()->find($u2->id))->not->toBeNull();
});

it('never touches claimed builds', function () {
    [, , $build] = makeExpiredBuild();
    $build->update(['claimed_at' => now()]);

    $this->artisan('builds:prune-expired')->assertSuccessful();

    expect(PreAccountBuild::query()->count())->toBe(1);
});

it('supports --dry-run', function () {
    makeExpiredBuild();
    $this->artisan('builds:prune-expired --dry-run')->assertSuccessful();
    expect(PreAccountBuild::query()->count())->toBe(1);
});

it('isolates a per-candidate teardown fault and still prunes the remaining candidate', function () {
    [$u1, $s1, $b1] = makeExpiredBuild();
    $u2 = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'handle' => 'stale-two']);
    $s2 = Site::factory()->create(['user_id' => $u2->id, 'subdomain' => 'stale-two']);
    $b2 = PreAccountBuild::factory()->make(['expires_at' => now()->subDay()]);
    $b2->user()->associate($u2);
    $b2->save();

    // First candidate's cache invalidation throws — the per-candidate transaction
    // rolls back and the sweep must keep going instead of aborting entirely.
    $this->partialMock(SiteCacheService::class, function ($mock) use ($s1) {
        $mock->shouldReceive('invalidateSite')
            ->twice()
            ->andReturnUsing(function (Site $site) use ($s1) {
                if ($site->id === $s1->id) {
                    throw new RuntimeException('redis unavailable');
                }

                return null;
            });
    });

    $this->artisan('builds:prune-expired')->assertSuccessful();

    // Failed candidate: transaction rolled back, untouched, retried next run.
    expect(User::query()->find($u1->id))->not->toBeNull()
        ->and(PreAccountBuild::query()->find($b1->id))->not->toBeNull()
        // Successful candidate: still pruned despite the other candidate's fault.
        ->and(User::query()->find($u2->id))->toBeNull()
        ->and(PreAccountBuild::query()->find($b2->id))->toBeNull();
});
