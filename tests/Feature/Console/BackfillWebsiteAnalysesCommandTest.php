<?php

// SCALE-2: design:backfill-website-analyses --retry-failures used to load every
// eligible platform_connections row into memory via ->get() before restripping
// stale failed analyses. Now chunked (200 rows/round) — these prove every
// eligible connection is still processed (no truncation) and untouched
// connections are left alone.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Queue::fake();
});

function backfillUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** A 'custom' platform connection carrying a current-version FAILED styleAnalysis. */
function failedCustomConnection(User $user, string $resourceId): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'custom',
        'resource_id' => $resourceId,
        'resource_kind' => 'link',
        'payload' => [
            'kind' => 'link',
            'url' => "https://{$resourceId}.example.com",
            'styleAnalysis' => ['v' => WebsiteStyleAnalyzer::VERSION, 'ok' => false],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('strips the stale failed analysis from every eligible connection, not just the first chunk', function () {
    $user = backfillUser('backfill-many');

    // More rows than fit in a single memory-bound page would have — chunking
    // must still walk every one of them, not just the first slice.
    $ids = [];
    foreach (range(1, 12) as $i) {
        $ids[] = "conn-{$i}";
        failedCustomConnection($user, "conn-{$i}");
    }

    $this->artisan('design:backfill-website-analyses', ['--retry-failures' => true])->assertExitCode(0);

    foreach ($ids as $id) {
        $connection = IntegrationConnection::query()->where('resource_id', $id)->firstOrFail();
        expect($connection->payload)->not->toHaveKey('styleAnalysis');
    }
});

it('leaves a connection with no failed analysis untouched', function () {
    $user = backfillUser('backfill-clean');

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'custom',
        'resource_id' => 'clean-conn',
        'resource_kind' => 'link',
        'payload' => [
            'kind' => 'link',
            'url' => 'https://clean.example.com',
            'styleAnalysis' => ['v' => WebsiteStyleAnalyzer::VERSION, 'ok' => true],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $this->artisan('design:backfill-website-analyses', ['--retry-failures' => true])->assertExitCode(0);

    $connection->refresh();
    expect($connection->payload['styleAnalysis'])->toBe(['v' => WebsiteStyleAnalyzer::VERSION, 'ok' => true]);
});

it('reads the retry-failures result set in multiple chunked round trips, not one unbounded fetch', function () {
    // The actual "no longer ->get()" proof: 205 eligible rows must take >1 SELECT
    // round trip at the command's 200-row chunk size — a single ->get() would show
    // as exactly 1 SELECT and still pass the "every row processed" assertion above,
    // so this is the half that catches a chunk swap that silently regressed to a
    // full fetch.
    $user = backfillUser('backfill-chunked');
    foreach (range(1, 205) as $i) {
        failedCustomConnection($user, "chunk-conn-{$i}");
    }

    DB::connection('pgsql')->enableQueryLog();
    $this->artisan('design:backfill-website-analyses', ['--retry-failures' => true])->assertExitCode(0);
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    // Chunked SELECTs compile with an explicit `limit ... offset ...` page (visible
    // in the query log as e.g. "... order by ... id asc limit 200 offset 200"),
    // which a plain ->get() never emits — the distinct-pluck SELECT that also runs
    // later in the command has no `limit`, so this stays scoped to the retry loop.
    $chunkSelects = array_filter(
        $log,
        fn ($q) => str_starts_with(trim($q['query']), 'select')
            && str_contains($q['query'], 'platform_connections')
            && str_contains($q['query'], 'limit')
    );
    expect(count($chunkSelects))->toBeGreaterThan(1);

    // And every one of the 205 rows was actually visited — the chunk loop covers
    // the full result set, not just the first 200-row page.
    $strippedCount = IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->get()
        ->filter(fn ($c) => ! array_key_exists('styleAnalysis', $c->payload))
        ->count();
    expect($strippedCount)->toBe(205);
});

it('does not touch failed analyses when --retry-failures is omitted', function () {
    $user = backfillUser('backfill-omit');
    failedCustomConnection($user, 'omit-conn');

    $this->artisan('design:backfill-website-analyses')->assertExitCode(0);

    $connection = IntegrationConnection::query()->where('resource_id', 'omit-conn')->firstOrFail();
    expect($connection->payload)->toHaveKey('styleAnalysis');
});
