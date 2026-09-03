<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

// Defined and used in THIS file only — a cross-file Pest helper breaks the
// parallel runner in this repo.
function brandGuardUser(string $handle, string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        // business + a food sector is what grants can_use_online_ordering,
        // which the ordering brands below are gated on.
        'account_type' => 'business',
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('rejects a url belonging to a different brand', function () {
    $user = brandGuardUser('bg-cross');

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.doordash.com/store/abc-123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.url.0', fn ($m) => str_contains(strtolower((string) $m), 'doordash'));
});

it('names the brand the url actually belongs to', function () {
    $user = brandGuardUser('bg-name');

    actingAsUser($user)
        ->postJson('/api/platforms/doordash/connect', ['url' => 'https://www.skipthedishes.com/example-restaurant/menu'])
        ->assertStatus(422)
        ->assertJsonPath('errors.url.0', fn ($m) => str_contains(strtolower((string) $m), 'skipthedishes'));
});

it('rejects a url the classifier does not recognise at all', function () {
    $user = brandGuardUser('bg-unknown');

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://example.invalid/nothing'])
        ->assertStatus(422);
});

it('accepts a url belonging to the addressed brand', function () {
    $user = brandGuardUser('bg-match');

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/example-restaurant/menu'])
        ->assertSuccessful();
});

// The MANUAL lane now asks the same question the accept lane asks (2026-09-03).
// Until then this file's own fixtures were bare brand-domain URLs
// ('skipthedishes.com/example-restaurant', 'booksy.com/en-gb/x') that no real
// store page looks like — they connected anyway, because classify() only ever
// checked the host. Each would have produced a connection whose identifier was
// the whole URL: nameless on the card, unverifiable, un-refreshable.
it('refuses a brand-domain url that is not an account page, and says what one looks like', function () {
    $user = brandGuardUser('bg-shape');

    actingAsUser($user)
        // Right host, right brand, right capability — but /promotions is a
        // marketing page, not anyone's store.
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/promotions'])
        ->assertStatus(422)
        // The hint is built by masking the segment the detector's OWN capture
        // matched, so it names the routing word and never the real restaurant
        // whose page the catalog note records.
        ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'https://www.skipthedishes.com/…/menu')
            && ! str_contains((string) $m, 'songs-kitchen'));

    expect(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('still connects a brand we have no shape on file for', function () {
    // The refusal is scoped to surfaces with at least one specific detector —
    // the nine surfaces with no grammar at all are ones we have no standing to
    // judge, and must stay connectable. Easi is one of those nine: its store
    // URLs carry no account segment the pattern fleet could verify, so a plain
    // brand-domain link there is still accepted. (Menulog would look like the
    // obvious choice here and is the wrong one — it is RETIRED, so it 422s for
    // an entirely different reason and would prove nothing.)
    $user = brandGuardUser('bg-noshape');

    actingAsUser($user)
        ->postJson('/api/platforms/easi/connect', ['url' => 'https://www.easi.com.au/whatever'])
        ->assertSuccessful();
});

it('leaves hand-written platforms unguarded', function () {
    // tiktok is LinkOnly with its own normalizer and accepts a bare handle,
    // which classify() cannot resolve. If the guard leaked past Brand shape,
    // this would 422.
    $user = brandGuardUser('bg-handwritten');

    actingAsUser($user)
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk();
});

it('403s a brand whose routing class the account cannot use', function () {
    // A non-food business has booking but NOT online-ordering. SkipTheDishes is an
    // ordering brand, so its connect must be closed to them — the capability
    // gate is the point of DerivedDescriptorFactory::CAPABILITY_BY_ROUTING_CLASS.
    $user = brandGuardUser('bg-nofood', 'barber');

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/example-restaurant/menu'])
        ->assertStatus(403);
});

it('403s an upgraded booking brand for an account without booking', function () {
    // booksy was a hand-written descriptor UPGRADED to Brand. The upgrade path
    // must attach the capability predicate too, not just the routes — a food
    // business has ordering but not booking.
    $user = brandGuardUser('bg-food-booking', 'restaurant');

    actingAsUser($user)
        ->postJson('/api/platforms/booksy/connect', ['url' => 'https://booksy.com/x'])
        ->assertStatus(403);
});

it('lets an eligible account connect an upgraded booking brand', function () {
    $user = brandGuardUser('bg-barber-booking', 'barber');

    actingAsUser($user)
        ->postJson('/api/platforms/booksy/connect', ['url' => 'https://booksy.com/en-gb/904207_the-salon'])
        ->assertSuccessful();
});

it('leaves social brands ungated by any capability', function () {
    // github is routing_class social — no capability applies, so a partna
    // account with no sector must still be able to connect it.
    $user = User::create([
        'handle' => 'bg-social', 'handle_lc' => 'bg-social', 'display_name' => 'S',
        'first_name' => 'S', 'account_type' => 'partna', 'sector' => null,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'bg-social@example.com',
    ]);

    actingAsUser($user)
        ->postJson('/api/platforms/github/connect', ['url' => 'https://github.com/someone'])
        ->assertSuccessful();
});

it('enriches a brand card after connect so it does not sit pending forever (F4)', function () {
    // Overnight 2026-08-18 F4: writeBrandCard() stores last_refresh_status
    // 'pending' and connectBrand dispatched nothing to flip it — 43 brand
    // connects in the sweep were pending with the brand label as their name.
    // Booking/reservations/ordering already dispatch EnrichLinkCardJob; the
    // generic brand path must too.
    Queue::fake();
    $user = User::create([
        'handle' => 'bg-enrich', 'handle_lc' => 'bg-enrich', 'display_name' => 'S',
        'first_name' => 'S', 'account_type' => 'partna', 'sector' => null,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'bg-enrich@example.com',
    ]);

    actingAsUser($user)
        ->postJson('/api/platforms/github/connect', ['url' => 'https://github.com/someone'])
        ->assertSuccessful();

    Queue::assertPushed(EnrichLinkCardJob::class, function ($job) use ($user) {
        return $job->userId === (string) $user->id
            && $job->platform === 'github'
            && $job->url === 'https://github.com/someone'
            && $job->surfaceKey !== null;
    });
});

it('refuses a SECOND store on a brand whose one slot is filled, rather than overwriting it', function () {
    // Owner, 2026-08-19. writeBrandCard's updateOrCreate keys on the brand
    // prefix, so a second Menulog store silently replaced the first — and the
    // legacy ordering controller's answer, quietly filing it as a links-pool
    // card, was worse. Neither is a decision the owner made. The connect
    // refuses; the dashboard turns `slot_taken` into Swap / Keep mine.
    $user = brandGuardUser('bg-slot');

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/first-restaurant/menu'])
        ->assertSuccessful();

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/second-restaurant/menu'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'slot_taken')
        ->assertJsonPath('displayName', 'SkipTheDishes')
        ->assertJsonPath('incumbentUrl', 'https://www.skipthedishes.com/first-restaurant/menu');

    // Nothing was written: the incumbent still holds the slot, alone.
    expect($user->integrationConnections()->where('surface_key', 'skipthedishes.order')->count())->toBe(1)
        ->and($user->integrationConnections()->where('surface_key', 'skipthedishes.order')->first()->payload['url'])
        ->toBe('https://www.skipthedishes.com/first-restaurant/menu');
});

it('lets replace=true swap the incumbent, and never blocks re-connecting the SAME link', function () {
    $user = brandGuardUser('bg-slot-replace');

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/first-restaurant/menu'])
        ->assertSuccessful();

    // Same link again — a re-connect that fixes a payload, not a second store.
    // A trailing slash is not a different store either.
    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', ['url' => 'https://www.skipthedishes.com/first-restaurant/menu/'])
        ->assertSuccessful();

    actingAsUser($user)
        ->postJson('/api/platforms/skipthedishes/connect', [
            'url' => 'https://www.skipthedishes.com/second-restaurant/menu',
            'replace' => true,
        ])
        ->assertSuccessful();

    expect($user->integrationConnections()->where('surface_key', 'skipthedishes.order')->count())->toBe(1)
        ->and($user->integrationConnections()->where('surface_key', 'skipthedishes.order')->first()->payload['url'])
        ->toBe('https://www.skipthedishes.com/second-restaurant/menu');
});

it('accepts a bare handle for brands whose surface has a canonical template (F12)', function () {
    Queue::fake();
    $user = User::create([
        'handle' => 'bg-handle', 'handle_lc' => 'bg-handle', 'display_name' => 'S',
        'first_name' => 'S', 'account_type' => 'partna', 'sector' => null,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'bg-handle@example.com',
    ]);

    actingAsUser($user)->postJson('/api/platforms/github/connect', ['url' => '@torvalds'])
        ->assertSuccessful()->assertJsonPath('url', 'https://github.com/torvalds');
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'astralcodexten'])
        ->assertSuccessful()->assertJsonPath('url', 'https://astralcodexten.substack.com');
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'astralcodexten.substack.com'])
        ->assertSuccessful()->assertJsonPath('url', 'https://astralcodexten.substack.com');
    // A brand without a template still needs a URL.
    actingAsUser($user)->postJson('/api/platforms/skipthedishes/connect', ['url' => 'somerestaurant'])
        ->assertStatus(422);
    // A foreign host is never a subdomain handle (review: torvalds.github.io was rewritten to *.substack.com).
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'torvalds.github.io'])
        ->assertStatus(422);
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'myrestaurant.com.au'])
        ->assertStatus(422);
});

