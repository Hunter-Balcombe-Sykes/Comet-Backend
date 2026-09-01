<?php

use App\Models\Core\Site\Site;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheLockService;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Media\MediaUrlResolver;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use App\Site\Sections\SectionCandidates;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    // CCH-11: pageOrder()/buildActions() now consult ContentPopularityReader's
    // lastReadFailed() and mark the SAME resolver degraded on a fault — so an
    // unprovisioned analytics.content_popularity_scores would fault every
    // build here and make every "does not mark degraded" assertion in this
    // file false for the wrong reason (an analytics blip, not the pool fault
    // each test is actually about). Provisioned empty, matching every other
    // fixture in this suite that has no popularity data.
    setupContentPopularityScoresTable();
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
                app(PoolSectionProvisioner::class),
                app(SectionCandidates::class),
                app(ContentItemSlugAllocator::class),
                app(MediaUrlResolver::class),
                app(\App\Services\Media\InstagramMediaUrl::class),
                app(ContentPopularityReader::class),
                app(CacheLockService::class),
            );
        }

        /** Every key buildPools() reads, so a missing one fails as a real bug and not as a fixture gap. */
        public static function emptyPool(): array
        {
            return ['selection' => [], 'library' => [], 'latestItemId' => null, 'collections' => [], 'diningModes' => null, 'stats' => null];
        }

        // PoolWire runs plan → hydrateItems → assemble since the 2026-08-24
        // batching, so the per-pool poison moved from resolve() to
        // assemble() — the per-pool phase — and the shared phases answer
        // inertly. resolve() keeps the same behaviour for any single-pool
        // caller.
        public function preloadSections(Site $site, array $pools): array
        {
            $out = [];
            foreach ($pools as $pool) {
                $out[$pool] = (object) ['id' => 'section-'.$pool];
            }

            return $out;
        }

        public function preloadCuration(array $sections): array
        {
            return [];
        }

        public function plan(Site $site, string $pool, ?object $section = null, ?Collection $curation = null): array
        {
            return ['pinned' => [], 'ruleIds' => [], 'autoSet' => [], 'selectionIds' => [], 'libraryIds' => []];
        }

        // #API-7: signature must track PoolResolver::hydrateItems()'s new
        // trailing $withDuplicateCandidates param (LSP) — same reason as
        // $withLibrary below. PHP fatals at CLASS-DECLARATION time when a
        // child drops a parent's optional param, so getting this wrong shows
        // up as pest exiting 2 with no output at all, not as a test failure.
        public function hydrateItems(Site $site, array $ids, bool $withDuplicateCandidates = false): array
        {
            return [[], collect()];
        }

        // SCALE-2: signature must track PoolResolver::assemble()'s new
        // trailing $withLibrary param (LSP) — unused here since neither
        // branch below reads the library.
        public function assemble(Site $site, string $pool, array $plan, array $payloads, Collection $stores, bool $withLibrary = true): array
        {
            if ($pool === $this->failingPool) {
                throw new QueryException('pgsql', 'select * from content.items', [], new RuntimeException('server closed the connection unexpectedly'));
            }

            return $this->answers[$pool] ?? self::emptyPool();
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
    $pro = createTenant('deg-'.Str::lower(Str::random(6)));
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
    // The payload casts an empty pools map to an object so it serialises as
    // `{}` and not `[]`; cast back before asserting on keys.
    $pools = (array) ($payload['profile']['pools'] ?? []);

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
    $pro = createTenant('ok-'.Str::lower(Str::random(6)));
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
    $pro = createTenant('wire-'.Str::lower(Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();

    $resolver = app(SitepageDataResolverService::class);
    app()->instance(SitepageDataResolverService::class, $resolver);
    app()->instance(PoolResolver::class, degradedPoolResolver('shop', []));

    expect($resolver->hasDegraded())->toBeFalse();

    app(IndividualProfilePayloadBuilder::class)->build($pro, $site);

    expect($resolver->hasDegraded())->toBeTrue();
});

it('bails on the whole lane — and does NOT mark degraded — when the content tables do not exist at all', function () {
    // The other half of the branch, and a real regression caught by review-by-
    // test-suite: the first cut of #LIFE-6 treated "content.* does not exist"
    // as "one pool failed" and continued the loop. resolve() provisions a
    // section row as a SIDE EFFECT, so continuing minted a section for every
    // pool while content.* was absent, and ActionCandidates -> PoolWire
    // is not guarded against that pairing: the public profile endpoint went
    // from 200 to 500 in any environment without the content lane.
    //
    // "A missing lane yields no pools, never a 500" is the contract the
    // original `return []` kept, and it still has to hold. It is also not a
    // DEGRADATION — the schema is missing, not unwell — so the payload must
    // cache at the normal TTL rather than the 10s degraded one.
    $pro = createTenant('nolane-'.Str::lower(Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();

    app()->instance(PoolResolver::class, new class extends PoolResolver
    {
        public function __construct() {}

        /** The lane-absent throw fires on the FIRST shared pre-read now
         * (2026-08-24 batching) — same failure, earliest seam. */
        public function preloadSections(Site $site, array $pools): array
        {
            $this->throwLaneAbsent();
        }

        public function resolve(Site $site, string $pool): array
        {
            $this->throwLaneAbsent();
        }

        private function throwLaneAbsent(): never
        {
            // Exactly what SQLite raises for an unprovisioned lane, and what
            // Postgres raises as SQLSTATE 42P01.
            throw new QueryException(
                'pgsql',
                'select * from "content"."items"',
                [],
                new RuntimeException('SQLSTATE[HY000]: General error: 1 no such table: content.items'),
            );
        }

        public function hasSelection(Site $site, string $pool): bool
        {
            return false;
        }
    });

    $builder = app(IndividualProfilePayloadBuilder::class);
    $payload = $builder->build($pro, $site);

    expect((array) ($payload['profile']['pools'] ?? []))->toBe([]);
    expect($builder->lastBuildDegraded())->toBeFalse();
});

it('recognises the POSTGRES undefined-table code, not just the SQLite message', function () {
    // Review gap, closed. The case above injects a SQLite-shaped error, so it
    // only exercises the str_contains('no such table') arm — and SQLite is the
    // arm that does NOT run in production. This one carries SQLSTATE 42P01 with
    // a message containing no such substring, so it can only pass via
    // $e->getCode(). If that arm were dead, production would take the DEGRADED
    // branch on a genuinely missing table and mint a section row per pool: the
    // exact 500 this branch exists to prevent, reachable only on Postgres and
    // invisible to the whole SQLite suite.
    $pro = createTenant('pg42-'.Str::lower(Str::random(6)));
    $site = Site::where('user_id', $pro->id)->first();

    app()->instance(PoolResolver::class, new class extends PoolResolver
    {
        public function __construct() {}

        /** Same seam move as the SQLite case above (2026-08-24 batching). */
        public function preloadSections(Site $site, array $pools): array
        {
            $this->throwUndefinedTable();
        }

        public function resolve(Site $site, string $pool): array
        {
            $this->throwUndefinedTable();
        }

        private function throwUndefinedTable(): never
        {
            $previous = new class('SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "content.items" does not exist') extends PDOException
            {
                public function __construct(string $message)
                {
                    parent::__construct($message);
                    // PDO reports SQLSTATE as a STRING code; Exception::$code is
                    // protected, so a subclass is the only way to set it here.
                    $this->code = '42P01';
                }
            };

            throw new QueryException('pgsql', 'select * from "content"."items"', [], $previous);
        }

        public function hasSelection(Site $site, string $pool): bool
        {
            return false;
        }
    });

    $builder = app(IndividualProfilePayloadBuilder::class);
    $payload = $builder->build($pro, $site);

    expect((array) ($payload['profile']['pools'] ?? []))->toBe([]);
    expect($builder->lastBuildDegraded())->toBeFalse();
});
