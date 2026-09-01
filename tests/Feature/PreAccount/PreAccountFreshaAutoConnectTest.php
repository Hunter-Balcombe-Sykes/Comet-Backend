<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\SourceGeneratorRegistry;
use App\Services\PreAccount\SourcePrefetch;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupIntegrationConnectionsTable();
});

it('passes autoConnectBooking TRUE for a self-serve site-first signup', function () {
    // R14: a public signup build has nobody sitting in front of a picker either —
    // the site is public from the moment it is built (the profiles route ignores
    // is_published for 'unclaimed') and may never be claimed. The v3 auto-route
    // design marks every Instagram-origin site true; only the dashboard paste is
    // false. built_by_staff_id was never the discriminator that design intended.
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Simon Doyle']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'simondoylehair', 'is_published' => false]);

    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // built_by_staff_id is not fillable (set via ->builtByStaff()->associate()),
    // so a plain signup build simply never gets one — that NULL is the condition
    // under test.
    expect($build->built_by_staff_id)->toBeNull();

    $seen = new stdClass;
    $seen->flag = null;

    $this->mock(SourceGeneratorRegistry::class, function ($mock) use ($seen) {
        $gen = new class($seen) implements SiteSourceGenerator
        {
            public function __construct(private stdClass $seen) {}

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

            public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
            {
                return new SourcePrefetch(payload: []);
            }

            public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void
            {
                $this->seen->flag = $autoConnectBooking;
            }
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, $build->source_type, publish: false))
        ->handle(app(SourceGeneratorRegistry::class));

    expect($seen->flag)->toBeTrue();
});
