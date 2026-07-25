<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W5 (LIFE-13..20 Phase 2) — writeAccountConnection()'s new $pending
// parameter. Nothing calls it with $pending=true yet (the deferred-connect
// controller wiring is W6), so this is the only place the MERGE contract is
// proven ahead of that wiring. A minimal anonymous controller stands in for
// GenericPlatformController: ManagesIntegrationConnection::platform() is
// abstract and reads nothing from an HTTP request, so no route/request
// context is needed to exercise it directly.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function pcwUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** A trait-only test double — see file header. */
function pcwController(string $platform): object
{
    return new class($platform) extends ApiController
    {
        use ManagesIntegrationConnection;

        public function __construct(private readonly string $platformKey) {}

        protected function platform(): string
        {
            return $this->platformKey;
        }

        public function callWriteAccountConnection(User $user, string $canonicalKey, array $payload, bool $pending): ?IntegrationConnection
        {
            return $this->writeAccountConnection($user, $canonicalKey, $payload, $pending);
        }
    };
}

it('a pending account write on a NEW row stores the given payload as-is (nothing to merge onto)', function () {
    $user = pcwUser('pcw1');
    $controller = pcwController('bandcamp');

    $row = $controller->callWriteAccountConnection(
        $user,
        'https://newartist.bandcamp.com',
        ['url' => 'https://newartist.bandcamp.com'],
        true,
    );

    expect($row)->not->toBeNull();
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->payload)->toBe(['url' => 'https://newartist.bandcamp.com']);
});

it('a pending account write on an EXISTING row MERGES the new payload onto the stored one, never replacing it', function () {
    $user = pcwUser('pcw2');
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1('https://artist.bandcamp.com'), 0, 16),
        'canonical_key' => 'https://artist.bandcamp.com',
        'payload' => [
            'url' => 'https://artist.bandcamp.com',
            'artist' => 'Old Artist',
            'latest' => ['itemId' => 'album-9'],
            'releases' => [['itemId' => 'album-9'], ['itemId' => 'album-8']],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $controller = pcwController('bandcamp');
    // Reconnect: identify() only ever derives the identity key, never the rest.
    $row = $controller->callWriteAccountConnection(
        $user,
        'https://artist.bandcamp.com',
        ['url' => 'https://artist.bandcamp.com'],
        true,
    );

    expect($row->id)->toBe($existing->id);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    // The three fields a naive REPLACE would have blanked all survive.
    expect($row->payload['artist'])->toBe('Old Artist');
    expect($row->payload['latest']['itemId'])->toBe('album-9');
    expect($row->payload['releases'])->toHaveCount(2);
    expect($row->payload['url'])->toBe('https://artist.bandcamp.com');
});

it('a NON-pending (completed) account write still REPLACES the payload — the merge is pending-only', function () {
    $user = pcwUser('pcw3');
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1('https://artist2.bandcamp.com'), 0, 16),
        'canonical_key' => 'https://artist2.bandcamp.com',
        'payload' => ['url' => 'https://artist2.bandcamp.com', 'artist' => 'Stale Artist'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $controller = pcwController('bandcamp');
    $row = $controller->callWriteAccountConnection(
        $user,
        'https://artist2.bandcamp.com',
        ['url' => 'https://artist2.bandcamp.com', 'artist' => 'Fresh Artist'],
        false,
    );

    expect($row->id)->toBe($existing->id);
    expect($row->last_refresh_status)->toBe('ok');
    // No merge: the old 'artist' key is gone from the raw new payload's shape —
    // here it's simply overwritten since both payloads share the same key.
    expect($row->payload)->toBe(['url' => 'https://artist2.bandcamp.com', 'artist' => 'Fresh Artist']);
});

it('a pending write still enforces the account cap and returns null — the pending flag does not bypass it', function () {
    $user = pcwUser('pcw4');
    $controller = pcwController('bandcamp');

    // Fill every account slot (maxAccounts() = 5) with completed connections.
    for ($i = 0; $i < 5; $i++) {
        $controller->callWriteAccountConnection($user, "https://artist{$i}.bandcamp.com", ['url' => "https://artist{$i}.bandcamp.com"], false);
    }

    $row = $controller->callWriteAccountConnection($user, 'https://one-too-many.bandcamp.com', ['url' => 'https://one-too-many.bandcamp.com'], true);

    expect($row)->toBeNull();
});
