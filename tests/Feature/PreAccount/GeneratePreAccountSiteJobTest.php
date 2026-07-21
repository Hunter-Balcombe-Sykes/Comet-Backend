<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
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
    $build->forceFill(['claimed_at' => now(), 'build_state' => PreAccountBuild::STATE_READY])->save(); // B11 SEC-4

    (new GeneratePreAccountSiteJob($build->id))->handle(app(SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
});

it('notifies via email when a published build with contact_email reaches ready', function () {
    Mail::fake();
    // Real dispatch runs inline under QUEUE_CONNECTION=sync (tests) and would hit
    // missing alias-table setup unrelated to this test — fake it like the sibling
    // publish test above.
    Queue::fake([SyncSubdomainToKvJob::class]);
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram', 'contact_email' => 'lead@example.com']);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // Stub the generator so no real scrape runs. Must implement SiteSourceGenerator —
    // SourceGeneratorRegistry::for() is typed to return it, so an anon class that
    // merely duck-types generate() trips a TypeError (caught as a scrape failure).
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate($user, $site, $ref): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, publish: true))->handle(app(SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});

it('does not notify when an unpublished build with contact_email reaches ready', function () {
    Mail::fake();
    // Dark (visitor/early-access) builds must never send the claim invite early —
    // that's reserved for staff-published marketing builds. Mirrors the published
    // notify test above but with publish: false.
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedark', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram', 'contact_email' => 'lead-dark@example.com']);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // Stub the generator so no real scrape runs — must implement SiteSourceGenerator
    // (SourceGeneratorRegistry::for() is typed to return it; a duck-typed anon class
    // trips a TypeError caught as a scrape failure).
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate($user, $site, $ref): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, publish: false))->handle(app(SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
    Mail::assertNothingQueued();
});

it('deactivates the IG connection for a dark early-access build', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_jane', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS, 'expires_at' => null,
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // Generator stub that seeds an ACTIVE IG connection (mirrors the real seeder).
    // Must implement SiteSourceGenerator — SourceGeneratorRegistry::for() is typed
    // to return it, so a duck-typed anon class trips a TypeError.
    $this->mock(SourceGeneratorRegistry::class, function ($mock) use ($user) {
        $gen = new class($user) implements SiteSourceGenerator
        {
            public function __construct(private User $user) {}

            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate(User $user, Site $site, string $sourceRef): void
            {
                IntegrationConnection::create([
                    'user_id' => $this->user->id, 'platform' => 'instagram',
                    'resource_id' => 'instagram', 'payload' => [], 'is_active' => true,
                ]);
            }
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, publish: false))->handle(app(SourceGeneratorRegistry::class));

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->first();
    expect((bool) $conn->is_active)->toBeFalse();
});
