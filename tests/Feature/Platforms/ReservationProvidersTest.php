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

// Defaults to a Business Partna: the Google auto-sync assertions below exercise
// the FULL sync (reservation + workplace seeds), which is Business-only. Also
// defaults to a food sector ('restaurant') — reservations are food-business-only
// (2026-07-15 sector gating), and this file's connect/detect/auto-seed tests are
// almost all exercising the reservation family. Pass sector: null (or another
// non-food slug) for the booking-only scenarios (gb1-gb3 below).
function resUser(string $h, string $accountType = 'business', ?string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
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

// FOUND-4: workplace data now lives in site.workplaces (promoted from settings JSONB).
function resWorkplace(User $user): ?array
{
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('id');
    if (! $siteId) {
        return null;
    }
    $row = DB::connection('pgsql')->table('site.workplaces')->where('site_id', $siteId)->first();

    return $row ? (array) $row : null;
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

// ── Sector-derived AutoSync direction (2026-07-15) ──────────────────────
// A business account's sector picks exactly one family: food → reservations +
// online-ordering (never booking); non-food → booking (never reservations or
// online-ordering). Both branches carry all three link types in the enrichment
// to prove it's the gate — not absent data — deciding the outcome.

it('a food (restaurant) business GB connect seeds reservations + ordering but not booking', function () {
    $user = resUser('rpfood', sector: 'restaurant');

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [
        'reservation' => ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies', 'provider' => 'ResDiary'],
        'order' => ['providers' => [
            ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'type' => 'pickup'],
        ]],
        'booking' => ['https://www.fresha.com/a/ollies-salon'],
    ], 'Ollies');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->exists())->toBeFalse();
});

it('a non-food (barbershop) business GB connect seeds booking but not reservations/ordering', function () {
    $user = resUser('rpnonfood', sector: 'barber');

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [
        'reservation' => ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies', 'provider' => 'ResDiary'],
        'order' => ['providers' => [
            ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'type' => 'pickup'],
        ]],
        'booking' => ['https://www.fresha.com/a/ollies-salon'],
    ], 'Ollies');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->exists())->toBeFalse();
});

it('a null-sector business GB connect defaults to booking-only (not-food default)', function () {
    $user = resUser('rpnullsector', sector: null);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [
        'reservation' => ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies', 'provider' => 'ResDiary'],
        'booking' => ['https://www.fresha.com/a/ollies-salon'],
    ], 'Ollies');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
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
    // FOUND-4: pre-seed the workplace in site.workplaces (not settings JSONB).
    $user = resUser('rp7');
    resSite($user);

    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('id');
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Ollies',
        'category' => 'My own category',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [], 'Ollies', [
        'category' => 'Japanese restaurant',
        'website' => 'https://ollies.example',
    ]);

    $workplace = resWorkplace($user);
    expect($workplace['category'])->toBe('My own category');             // preserved
    expect($workplace['previous_website'])->toBe('https://ollies.example'); // filled (was empty)
});

it('stamps field_sources provenance for every workplace field it fills from Google', function () {
    $user = resUser('rp6b');
    resSite($user);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [], 'Ollies', [
        'category' => 'Japanese restaurant',
        'website' => 'https://ollies.example',
        'editorialSummary' => 'From Ollies: the best ramen in town.',
    ]);

    $sources = json_decode(resWorkplace($user)['field_sources'], true);

    expect($sources['category']['source'])->toBe('google-business');
    expect($sources['previous_website']['source'])->toBe('google-business');
    expect($sources['description']['source'])->toBe('google-business');
    expect($sources['category'])->toHaveKey('at');
});

it('does not stamp field_sources for a field it declined to overwrite', function () {
    $user = resUser('rp7b');
    resSite($user);
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('id');
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Ollies',
        'category' => 'My own category',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [], 'Ollies', [
        'category' => 'Japanese restaurant',
        'website' => 'https://ollies.example',
    ]);

    $workplace = resWorkplace($user);
    $sources = json_decode($workplace['field_sources'] ?? 'null', true);

    expect($workplace['category'])->toBe('My own category'); // preserved, per the existing test above
    expect($sources['previous_website']['source'] ?? null)->toBe('google-business'); // this one WAS filled
    expect($sources['category'] ?? null)->toBeNull(); // never written, so never stamped
});

