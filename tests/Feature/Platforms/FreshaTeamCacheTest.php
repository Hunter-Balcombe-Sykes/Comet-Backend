<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.platforms.fresha.team_cache_seconds', 86400);
});

function teamCacheUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function seedTeamCacheFresha(User $user, array $payload): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

/** Minimal __NEXT_DATA__ page the scraper can parse. */
function freshaPageHtml(string $employeeName = 'Simon'): string
{
    $data = json_encode(['props' => ['pageProps' => ['data' => ['location' => [
        'name' => 'Anseo Studio',
        'employeeProfiles' => ['edges' => [
            ['node' => ['employeeId' => 'e1', 'displayName' => $employeeName, 'jobTitle' => 'Barber']],
        ]],
        'services' => [],
    ]]]]]);

    return '<html><script id="__NEXT_DATA__" type="application/json">'.$data.'</script></html>';
}

it('scrapes on a cold cache and stores the roster under teamMenuCache', function () {
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('coldcache');
    $row = seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('storeName', 'Anseo Studio')
        ->assertJsonPath('team.0.displayName', 'Simon');

    $payload = $row->fresh()->payload;
    expect($payload['teamMenuCache']['storeName'])->toBe('Anseo Studio')
        ->and($payload['teamMenuCachedAt'])->toBeString()
        ->and($payload['url'])->toBe('https://www.fresha.com/a/anseo-studio')
        ->and($payload)->not->toHaveKey('teamMenu');
});

it('serves the second call from cache without scraping again', function () {
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('warmcache');
    seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')->assertOk();
    $afterFirst = Http::recorded()->count();

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Simon');

    expect(Http::recorded()->count())->toBe($afterFirst);
});

it('re-scrapes when the cache is older than the TTL', function () {
    Http::fake(['*' => Http::response(freshaPageHtml('Rotated'), 200)]);
    $user = teamCacheUser('staleche');
    seedTeamCacheFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'teamMenuCache' => ['storeName' => 'Old', 'team' => [], 'services' => []],
        'teamMenuCachedAt' => now()->subDays(3)->toIso8601String(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Rotated');
});

it('re-scrapes on ?refresh=1 even with a warm cache', function () {
    Http::fake(['*' => Http::response(freshaPageHtml('Fresh'), 200)]);
    $user = teamCacheUser('forcedref');
    seedTeamCacheFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'teamMenuCache' => ['storeName' => 'Old', 'team' => [], 'services' => []],
        'teamMenuCachedAt' => now()->toIso8601String(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team?refresh=1')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Fresh');
});

it('serves a stale cache when the scrape fails rather than 502ing', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $user = teamCacheUser('stalefall');
    seedTeamCacheFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'teamMenuCache' => ['storeName' => 'Cached Studio', 'team' => [], 'services' => []],
        'teamMenuCachedAt' => now()->subDays(3)->toIso8601String(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('storeName', 'Cached Studio');
});

it('still 404s when no url is connected', function () {
    actingAsUser(teamCacheUser('nourl'))->getJson('/api/platforms/fresha/team')
        ->assertStatus(404);
});

it('does not purge the sitepage cache when caching the roster', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('nopurge');
    seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')->assertOk();

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});
