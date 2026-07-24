<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// CA-W2 — the DefersBespokeConnect concern, exercised in complete isolation:
// nothing in app/ uses this trait yet (W3 wires the first real consumer,
// Apple), so this file is the ONLY place its three methods run until then.
// A minimal anonymous host stands in for a future bespoke controller, mirroring
// tests/Unit/Platforms/WriteAccountConnectionPendingTest.php's own pattern for
// exactly this situation (a $pending-shaped parameter proven ahead of its wiring).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function dbcUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** A trait-only test double — see file header. */
function dbcController(string $platform): object
{
    return new class($platform) extends ApiController
    {
        use DefersBespokeConnect;
        use ManagesIntegrationConnection;

        public function __construct(private readonly string $platformKey) {}

        protected function platform(): string
        {
            return $this->platformKey;
        }

        public function callShouldDeferConnect(string $slug): bool
        {
            return $this->shouldDeferConnect($slug);
        }

        public function callDeferredConnectResponse(IntegrationConnection $row, array $partial, string $statusPath, bool $perAccount = false): JsonResponse
        {
            return $this->deferredConnectResponse($row, $partial, $statusPath, $perAccount);
        }

        public function callBespokeConnectStatus(User $user, ?string $accountId, callable $shape, bool $perAccount = false): JsonResponse
        {
            return $this->bespokeConnectStatus($user, $accountId, $shape, $perAccount);
        }

        public function callWriteConnection(User $user, array $payload, bool $pending): IntegrationConnection
        {
            return $this->writeConnection($user, $payload, pending: $pending);
        }
    };
}

// ── shouldDeferConnect ──────────────────────────────────────────────────────

it('DELIBERATELY VACUOUS — flag unset: shouldDeferConnect() is false for all six bespoke slugs, so W2 lands inert', function () {
    // Passes against unfixed code because the flag defaults to [] in every
    // test (no PARTNA_CONNECT_DEFERRED in phpunit.xml), so this proves
    // nothing about the method's own logic. It exists as the tripwire an
    // accidental default flip would trip, and as the executable statement of
    // "W2 is inert" at the config level.
    config(['partna.connect.deferred' => []]);
    $controller = dbcController('skool');

    foreach (['apple-music', 'apple-podcast', 'skool', 'eventbrite', 'humanitix', 'fresha'] as $slug) {
        expect($controller->callShouldDeferConnect($slug))->toBeFalse();
    }
});

it('a flag naming one slug defers exactly that slug and nothing else', function () {
    config(['partna.connect.deferred' => ['skool']]);
    $controller = dbcController('skool');

    expect($controller->callShouldDeferConnect('skool'))->toBeTrue();
    expect($controller->callShouldDeferConnect('apple-music'))->toBeFalse();
    expect($controller->callShouldDeferConnect('fresha'))->toBeFalse();
});

it('shouldDeferConnect() matches exactly — not by prefix, not case-insensitively', function () {
    // Pins the strict in_array against a future str_contains "improvement"
    // that would silently activate apple-podcast whenever apple-music is named.
    config(['partna.connect.deferred' => ['apple-music']]);
    $controller = dbcController('apple-music');

    expect($controller->callShouldDeferConnect('apple'))->toBeFalse();
    expect($controller->callShouldDeferConnect('apple-music-x'))->toBeFalse();
    expect($controller->callShouldDeferConnect('apple-podcast'))->toBeFalse();
    expect($controller->callShouldDeferConnect('APPLE-MUSIC'))->toBeFalse();
});

// ── deferredConnectResponse ──────────────────────────────────────────────────

it('202 for a single-selection platform carries status + statusUrl and no account segment', function () {
    $user = dbcUser('dcr1');
    $controller = dbcController('skool');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    Queue::fake();

    $response = $controller->callDeferredConnectResponse(
        $row,
        ['url' => 'https://www.skool.com/demo'],
        '/api/platforms/skool/connect/status',
        false,
    );

    expect($response->getStatusCode())->toBe(202);
    $body = $response->getData(true);
    expect($body['status'])->toBe('pending');
    expect($body['url'])->toBe('https://www.skool.com/demo');
    expect($body['statusUrl'])->toBe(url('/api/platforms/skool/connect/status'));
    expect($body)->not->toHaveKey('id');
});