// Found live on dev (2026-08-19, big run): the slot guard only compared the
// brand-slug rid, so a ROUTER-seeded incumbent ('order-<hash>') was invisible
// to a manual connect and a second Uber Eats landed silently beside it.
it('422s slot_taken against a ROUTER-seeded incumbent, and Swap retires it', function () {
    $user = brandGuardUser('bg-router-incumbent');

    // The router's write shape: url-derived rid on the same brand surface.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats',
        'surface_key' => 'uber_eats.order',
        'resource_id' => 'order-'.substr(sha1('https://www.ubereats.com/au/store/first/aaa'), 0, 16),
        'payload' => ['url' => 'https://www.ubereats.com/au/store/first/aaa', 'provider' => 'Uber Eats', 'source' => 'auto'],
        'is_active' => true,
    ]);

    // A different store on the same single-slot surface → refused, naming the incumbent.
    actingAsUser($user)
        ->postJson('/api/platforms/uber_eats/connect', ['url' => 'https://www.ubereats.com/au/store/second/bbb'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'slot_taken');

    // Swap: replace=true retires the router row — ONE live row afterwards.
    actingAsUser($user)
        ->postJson('/api/platforms/uber_eats/connect', ['url' => 'https://www.ubereats.com/au/store/second/bbb', 'replace' => true])
        ->assertOk();

    $rows = IntegrationConnection::query()
        ->where('user_id', $user->id)->where('surface_key', 'uber_eats.order')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->payload['url'])->toBe('https://www.ubereats.com/au/store/second/bbb');
});

it('refuses a retired brand with a reason rather than a 404', function () {
    // Menulog was this file's generic example until it was retired
    // (2026-09-03). Retirement must not vaporize the platform: the descriptor
    // still derives, so the route still exists and the person gets told WHY.
    // A 404 here would mean an existing Menulog connection had no descriptor
    // to render, export or delete itself with.
    $user = brandGuardUser('bg-retired');

    $response = actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://www.menulog.com.au/restaurants/x'])
        ->assertStatus(422);

    expect(strtolower(json_encode($response->json())))->toContain('no longer operating');
});
