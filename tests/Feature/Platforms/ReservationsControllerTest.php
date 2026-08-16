<?php

use App\Jobs\Content\EnrichPoolLinkJob;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Convergence Phase 6: a reservation link with no brand home goes to the
    // links POOL (owner ruling 2A), so these cases need the content lane too.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function reservationsUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // A pool item needs a section, which hangs off the SITE.
    $site = new Site(['subdomain' => $handle, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('detect a recognised reservation brand returns 202 + minimal card on that BRAND surface', function () {
    Queue::fake();
    Http::fake();

    $user = reservationsUser('resuser');

    // Convergence Phase 6: SevenRooms has its own catalog surface, so the card
    // lands there rather than on the retired shared `partna.reserve_link` key.
    // Wire unchanged — provider 'custom', next 'custom-saved'.
    $res = actingAsUser($user)->postJson('/api/platforms/reservations/detect', [
        'url' => 'https://www.sevenrooms.com/reservations/some-restaurant',
    ]);

    $res->assertStatus(202)
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('next', 'custom-saved')
        ->assertJsonPath('status', 'pending');

    $row = IntegrationConnection::where('user_id', $user->id)->where('routing_class', 'reservations')->firstOrFail();
    expect($row->surface_key)->toBe('sevenrooms.reserve');
    expect(IntegrationConnection::where('user_id', $user->id)
        ->where('surface_key', 'partna.reserve_link')->exists())->toBeFalse();

    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class, fn ($j) => $j->platform === 'reservations' && $j->surfaceKey === 'sevenrooms.reserve');
});

it('detect a reservation link with no brand home sends it to the links pool', function () {
    Queue::fake();
    Http::fake();

    $user = reservationsUser('respool');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', [
        'url' => 'https://www.example.com/reserve',
    ])->assertStatus(202)
        ->assertJsonPath('next', 'link-saved')
        ->assertJsonPath('routedTo.pool', 'custom_links')
        ->assertJsonPath('selection', null);

    expect(IntegrationConnection::where('user_id', $user->id)->count())->toBe(0);
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(1);

    actingAsUser($user)->getJson('/api/platforms/reservations/status')
        ->assertOk()->assertJsonPath('connected', false);

    Queue::assertPushed(EnrichPoolLinkJob::class);
    Queue::assertNotPushed(EnrichLinkCardJob::class);
});

it('detect opentable URL stays synchronous 200 with no job dispatched', function () {
    Queue::fake();

    $user = reservationsUser('otuser');

    $res = actingAsUser($user)->postJson('/api/platforms/reservations/detect', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
    ]);

    $res->assertOk()
        ->assertJsonPath('provider', 'opentable');

    Queue::assertNotPushed(EnrichLinkCardJob::class);
});

it('detect/status returns pending while the enrichment job is running', function () {
    Queue::fake();
    Http::fake();

    $user = reservationsUser('respolluser');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', [
        'url' => 'https://www.sevenrooms.com/reservations/some-restaurant',
    ])->assertStatus(202);

    $res = actingAsUser($user)->getJson('/api/platforms/reservations/detect/status');
    $res->assertOk()->assertJsonPath('status', 'pending');
});