it('202 for a multi-account platform carries id and ?account=, at a path the slug could never produce', function () {
    $user = dbcUser('dcr2');
    $controller = dbcController('apple-music');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-music',
        'resource_id' => 'acct-'.substr(sha1('Radiohead'), 0, 16),
        'payload' => ['input' => 'Radiohead'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    Queue::fake();

    $response = $controller->callDeferredConnectResponse(
        $row,
        ['input' => 'Radiohead'],
        '/api/platforms/apple/music/connect/status',
        true,
    );

    $body = $response->getData(true);
    // The assertion on the /apple/music/ path is the point: it proves the
    // path is caller-supplied, not derived from the 'apple-music' slug (which
    // would produce a URL that does not exist as a route).
    expect($body['id'])->toBe($row->resource_id);
    expect($body['statusUrl'])->toBe(url('/api/platforms/apple/music/connect/status').'?account='.$row->resource_id);
});

it('the envelope wins over a colliding partial key', function () {
    $user = dbcUser('dcr3');
    $controller = dbcController('eventbrite');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-'.substr(sha1('https://www.eventbrite.com/o/some-organiser'), 0, 16),
        'payload' => ['url' => 'https://www.eventbrite.com/o/some-organiser'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    Queue::fake();

    // A partial that (implausibly but legally) carries its own 'status' and
    // 'id' keys — mirroring how an Eventbrite/Humanitix partial can carry its
    // own 'id' via EventsPayload::withIds.
    $response = $controller->callDeferredConnectResponse(
        $row,
        ['status' => 'nope', 'id' => 'payload-id', 'url' => 'https://www.eventbrite.com/o/some-organiser'],
        '/api/platforms/eventbrite/connect/status',
        true,
    );

    $body = $response->getData(true);
    expect($body['status'])->toBe('pending');
    expect($body['id'])->toBe($row->resource_id);
});

it('deferredConnectResponse dispatches exactly one ConnectFetchJob for the row\'s own platform', function () {
    $user = dbcUser('dcr4');
    $controller = dbcController('skool');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    Queue::fake();

    $controller->callDeferredConnectResponse($row, ['url' => 'https://www.skool.com/demo'], '/api/platforms/skool/connect/status');

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === $row->platform);
    Queue::assertPushedTimes(ConnectFetchJob::class, 1);
});

// ── bespokeConnectStatus ─────────────────────────────────────────────────────

it('poll 404s (never 403) when the caller has no row for this platform', function () {
    $user = dbcUser('poll1');
    $controller = dbcController('skool');

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getData(true)['message'])->toBe('Account not found.');
});

it('poll 404s (never 403) for another user\'s row', function () {
    $owner = dbcUser('pollowner');
    $stranger = dbcUser('pollstranger');
    $controller = dbcController('apple-music');

    $row = IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'apple-music',
        'resource_id' => 'acct-'.substr(sha1('Radiohead'), 0, 16),
        'payload' => ['input' => 'Radiohead'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    $response = $controller->callBespokeConnectStatus($stranger, $row->resource_id, fn (array $p) => $p, true);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getData(true)['message'])->toBe('Account not found.');
});

it('a freshly-written pending row reports exactly {status: pending}', function () {
    $user = dbcUser('poll2');
    $controller = dbcController('skool');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    expect($response->getData(true))->toBe(['status' => 'pending']);
});

it('PORTED STALENESS — a pending row untouched for more than 5 minutes reports failed with the stranded-connection sentence', function () {
    $user = dbcUser('stale1');
    $controller = dbcController('skool');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    // Deliberately a query-builder mass update, NOT $row->save(): Eloquent's
    // save() re-touches updated_at to now() whenever the model uses
    // timestamps, silently discarding a manually forceFill()'d value.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);
    $row->refresh();

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    expect($response->getData(true))->toBe([
        'status' => 'failed',
        'error' => "We couldn't save your connection just then — please try again.",
    ]);
});

it('a pending row inside the 5-minute window is not stale', function () {
    $user = dbcUser('stale2');
    $controller = dbcController('skool');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(4)]);
    $row->refresh();

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    expect($response->getData(true))->toBe(['status' => 'pending']);
});

it('the staleness verdict is synthetic — the row is never written, so a late worker can still complete it', function () {
    $user = dbcUser('stale3');
    $controller = dbcController('skool');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

    $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refresh_error)->toBeNull();
});

