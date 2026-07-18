<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

function makePendingBuild(bool $publish = false): PreAccountBuild
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);
    $build = PreAccountBuild::factory()->make();
    $build->user()->associate($user);
    $build->save();

    return $build;
}

function bindGenerator(?Closure $behaviour = null): void
{
    $gen = Mockery::mock(SiteSourceGenerator::class);
    $exp = $gen->shouldReceive('generate')->once();
    if ($behaviour) {
        $exp->andReturnUsing($behaviour);
    }
    config(['partna.pre_account.generators.instagram' => get_class($gen)]);
    app()->instance(get_class($gen), $gen);
}

it('runs the generator and flips pending → ready', function () {
    $build = makePendingBuild();
    bindGenerator();

    (new GeneratePreAccountSiteJob($build->id))->handle(app(SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
});

it('records failure_code and flips to failed on SourceGenerationException', function () {
    $build = makePendingBuild();
    bindGenerator(fn () => throw SourceGenerationException::sourceNotFound());

    (new GeneratePreAccountSiteJob($build->id))->handle(app(SourceGeneratorRegistry::class));

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($fresh->failure_code)->toBe(PreAccountBuild::FAILURE_SOURCE_NOT_FOUND);
});

it('publishes the site + re-syncs KV for staff publish builds', function () {
    Queue::fake([SyncSubdomainToKvJob::class]);
    $build = makePendingBuild();
    bindGenerator();

    (new GeneratePreAccountSiteJob($build->id, publish: true))->handle(app(SourceGeneratorRegistry::class));

    expect($build->user->fresh()->site->is_published)->toBeTrue();
    Queue::assertPushed(SyncSubdomainToKvJob::class);
});

it('no-ops on a claimed or already-ready build', function () {
    $build = makePendingBuild();
    $build->update(['claimed_at' => now(), 'build_state' => PreAccountBuild::STATE_READY]);

    (new GeneratePreAccountSiteJob($build->id))->handle(app(SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
});
