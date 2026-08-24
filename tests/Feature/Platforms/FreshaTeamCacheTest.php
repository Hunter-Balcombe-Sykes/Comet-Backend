<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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

/** Set an existing user's status to pending_deletion and reload — mirrors
 *  PlatformConnectionAuthorizationTest's makePendingDeletion() helper. */
function markTeamCacheUserPendingDeletion(User $user): User
{
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'status' => 'pending_deletion',
    ]);

    return $user->fresh();
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

it('serves the scraped roster but does not write the cache for a pending-deletion user', function () {
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('pendingdel');
    $row = seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);
    $user = markTeamCacheUserPendingDeletion($user);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('storeName', 'Anseo Studio')
        ->assertJsonPath('team.0.displayName', 'Simon');

    $payload = $row->fresh()->payload;
    expect($payload)->not->toHaveKey('teamMenuCache')
        ->and($payload)->not->toHaveKey('teamMenuCachedAt');
});

it('does not purge the sitepage cache when caching the roster', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(freshaPageHtml(), 200)]);
    $user = teamCacheUser('nopurge');
    seedTeamCacheFresha($user, ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')->assertOk();

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('serves /team from a router-placed row keyed by slug, and writes the cache back to that same row', function () {
    // The routing lane (SourceReconciler / SuggestionApplier) keys a Fresha
    // connection by the resolved identifier — the venue slug — and writes
    // ConnectionPayload::forWrite → {url, source, username}. The controller
    // used to look only under resource_id='fresha', 404 "No Fresha URL
    // connected yet", and the sheet showed "couldn't load your team"
    // (gsnwilliams, 2026-08-18). One user has one Fresha row whichever lane
    // made it; the controller must find it.
    Http::fake(['*' => Http::response(freshaPageHtml('Simon'), 200)]);
    $user = teamCacheUser('routerlane');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'anseo-studio-v0v92jna',
        'payload' => [
            'url' => 'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?pId=2835260',
            'source' => 'link_in_bio',
            'username' => 'anseo-studio-v0v92jna',
        ],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('team.0.displayName', 'Simon');

    expect($row->fresh()->payload['teamMenuCache']['storeName'])->toBe('Anseo Studio')
        ->and(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->count())->toBe(1);
});

// ── Slug rotation (live 2026-08-18: anseo-studio-v0v92jna → anseo-studio-melbourne-140a-chapel-street-w8ajp04r)

it('follows a rotated slug: /a/<old> is 410, the share URL redirects to /<locale>/a/<new>, the team loads', function () {
    // Fresha rotates a venue slug when its address lands. The stored slug's
    // canonical page answers 410 Gone; the share alias still 307s to the new
    // slug behind a locale prefix. The picker must land on the venue, not on
    // "couldn't load your team".
    Http::fake([
        'www.fresha.com/a/anseo-studio-v0v92jna' => Http::response('', 410),
        'www.fresha.com/book-now/anseo-studio-v0v92jna/*' => Http::response('', 307, [
            'Location' => 'https://www.fresha.com/en-GB/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r/booking?menu=true&share=true&pId=2835260',
        ]),
        'www.fresha.com/en-GB/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r/booking*' => Http::response(freshaPageHtml('Simon'), 200),
        'www.fresha.com/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r' => Http::response(freshaPageHtml('Simon'), 200),
    ]);
    $user = teamCacheUser('rotated');
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'anseo-studio-v0v92jna',
        'payload' => ['url' => 'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?pId=2835260', 'source' => 'link_in_bio', 'username' => 'anseo-studio-v0v92jna'],
        'is_active' => true, 'last_refresh_status' => 'action_needed',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/team')
        ->assertOk()
        ->assertJsonPath('storeName', 'Anseo Studio')
        ->assertJsonPath('team.0.displayName', 'Simon');

    // …and the rotation is persisted so the next read (and the ingest lane)
    // start from the current slug rather than paying the 410 again.
    $fresh = $row->fresh();
    expect($fresh->payload['url'])->toBe('https://www.fresha.com/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r')
        // routing identity keeps the slug the bio link carries; the current
        // slug becomes the alias ConnectionIdentity step 2 matches on.
        ->and($fresh->resource_id)->toBe('anseo-studio-v0v92jna')
        ->and($fresh->canonical_key)->toBe('anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
});

it('resolveCurrentSlug answers only positively: a dead network yields null, never a slug change', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('nope')]);

    expect(app(FreshaScraper::class)->resolveCurrentSlug('https://www.fresha.com/a/anseo-studio-v0v92jna'))->toBeNull();
});
