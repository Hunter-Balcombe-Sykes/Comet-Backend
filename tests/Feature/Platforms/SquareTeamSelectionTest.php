<?php

use App\Jobs\Platforms\SquareAutoSelectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AutoBookingConnectDispatcher;
use App\Services\Platforms\SquareBookingClient;
use App\Services\Platforms\StaffNameMatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    setupIngestTables();
});

const SQ_JESSE_URL = 'https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z';
const SQ_ROOT_URL = 'https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW';
const SQ_CANONICAL_JESSE = 'https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW?team_member_id=TM-qREuvGrHGnJ5Z';

function squareTeamUser(string $h, string $accountType = 'partna', string $first = 'Jesse', string $last = 'Jensz'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => trim("{$first} {$last}"),
        'first_name' => $first,
        'last_name' => $last,
        'account_type' => $accountType,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function squareWidgetFake(array $extra = []): void
{
    Http::fake([
        'app.squareup.com/*' => Http::response(
            file_get_contents(dirname(__DIR__, 2).'/fixtures/square/widget-akro.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
        ...$extra,
    ]);
}

function connectSquare(User $user, string $url): IntegrationConnection
{
    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => $url])->assertOk();

    return IntegrationConnection::where('user_id', $user->id)->where('platform', 'square')->firstOrFail();
}

function squareRow(User $user): IntegrationConnection
{
    return IntegrationConnection::where('user_id', $user->id)->where('platform', 'square')->firstOrFail();
}

it('lists the booking page team and suggests the team member the url names', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam1', 'partna', 'Alex', 'Kim');
    connectSquare($user, SQ_JESSE_URL);

    $response = actingAsUser($user)->getJson('/api/platforms/square/team')->assertOk();

    expect($response->json('team.0.employeeId'))->toBe('TM-qREuvGrHGnJ5Z')
        ->and($response->json('team.0.displayName'))->toBe('Jesse Jensz')
        ->and($response->json('team.0.avatarUrl'))->toStartWith('https://')
        ->and($response->json('suggestedEmployeeId'))->toBe('TM-qREuvGrHGnJ5Z');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://app.squareup.com/appointments/api/buyer/widget/7rn54rnv21ng7n?unit_token=LAJZK7J54JGCW')
        && $request->hasHeader('Accept', 'application/json'));
    // The member the url named is remembered for /selection.
    expect(squareRow($user)->payload['teamMember']['displayName'])->toBe('Jesse Jensz');
});

it('suggests the team member by name when the url names none', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam2');
    connectSquare($user, SQ_ROOT_URL);

    actingAsUser($user)->getJson('/api/platforms/square/team')
        ->assertOk()
        ->assertJsonPath('suggestedEmployeeId', 'TM-qREuvGrHGnJ5Z');
});

it('422s the team roster for a square.site root that links to no booking page', function () {
    squareWidgetFake(['*.square.site/*' => Http::response('<html><body>Welcome</body></html>', 200)]);
    $user = squareTeamUser('sqteam3');
    connectSquare($user, 'https://akro-studio.square.site/');

    actingAsUser($user)->getJson('/api/platforms/square/team')->assertStatus(422);
});

it('resolves a square.site root to the Appointments deep link the site links out to', function () {
    squareWidgetFake(['*.square.site/*' => Http::response(
        '<html><body><a class="book" href="https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?color=000000&amp;team_member_id=TM-qREuvGrHGnJ5Z">Book now</a></body></html>',
        200,
    )]);
    $user = squareTeamUser('sqteam4');

    $row = connectSquare($user, 'https://akro-studio.square.site/');

    expect($row->payload['url'])->toBe(SQ_CANONICAL_JESSE);
});

it('saves a team-member selection by rewriting the url and re-reads it', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam5');
    connectSquare($user, SQ_ROOT_URL);

    actingAsUser($user)->postJson('/api/platforms/square/selection', ['employeeId' => 'TM-qREuvGrHGnJ5Z'])
        ->assertOk()
        ->assertJsonPath('selection.mode', 'employee')
        ->assertJsonPath('selection.employee.displayName', 'Jesse Jensz');

    $row = squareRow($user);
    expect($row->payload['url'])->toBe(SQ_CANONICAL_JESSE)
        ->and($row->payload['teamMember']['employeeId'])->toBe('TM-qREuvGrHGnJ5Z');

    actingAsUser($user)->getJson('/api/platforms/square/selection')
        ->assertOk()
        ->assertJsonPath('url', SQ_CANONICAL_JESSE)
        ->assertJsonPath('selection.mode', 'employee')
        ->assertJsonPath('selection.employee.employeeId', 'TM-qREuvGrHGnJ5Z');
});

