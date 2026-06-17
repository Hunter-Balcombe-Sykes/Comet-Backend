<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\NowBookitService;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\ResDiaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();

    // seedWorkplace saves site.sites → SiteObserver. Stub the cache service + fake
    // the queue so the observer's invalidate / KV-sync side effects are no-ops.
    $cache = Mockery::mock(SiteCacheService::class)->shouldIgnoreMissing();
    app()->instance(SiteCacheService::class, $cache);
    Queue::fake();
});

function resUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function resSite(User $user, array $settings = []): void
{
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => 0,
        'settings' => json_encode($settings),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function resWorkplace(User $user): ?array
{
    $settings = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('settings');

    return data_get(json_decode((string) $settings, true), 'workplace');
}

// ── Service-level: detection + embed construction ─────────────────────

it('detects resdiary and nowbookit reservation providers (and still opentable)', function () {
    $d = app(ProviderDetector::class);

    expect($d->detectFor('reservations', 'https://booking.resdiary.com/widget/Standard/Ollies'))->toBe('resdiary');
    expect($d->detectFor('reservations', 'https://ollies.resdiary.com/'))->toBe('resdiary');
    expect($d->detectFor('reservations', 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34'))->toBe('nowbookit');
    expect($d->detectFor('reservations', 'https://www.opentable.com.au/restaurant/profile/266537'))->toBe('opentable');
    expect($d->detectFor('reservations', 'https://example.com/book'))->toBeNull();
});

it('builds the resdiary embed verbatim from a widget link and from a microsite', function () {
    $s = app(ResDiaryService::class);

    expect($s->embedUrl('https://booking.resdiary.com/widget/Standard/Ollies'))->toBe('https://booking.resdiary.com/widget/Standard/Ollies');
    expect($s->embedUrl('https://ollies-pizza.resdiary.com/'))->toBe('https://booking.resdiary.com/widget/Standard/ollies-pizza');
    expect($s->nameFromUrl('https://ollies-pizza.resdiary.com/'))->toBe('Ollies Pizza');
});

it('parses nowbookit ids and builds the widget embed', function () {
    $s = app(NowBookitService::class);

    expect($s->parseIds('https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34&theme=dark'))
        ->toBe(['accountId' => '12', 'venueId' => '34']);
    expect($s->parseIds('https://booking.nowbookit.com/steps/sitting-details'))->toBeNull();
    expect($s->embedUrl('12', '34'))->toContain('accountid=12')->toContain('venueid=34');
});

// ── HTTP: detect → connect → status → forget ──────────────────────────

it('routes resdiary + nowbookit urls to their connect step', function () {
    $user = resUser('rp1');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])
        ->assertOk()->assertJsonPath('provider', 'resdiary')->assertJsonPath('next', 'resdiary-connect');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', ['url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=1&venueid=2'])
        ->assertOk()->assertJsonPath('provider', 'nowbookit')->assertJsonPath('next', 'nowbookit-connect');
});

it('connects resdiary and reports it as the reservation', function () {
    $user = resUser('rp2');

    actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])
        ->assertOk()->assertJsonPath('embedUrl', 'https://booking.resdiary.com/widget/Standard/Ollies');

    actingAsUser($user)->getJson('/api/platforms/reservations/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('provider', 'resdiary')
        ->assertJsonPath('embedUrl', fn ($u) => str_contains((string) $u, 'widget/Standard/Ollies'));
});

it('connects nowbookit and rejects a link missing venue ids', function () {
    $user = resUser('rp3');

    actingAsUser($user)->postJson('/api/platforms/nowbookit/connect', ['url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34'])
        ->assertOk()->assertJsonPath('accountId', '12')->assertJsonPath('venueId', '34');

    actingAsUser($user)->getJson('/api/platforms/reservations/status')->assertJsonPath('provider', 'nowbookit');

    actingAsUser(resUser('rp3b'))->postJson('/api/platforms/nowbookit/connect', ['url' => 'https://www.nowbookit.com/'])
        ->assertStatus(422);
});

it('clears a resdiary reservation via reservations forget (single-slot)', function () {
    $user = resUser('rp4');

    actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])->assertOk();
    actingAsUser($user)->deleteJson('/api/platforms/reservations')->assertOk()->assertJsonPath('connected', false);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
});

// ── Google auto-sync: reservation + workplace ─────────────────────────

it('auto-seeds a resdiary reservation from a google reservation link', function () {
    $user = resUser('rp5');

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['reservation' => ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies', 'provider' => 'ResDiary']],
        'Ollies',
    );

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->firstOrFail()->payload;
    expect($row['embedUrl'])->toContain('widget/Standard/Ollies');
    expect($row['source'])->toBe('google-business');
});

it('auto-fills workplace category, description and old website from place details', function () {
    $user = resUser('rp6');
    resSite($user);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [], 'Ollies', [
        'category' => 'Japanese restaurant',
        'website' => 'https://ollies.example',
        'editorialSummary' => 'From Ollies: the best ramen in town.',
    ]);

    $workplace = resWorkplace($user);
    expect($workplace['category'])->toBe('Japanese restaurant');
    expect($workplace['previous_website'])->toBe('https://ollies.example');
    expect($workplace['description'])->toBe('From Ollies: the best ramen in town.');
});

it('does not overwrite a workplace field the user already set', function () {
    $user = resUser('rp7');
    resSite($user, ['workplace' => ['name' => 'Ollies', 'category' => 'My own category']]);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [], 'Ollies', [
        'category' => 'Japanese restaurant',
        'website' => 'https://ollies.example',
    ]);

    $workplace = resWorkplace($user);
    expect($workplace['category'])->toBe('My own category');             // preserved
    expect($workplace['previous_website'])->toBe('https://ollies.example'); // filled (was empty)
});
