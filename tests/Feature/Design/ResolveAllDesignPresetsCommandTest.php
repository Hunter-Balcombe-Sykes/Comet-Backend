<?php

/**
 * Tests for the design:resolve-all backfill command (spec §1c). Verifies it
 * resolves sites with an active connection, honours --dry-run (writes NOTHING
 * while still reporting the delta), honours --user (single-site), skips sites
 * with no active connection, and is idempotent.
 */

use App\Console\Commands\ResolveAllDesignPresetsCommand;
use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
});

/** Raw-insert an active platform connection for a user. */
function racSeedConnection(User $user, array $payload, string $platform = 'google-business', bool $active = true): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => $active ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function racContributionCount(string $siteId): int
{
    return DesignKitContribution::query()->where('site_id', $siteId)->count();
}

it('backfills a site with an active connection on a real run', function () {
    $user = createTenant('joes-diner');
    racSeedConnection($user, ['category' => 'Italian restaurant', 'name' => "Joe's"]);

    expect(racContributionCount($user->site->id))->toBe(0);

    $this->artisan('design:resolve-all')
        ->assertExitCode(0);

    // The Google-Business restaurant factor (+ others) wrote contributions.
    expect(racContributionCount($user->site->id))->toBeGreaterThan(0);
});

it('writes nothing on --dry-run but reports the delta', function () {
    $user = createTenant('dry-run-diner');
    racSeedConnection($user, ['category' => 'Italian restaurant', 'name' => 'Dry']);

    $this->artisan('design:resolve-all', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->expectsOutputToContain('would change')
        ->assertExitCode(0);

    // Crucially: the dry run persisted NOTHING.
    expect(racContributionCount($user->site->id))->toBe(0);
});

it('is idempotent — a second real run changes nothing new', function () {
    $user = createTenant('idem-diner');
    racSeedConnection($user, ['category' => 'Italian restaurant', 'name' => 'Idem']);

    $this->artisan('design:resolve-all')->assertExitCode(0);
    $countAfterFirst = racContributionCount($user->site->id);

    // Second run: same active connection → the resolver upserts identically.
    $this->artisan('design:resolve-all')
        ->expectsOutputToContain('0 changed')
        ->assertExitCode(0);

    expect(racContributionCount($user->site->id))->toBe($countAfterFirst);
});

it('limits to a single user with --user by handle', function () {
    $target = createTenant('target-diner');
    $other = createTenant('other-diner');
    racSeedConnection($target, ['category' => 'Italian restaurant', 'name' => 'T']);
    racSeedConnection($other, ['category' => 'Barber shop', 'name' => 'O']);

    $this->artisan('design:resolve-all', ['--user' => 'target-diner'])
        ->assertExitCode(0);

    // Only the target was resolved.
    expect(racContributionCount($target->site->id))->toBeGreaterThan(0)
        ->and(racContributionCount($other->site->id))->toBe(0);
});

it('limits to a single user with --user by id', function () {
    $target = createTenant('id-target');
    $other = createTenant('id-other');
    racSeedConnection($target, ['category' => 'Italian restaurant', 'name' => 'T']);
    racSeedConnection($other, ['category' => 'Barber shop', 'name' => 'O']);

    $this->artisan('design:resolve-all', ['--user' => (string) $target->id])
        ->assertExitCode(0);

    expect(racContributionCount($target->site->id))->toBeGreaterThan(0)
        ->and(racContributionCount($other->site->id))->toBe(0);
});

it('reports a clean no-op when no site has an active connection', function () {
    createTenant('no-connections'); // a site, but no active connection

    $this->artisan('design:resolve-all')
        ->expectsOutputToContain('No sites with an active connection')
        ->assertExitCode(0);
});

it('fails cleanly for an unknown --user', function () {
    $this->artisan('design:resolve-all', ['--user' => 'nobody-here'])
        ->assertExitCode(1);
});

// ── OBS-5: an explicit $timeout ceiling on this manual backfill sweep ──

it('declares an explicit, non-null $timeout ceiling', function () {
    $property = (new ReflectionClass(ResolveAllDesignPresetsCommand::class))->getProperty('timeout');
    $property->setAccessible(true);

    expect($property->getDefaultValue())->not->toBeNull()
        ->and($property->getDefaultValue())->toBeGreaterThan(0);
});
