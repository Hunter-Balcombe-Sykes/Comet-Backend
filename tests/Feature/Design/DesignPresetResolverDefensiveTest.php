<?php

use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignFactor;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\DesignPresetResolver;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\SiteDesignFactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();              // also creates site.platform_connections
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
});

// ── Harness ────────────────────────────────────────────────────────────────

/** A resolver wired with the given fake factors (defaults to none — dark launch). */
function dprResolver(array $factors = [], array $siteFactors = []): DesignPresetResolver
{
    return new DesignPresetResolver(new DesignFactorRegistry($factors, $siteFactors));
}

/** Raw-insert an active platform connection (bypasses model events). */
function dprSeedConnection(User $user, array $payload, string $platform = 'google-business'): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/** Fake connection factor whose detect() always throws — pins the resolver's try/catch degrade path. */
function dprThrowingFactor(): DesignFactor
{
    return new class implements DesignFactor
    {
        public function key(): string
        {
            return 'fake:throwing-connection';
        }

        public function integration(): string
        {
            return 'google-business';
        }

        public function mode(): FactorMode
        {
            return FactorMode::Auto;
        }

        public function priority(): int
        {
            return 50;
        }

        public function detect(IntegrationConnection $connection): array
        {
            throw new RuntimeException('boom: fake connection factor failure');
        }
    };
}

/**
 * Fake Auto-mode connection factor that mirrors a 'color' payload key straight
 * to color_accent. (Was color_bg — no longer factor-targetable, so the
 * resolver's allowlist filter would drop it; see plan 2026-07-10.)
 */
function dprAutoColorFactor(): DesignFactor
{
    return new class implements DesignFactor
    {
        public function key(): string
        {
            return 'fake:auto-color';
        }

        public function integration(): string
        {
            return 'google-business';
        }

        public function mode(): FactorMode
        {
            return FactorMode::Auto;
        }

        public function priority(): int
        {
            return 50;
        }

        public function detect(IntegrationConnection $connection): array
        {
            $color = data_get($connection->payload, 'color');

            return $color === null ? [] : ['color_accent' => $color];
        }
    };
}

/** Fake site-level factor whose detect() always throws — pins the resolver's try/catch degrade path. */
function dprThrowingSiteFactor(): SiteDesignFactor
{
    return new class implements SiteDesignFactor
    {
        public function key(): string
        {
            return 'fake:throwing-site';
        }

        public function integrationLabel(): string
        {
            return 'fake-site';
        }

        public function priority(): int
        {
            return 50;
        }

        public function detect(User $user, Site $site, Collection $activeConnections): array
        {
            throw new RuntimeException('boom: fake site factor failure');
        }
    };
}

it('breaks an equal-priority contest by source ascending, independent of row order', function () {
    // Two contributions, SAME target_var + SAME priority, different sources. The
    // smaller source ('aaa-source') must win in BOTH insertion orders. SQLite
    // returns rows in insertion order with no ORDER BY, so a naive last-wins /
    // first-wins / no-tiebreak (>=) impl would flip the winner when the order
    // flips — only the real source-ascending comparison (presetLayer line ~50)
    // is order-invariant.
    $orders = [
        'zzz-first' => [['zzz-source', '#zzzzzz'], ['aaa-source', '#aaaaaa']],
        'aaa-first' => [['aaa-source', '#aaaaaa'], ['zzz-source', '#zzzzzz']],
    ];

    foreach ($orders as $handle => $rows) {
        $user = createTenant('tie-break-'.$handle);
        $siteId = $user->site->id;
        foreach ($rows as [$source, $value]) {
            DesignKitContribution::query()->create([
                'site_id' => $siteId,
                'source' => $source,
                'integration' => 'test',
                'priority' => 50,
                'mode' => 'one_shot',
                'target_var' => 'color_bg',
                'value' => $value,
            ]);
        }

        expect(dprResolver()->presetLayer($siteId)['color_bg'])->toBe('#aaaaaa');
    }
});

it('degrades a throwing connection factor to no contribution instead of failing the resolve', function () {
    $user = createTenant('throwing-connection-factor');
    dprSeedConnection($user, ['category' => 'Restaurant']);

    $changed = dprResolver([dprThrowingFactor()])->resolveForUser($user);

    expect($changed)->toBeFalse();
    expect(dprResolver()->presetLayer($user->site->id))->toBe([]);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('degrades a throwing site factor to no contribution instead of failing the resolve', function () {
    $user = createTenant('throwing-site-factor');

    $changed = dprResolver([], [dprThrowingSiteFactor()])->resolveForUser($user);

    expect($changed)->toBeFalse();
    expect(dprResolver()->presetLayer($user->site->id))->toBe([]);
});

it('re-detects an auto-mode connection factor when the payload changes', function () {
    $user = createTenant('auto-recheck');
    $connId = dprSeedConnection($user, ['color' => '#111111']);
    $resolver = dprResolver([dprAutoColorFactor()]);

    $resolver->resolveForUser($user);
    expect($resolver->presetLayer($user->site->id)['color_accent'])->toBe('#111111');

    // The connection's data moves; an Auto factor must re-detect (not freeze like
    // a OneShot would) — mirror image of the one-shot freeze test in DesignPresetSystemTest.
    DB::connection('pgsql')->table('site.platform_connections')
        ->where('id', $connId)->update(['payload' => json_encode(['color' => '#222222'])]);

    $changed = $resolver->resolveForUser($user);

    expect($changed)->toBeTrue();
    expect($resolver->presetLayer($user->site->id)['color_accent'])->toBe('#222222');
});

it('returns an empty preset layer instead of throwing when the query fails', function () {
    $user = createTenant('query-failure');
    $siteId = $user->site->id;

    // Drop the table out from under presetLayer() to force its query to throw —
    // proves the defensive catch returns [] instead of breaking the render.
    DB::connection('pgsql')->statement('DROP TABLE site.design_kit_contributions');

    expect(dprResolver()->presetLayer($siteId))->toBe([]);
});
