<?php

use App\Jobs\Design\ResolveDesignPresetsJob;
use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\DesignPresetResolver;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();              // also creates site.platform_connections
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
});

/** A resolver wired with only the Google Business type factor. */
function dkPresetResolver(): DesignPresetResolver
{
    return new DesignPresetResolver(new DesignFactorRegistry([new GoogleBusinessTypeFactor]));
}

/** Raw-insert a platform connection (bypasses model events). */
function dkSeedConnection(User $user, array $payload, string $platform = 'google-business', bool $active = true): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => $active ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('applies the restaurant preset for a Google Business restaurant', function () {
    $user = createTenant('joes-diner');
    dkSeedConnection($user, ['category' => 'Italian restaurant', 'name' => "Joe's"]);

    $changed = dkPresetResolver()->resolveForUser($user);

    expect($changed)->toBeTrue();
    $layer = dkPresetResolver()->presetLayer($user->site->id);
    expect($layer['color_bg'])->toBe('#f7f4ee')
        ->and($layer['color_accent'])->toBe('#e0491f')
        ->and($layer['text_xs'])->toBe('0.8rem')
        ->and($layer['weight_regular'])->toBe('300')
        ->and($layer['border_radius'])->toBe('0.25rem')
        ->and($layer['typography_font_family'])->toBe('forma-djr')
        ->and($layer['motion_pace'])->toBe('fast');
});

it('contributes nothing for a non-restaurant Google Business', function () {
    $user = createTenant('janes-cafe');
    dkSeedConnection($user, ['category' => 'Cafe']);

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('contributes nothing when the Google Business category is missing', function () {
    $user = createTenant('no-category');
    dkSeedConnection($user, ['name' => 'Mystery Co']);

    dkPresetResolver()->resolveForUser($user);

    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
});

it('freezes the one-shot contribution when the category later changes', function () {
    $user = createTenant('was-a-restaurant');
    $connId = dkSeedConnection($user, ['category' => 'Restaurant']);
    dkPresetResolver()->resolveForUser($user);
    expect(dkPresetResolver()->presetLayer($user->site->id)['color_bg'])->toBe('#f7f4ee');

    // The business re-categorises to a cafe; the frozen one-shot must not move.
    DB::connection('pgsql')->table('site.platform_connections')
        ->where('id', $connId)->update(['payload' => json_encode(['category' => 'Cafe'])]);

    $changed = dkPresetResolver()->resolveForUser($user);

    expect($changed)->toBeFalse();
    expect(dkPresetResolver()->presetLayer($user->site->id)['color_bg'])->toBe('#f7f4ee');
});

it('sweeps contributions when the integration disconnects', function () {
    $user = createTenant('closing-down');
    $connId = dkSeedConnection($user, ['category' => 'Restaurant']);
    dkPresetResolver()->resolveForUser($user);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBeGreaterThan(0);

    // Soft-delete = disconnect.
    DB::connection('pgsql')->table('site.platform_connections')
        ->where('id', $connId)->update(['deleted_at' => now()->toDateTimeString()]);

    $changed = dkPresetResolver()->resolveForUser($user);

    expect($changed)->toBeTrue();
    expect(dkPresetResolver()->presetLayer($user->site->id))->toBe([]);
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('lets a manual design_kit value win over the preset layer', function () {
    $user = createTenant('custom-diner');
    dkSeedConnection($user, ['category' => 'Restaurant']);
    dkPresetResolver()->resolveForUser($user);

    // User manually set their own background.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_bg' => '#123456',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $merged = dkPresetResolver()->mergedFlatKit($user->site->id);

    expect($merged['color_bg'])->toBe('#123456')          // manual wins
        ->and($merged['color_accent'])->toBe('#e0491f');   // preset fills the rest
});

it('resolves the highest-priority contribution per column, order-independent', function () {
    $user = createTenant('priority-test');
    $siteId = $user->site->id;

    // Two sources set color_bg; higher priority wins regardless of row order.
    foreach ([['a-source', 40, '#aaaaaa'], ['z-source', 60, '#bbbbbb']] as [$source, $prio, $val]) {
        DesignKitContribution::query()->create([
            'site_id' => $siteId,
            'source' => $source,
            'integration' => 'test',
            'priority' => $prio,
            'mode' => 'one_shot',
            'target_var' => 'color_bg',
            'value' => $val,
        ]);
    }

    expect(dkPresetResolver()->presetLayer($siteId)['color_bg'])->toBe('#bbbbbb');
});

it('is a no-op when no factors are registered (dark launch)', function () {
    $user = createTenant('dark-launch');
    dkSeedConnection($user, ['category' => 'Restaurant']);

    $emptyResolver = new DesignPresetResolver(new DesignFactorRegistry([]));
    $changed = $emptyResolver->resolveForUser($user);

    expect($changed)->toBeFalse();
    expect(DesignKitContribution::query()->where('site_id', $user->site->id)->count())->toBe(0);
});

it('dispatches a preset resolve job when a connection is created', function () {
    Queue::fake();
    $user = createTenant('dispatch-test');

    IntegrationConnection::query()->create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'r1',
        'payload' => ['category' => 'Restaurant'],
        'is_active' => true,
    ]);

    Queue::assertPushed(ResolveDesignPresetsJob::class);
});