it('404s a selection for a team member who is not on the booking page', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam6');
    connectSquare($user, SQ_ROOT_URL);

    actingAsUser($user)->postJson('/api/platforms/square/selection', ['employeeId' => 'TM-nobodyhere'])->assertStatus(404);
    expect(squareRow($user)->payload['url'])->toBe(SQ_ROOT_URL);
});

it('rejects a malformed employee id before touching Square', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam7');
    connectSquare($user, SQ_ROOT_URL);
    // The connect's eager ingest run reads the widget once; the rejected
    // selection must not read it again.
    $sent = count(Http::recorded());

    actingAsUser($user)->postJson('/api/platforms/square/selection', ['employeeId' => 'qgev4xbopoqbvs'])->assertStatus(422);
    expect(count(Http::recorded()))->toBe($sent);
});

it('refuses storewide for an account that books one team member at a time', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam8');
    connectSquare($user, SQ_JESSE_URL);

    actingAsUser($user)->postJson('/api/platforms/square/selection/storewide')->assertStatus(403);
    expect(squareRow($user)->payload['url'])->toContain('team_member_id=');
});

it('widens a storewide-capable account to the whole venue by dropping the team member', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqteam9', 'business');
    connectSquare($user, SQ_JESSE_URL);

    actingAsUser($user)->postJson('/api/platforms/square/selection/storewide')
        ->assertOk()
        ->assertJsonPath('selection.mode', 'storewide')
        ->assertJsonPath('url', SQ_ROOT_URL);
    expect(squareRow($user)->payload['url'])->toBe(SQ_ROOT_URL);
});

it('auto-selects the one team member whose name is the account holder', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqauto1');
    connectSquare($user, SQ_ROOT_URL);

    (new SquareAutoSelectJob((string) $user->id))->handle(app(SquareBookingClient::class), app(StaffNameMatcher::class));

    $row = squareRow($user);
    expect($row->payload['url'])->toBe(SQ_CANONICAL_JESSE)
        ->and($row->payload['autoSelected'])->toBeTrue()
        ->and($row->payload['matchTier'])->toBe('exact')
        ->and($row->payload['teamMember']['displayName'])->toBe('Jesse Jensz');
});

it('leaves the url alone when no team member matches the name', function () {
    squareWidgetFake();
    $user = squareTeamUser('sqauto2', 'partna', 'Alex', 'Kim');
    connectSquare($user, SQ_ROOT_URL);

    (new SquareAutoSelectJob((string) $user->id))->handle(app(SquareBookingClient::class), app(StaffNameMatcher::class));

    expect(squareRow($user)->payload['url'])->toBe(SQ_ROOT_URL)
        ->and(squareRow($user)->payload['autoSelected'] ?? null)->toBeNull();
});

it('leaves a storewide-capable account and an already-named link alone without calling Square', function () {
    squareWidgetFake();
    $business = squareTeamUser('sqauto3', 'business');
    connectSquare($business, SQ_ROOT_URL);
    $named = squareTeamUser('sqauto4');
    connectSquare($named, SQ_JESSE_URL);
    $sent = count(Http::recorded());

    (new SquareAutoSelectJob((string) $business->id))->handle(app(SquareBookingClient::class), app(StaffNameMatcher::class));
    (new SquareAutoSelectJob((string) $named->id))->handle(app(SquareBookingClient::class), app(StaffNameMatcher::class));

    expect(squareRow($business)->payload['url'])->toBe(SQ_ROOT_URL)
        ->and(squareRow($named)->payload['url'])->toBe(SQ_JESSE_URL);
    expect(count(Http::recorded()))->toBe($sent);
});

it('dispatches the Square auto-select from the shared booking auto-connect dispatcher', function () {
    Queue::fake();
    $user = squareTeamUser('sqauto5');

    app(AutoBookingConnectDispatcher::class)->dispatchFor((string) $user->id, 'square');

    Queue::assertPushed(SquareAutoSelectJob::class, fn (SquareAutoSelectJob $job): bool => $job->userId === (string) $user->id);
});