it('truncates a long Google description to the workplace field cap', function () {
    $user = resUser('rp8');
    resSite($user);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, [], 'Ollies', [
        'editorialSummary' => str_repeat('a', 1500),
    ]);

    expect(mb_strlen(resWorkplace($user)['description']))->toBe(1000);
});

// ── Google booking auto-connect (Fresha pending / Square full / custom) ──

it('auto-connects a pending Fresha from a google booking link', function () {
    // Booking is a non-food-business capability (2026-07-15 sector gating) —
    // a food business books via Reservations instead. See T7 direction tests below.
    $user = resUser('gb1', sector: null);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, ['booking' => ['https://www.fresha.com/a/ollies-salon']], 'Ollies');

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($row->payload['url'])->toBe('https://www.fresha.com/a/ollies-salon');
    expect($row->payload['selection'])->toBeNull();              // pending → "Finish setup"
    expect($row->payload['source'])->toBe('google-business');
});

it('auto-connects Square fully (no picker) from a google booking link', function () {
    $user = resUser('gb2', sector: null);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, ['booking' => ['https://book.squareup.com/appointments/x']], 'Ollies');

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->firstOrFail();
    expect($row->payload['url'])->toBe('https://book.squareup.com/appointments/x');
    expect($row->payload['source'])->toBe('google-business');
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('seeds a custom booking card for an unknown google booking link', function () {
    $user = resUser('gb3', sector: null);

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, ['booking' => ['https://calendly.com/ollies']], 'Ollies');

    // Convergence Phase 6: Calendly has its own catalog surface, so the Google
    // harvest seeds calendly.book — not the retired shared 'booking' key.
    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'booking')->firstOrFail();
    expect($row->surface_key)->toBe('calendly.book');
    expect($row->payload['provider'])->toBe('custom');
    expect($row->payload['url'])->toBe('https://calendly.com/ollies');
});

// ── Previous-website settings endpoint ──────────────────────────────────

it('saves and reads the previous website via the focused settings endpoint', function () {
    $user = resUser('gb4');
    resSite($user);

    actingAsUser($user)->patchJson('/api/site/workplace/previous-website', ['previous_website' => 'https://old.ollies.example'])
        ->assertOk()->assertJsonPath('previousWebsite', 'https://old.ollies.example');

    actingAsUser($user)->getJson('/api/site/workplace/previous-website')
        ->assertOk()->assertJsonPath('previousWebsite', 'https://old.ollies.example');
});

it('rejects an invalid previous website url', function () {
    $user = resUser('gb5');
    resSite($user);

    actingAsUser($user)->patchJson('/api/site/workplace/previous-website', ['previous_website' => 'not a url'])
        ->assertStatus(422);
});

// ── ResDiary + NowBookit /selection read-back guards ─────────────────────
// These lock the target shape before the $singleSelection → $migratedReads
// flip (Step 3), so the post-flip run proves byte-identical equivalence
// through GenericPlatformController → SelectionPayload.

it('reads the resdiary selection back through the read path', function () {
    $user = resUser('rsel1');

    actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/resdiary/selection')
        ->assertOk()
        ->assertJsonPath('selection.url', 'https://booking.resdiary.com/widget/Standard/Ollies')
        ->assertJsonPath('selection.embedUrl', 'https://booking.resdiary.com/widget/Standard/Ollies');
});

it('reads the nowbookit selection back through the read path', function () {
    $user = resUser('nsel1');

    actingAsUser($user)->postJson('/api/platforms/nowbookit/connect', ['url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34'])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/nowbookit/selection')
        ->assertOk()
        ->assertJsonPath('selection.accountId', '12')
        ->assertJsonPath('selection.venueId', '34')
        ->assertJsonPath('selection.embedUrl', fn ($u) => str_contains((string) $u, 'accountid=12'));
});

