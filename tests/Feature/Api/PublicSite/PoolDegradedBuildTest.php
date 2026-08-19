<?php

use App\Models\Core\Site\Site;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Pools\PoolResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// #LIFE-6 (also #CCH-3, #API-3 — one defect, three ids).
//
// buildPools() caught QueryException and did `return []`, which discarded EVERY
// pool because ONE of them threw — including pools it had already resolved — and
// then let that empty result cache for the full 60s payload TTL. The failure is
// most likely under database load, i.e. exactly when a page is popular, and it
// presents as "this person's sitepage suddenly has no content" for a minute.
//
// The degraded machinery to prevent that already existed (degradedCacheTtl(),
// the controller's shortenDegraded() rewrite) but ONLY safeQuery() could arm it,
// and the pool lane does not go through safeQuery(). So the two halves never met.
//
// These are READ paths. The three-lane cache contract (BuildState::bump +
// site.sites.updated_at + edge purge) is for owner-initiated MUTATIONS and does
// not apply here — no lane busts belong in this test or in the fix.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupMediaTables();
    setupIngestTables();
    setupContentTables();
    // The real mirror of site.design_kits — loadDesignKit() reads it on every build.
    setupDesignKitsTable();

    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN architecture_id TEXT NOT NULL DEFAULT 'staple'");
    } catch (Throwable) {
        // Already added by an earlier test in this process.
    }
});

/**
 * A PoolResolver that throws for ONE named pool and answers normally for the
 * rest. Subclassed rather than mocked so every pool except the poisoned one runs
 * the REAL resolver — otherwise the test proves nothing about the surviving
 * pools actually being built.
 */
function degradedPoolResolver(string $failingPool, array $answers): PoolResolver
{
    return new class($failingPool, $answers) extends PoolResolver
    {
        public function __construct(private string $failingPool, private array $answers)
        {
            // The parent's collaborators ARE still needed: only resolve() is
            // overridden, and SitepageDataResolverService calls hasSelection()
            // on this same instance, which reaches for $provisioner. Forward
            // the real container-resolved dependencies rather than skipping
            // the constructor — a subclass that leaves typed properties
            // uninitialised fails somewhere unrelated and looks like a bug in
            // the code under test.
            parent::__construct(
                app(App\Site\Pools\PoolSectionProvisioner::class),
                app(App\Site\Sections\SectionCandidates::class),
                app(App\Services\Content\ContentItemSlugAllocator::class),
                app(App\Services\Media\MediaUrlResolver::class),
                app(App\Services\Analytics\ContentPopularityReader::class),
                app(App\Services\Cache\CacheLockService::class),
            );
        }

        /** Every key buildPools() reads, so a missing one fails as a real bug and not as a fixture gap. */
        public static function emptyPool(): array
        {
            return ['selection' => [], 'library' => [], 'latestItemId' => null, 'collections' => [], 'diningModes' => null, 'stats' => null];
        }

        public function resolve(Site $site, string $pool): array
        {
            if ($pool === $this->failingPool) {
                throw new QueryException('pgsql', 'select * from content.items', [], new RuntimeException('server closed the connection unexpectedly'));
            }

            return $this->answers[$pool] ?? self::emptyPool();
        }
    };
}

it('drops only the pool that failed, still publishes the others, and marks the build degraded', function () {
    $pro = createTenant('deg-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();

    $surviving = [
        'selection' => [['id' => 'item-1', 'kind' => 'video', 'headline' => 'Survivor']],
        'library' => [],
        'latestItemId' => 'item-1',
        'collections' => [],
        'diningModes' => null,
        'stats' => null,
    ];

    app()->instance(PoolResolver::class, degradedPoolResolver('shop', ['media' => $surviving]));

    $builder = app(IndividualProfilePayloadBuilder::class);
    $payload = $builder->build($pro, $site);
    $pools = $payload['profile']['pools'] ?? [];

    // The load-bearing assertion. Before the fix this was [] — the media pool
    // was thrown away because the shop pool threw.
    expect($pools)->toHaveKey('media');
    expect($pools['media']['items'][0]['headline'])->toBe('Survivor');

    // And the pool that actually failed is simply absent, not faked.
    expect($pools)->not->toHaveKey('shop');

    // Degraded, so IndividualProfileController rewrites BOTH cache keys at the
    // 10s degraded TTL instead of letting a partial payload sit for the full 60s
    // (plus its x10 stale twin).
    expect($builder->lastBuildDegraded())->toBeTrue();
    expect($builder->degradedCacheTtl())->toBeLessThan($builder->cacheTtl());
});

it('does not mark a build degraded when every pool resolves', function () {
    $pro = createTenant('ok-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();

    // No failing pool at all — '' matches none of the registry keys.
    app()->instance(PoolResolver::class, degradedPoolResolver('', []));

    $builder = app(IndividualProfilePayloadBuilder::class);
    $builder->build($pro, $site);

    // Non-vacuity guard for the case above: if this ever returns true, the
    // degraded flag is stuck on and the assertion above proves nothing.
    expect($builder->lastBuildDegraded())->toBeFalse();
});

it('shares one resolver instance, so a pool fault is visible to the controller that reads hasDegraded()', function () {
    // The wiring #LIFE-6 turns on: the builder marks the flag on the SAME
    // SitepageDataResolverService the controller later asks. If these two ever
    // resolve to different instances the fix is silently inert.
    $pro = createTenant('wire-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();

    $resolver = app(SitepageDataResolverService::class);
    app()->instance(SitepageDataResolverService::class, $resolver);
    app()->instance(PoolResolver::class, degradedPoolResolver('shop', []));

    expect($resolver->hasDegraded())->toBeFalse();

    app(IndividualProfilePayloadBuilder::class)->build($pro, $site);

    expect($resolver->hasDegraded())->toBeTrue();
});