it('a ready multi-account poll returns the shaped connection and the row id', function () {
    $user = dbcUser('ready1');
    $controller = dbcController('apple-music');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-music',
        'resource_id' => 'acct-'.substr(sha1('Radiohead'), 0, 16),
        'payload' => ['input' => 'Radiohead', 'name' => 'Radiohead', 'thumbnail' => 'https://example.test/art.jpg'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $seenPayload = null;
    $shape = function (array $payload) use (&$seenPayload) {
        $seenPayload = $payload;

        return ['marker' => true];
    };

    $response = $controller->callBespokeConnectStatus($user, $row->resource_id, $shape, true);

    $body = $response->getData(true);
    expect($body['status'])->toBe('ready');
    expect($body['id'])->toBe($row->resource_id);
    expect($body['connection'])->toBe(['marker' => true]);
    // $shape received the row's RAW stored payload, not a re-shaped copy.
    expect($seenPayload)->toBe($row->payload);
});

it('a ready single-selection poll omits id', function () {
    $user = dbcUser('ready2');
    $controller = dbcController('skool');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo', 'name' => 'Demo Community'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    $body = $response->getData(true);
    expect($body['status'])->toBe('ready');
    expect($body)->not->toHaveKey('id');
    expect($body['connection'])->toBe($row->payload);
});

it('a failed poll surfaces the row\'s stored sentence verbatim', function () {
    $user = dbcUser('failed1');
    $controller = dbcController('skool');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'unavailable',
        'last_refresh_error' => 'Could not read that Skool community — check the URL.',
    ]);

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    expect($response->getData(true))->toBe([
        'status' => 'failed',
        'error' => 'Could not read that Skool community — check the URL.',
    ]);
});

it('a failed poll with no stored error falls back to the shared infrastructure sentence, never null', function () {
    $user = dbcUser('failed2');
    $controller = dbcController('skool');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/demo'],
        'is_active' => true,
        'last_refresh_status' => 'error',
        'last_refresh_error' => null,
    ]);

    $response = $controller->callBespokeConnectStatus($user, null, fn (array $p) => $p, false);

    // Pins the deliberate deviation from GenericPlatformController::connectStatus(),
    // which can emit {"error":null} here.
    expect($response->getData(true)['error'])->toBe('We could not load that account. Please try again.');
});

it('a single-selection poll ignores a stray ?account= and always reads the default row', function () {
    $user = dbcUser('ignore1');
    $controller = dbcController('skool');

    $default = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/default'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'acct-decoy0000000000',
        'canonical_key' => 'decoy',
        'payload' => ['url' => 'https://www.skool.com/decoy'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $response = $controller->callBespokeConnectStatus($user, 'acct-decoy0000000000', fn (array $p) => $p, false);

    expect($response->getData(true)['connection'])->toBe($default->payload);
});

// ── Postgres-vs-SQLite guard (constraint 9) ─────────────────────────────────

it('a pending write through the concern\'s own host stores non-empty payload content, never null', function () {
    // payload is jsonb NOT NULL DEFAULT '{}' in Postgres
    // (20260602150238_create_platform_connections.sql) — a null placeholder
    // would pass SQLite and 500 in production with SQLSTATE 23502. Assert
    // CONTENT, not merely non-null.
    $user = dbcUser('pgw1');
    $controller = dbcController('skool');

    $row = $controller->callWriteConnection($user, ['url' => 'https://www.skool.com/demo'], true);

    expect($row->payload)->toBe(['url' => 'https://www.skool.com/demo']);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
});

// ── Structural tripwire ──────────────────────────────────────────────────────

it('DELIBERATELY VACUOUS — every app/ class using DefersBespokeConnect also uses ManagesIntegrationConnection', function () {
    // Vacuously true at W2 (the set is empty — which is itself the inertness
    // proof at the class level, complementing the config-level proof above).
    // Becomes load-bearing from W3 onward: the concern hard-depends on
    // platform()/connectionFor()/requestedAccountRow(), and a class using it
    // alone would fatal at runtime, not at boot. Deliberately phrased as an
    // invariant that stays true forever, so no later unit has to edit or
    // delete it.
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (str_contains($contents, 'use DefersBespokeConnect;') && ! str_contains($contents, 'use ManagesIntegrationConnection;')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