// ── TEST-3: Reservation /selection exact-shape snapshots ─────────────────────
//
// The three reservation providers route through GenericPlatformController +
// a normalising SelectionPayload DTO feeding enumerating resources. These pins
// catch any DTO or resource key drift — the highest-value P3 because the
// normalising-DTO-meets-resource pattern is most likely to diverge silently.

it('opentable selection returns the exact 4-key shape and strips unknown stored keys', function () {
    $user = resUser('otel1');

    // Seed via connect so the resource normalisation path is exercised.
    actingAsUser($user)->postJson('/api/platforms/opentable/connect', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
    ])->assertOk();

    // Inject an extra key to prove the resource strips it.
    IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'opentable')
        ->firstOrFail()
        ->update(['payload' => array_merge(
            IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->firstOrFail()->payload,
            ['_internal' => 'leak'],
        )]);

    $selection = actingAsUser($user)->getJson('/api/platforms/opentable/selection')
        ->assertOk()
        ->json('selection');

    // OpenTableConnectionResource emits exactly {url, rid, name, embedUrl}.
    expect($selection)->toHaveKeys(['url', 'rid', 'name', 'embedUrl']);
    expect($selection)->not->toHaveKey('_internal', "'_internal' must be stripped by the resource");
    // Exact key set — toEqual asserts no extra keys leak in either direction.
    expect(array_keys($selection))->toEqual(['url', 'rid', 'name', 'embedUrl']);
    expect($selection['rid'])->toBe('266537');
    expect($selection['url'])->toBe('https://www.opentable.com.au/restaurant/profile/266537');
    expect($selection['name'])->toBeNull(); // name is null when not enriched by Apify
    expect($selection['embedUrl'])->toContain('rid=266537');
});

it('resdiary selection returns the exact 4-key shape and strips unknown stored keys', function () {
    $user = resUser('rdsel1');

    actingAsUser($user)->postJson('/api/platforms/resdiary/connect', [
        'url' => 'https://booking.resdiary.com/widget/Standard/Ollies',
    ])->assertOk();

    IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'resdiary')
        ->firstOrFail()
        ->update(['payload' => array_merge(
            IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->firstOrFail()->payload,
            ['_internal' => 'leak'],
        )]);

    $selection = actingAsUser($user)->getJson('/api/platforms/resdiary/selection')
        ->assertOk()
        ->json('selection');

    // ResDiaryConnectionResource emits exactly {url, microsite, name, embedUrl}.
    expect($selection)->not->toHaveKey('_internal', "'_internal' must be stripped by the resource");
    expect(array_keys($selection))->toEqual(['url', 'microsite', 'name', 'embedUrl']);
    expect($selection['url'])->toBe('https://booking.resdiary.com/widget/Standard/Ollies');
    expect($selection['embedUrl'])->toBe('https://booking.resdiary.com/widget/Standard/Ollies');
    expect($selection['microsite'])->toBe('Ollies'); // parseMicrosite extracts the trailing slug
    expect($selection['name'])->toBe('Ollies');      // nameFromUrl derives it from the same slug
});

it('nowbookit selection returns the exact 5-key shape and strips unknown stored keys', function () {
    $user = resUser('nbsel1');

    actingAsUser($user)->postJson('/api/platforms/nowbookit/connect', [
        'url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34',
    ])->assertOk();

    IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'nowbookit')
        ->firstOrFail()
        ->update(['payload' => array_merge(
            IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'nowbookit')->firstOrFail()->payload,
            ['_internal' => 'leak'],
        )]);

    $selection = actingAsUser($user)->getJson('/api/platforms/nowbookit/selection')
        ->assertOk()
        ->json('selection');

    // NowBookitConnectionResource emits exactly {url, accountId, venueId, name, embedUrl}.
    expect($selection)->not->toHaveKey('_internal', "'_internal' must be stripped by the resource");
    expect(array_keys($selection))->toEqual(['url', 'accountId', 'venueId', 'name', 'embedUrl']);
    expect($selection['accountId'])->toBe('12');
    expect($selection['venueId'])->toBe('34');
    expect($selection['url'])->toBe('https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34');
    expect($selection['embedUrl'])->toContain('accountid=12')->toContain('venueid=34');
    expect($selection['name'])->toBeNull(); // no name without enrichment
});
