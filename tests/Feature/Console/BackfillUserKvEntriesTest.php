<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
});

/** Insert a user row directly; $type must be a value prod's CHECK permits. */
function backfillSeedUser(string $handle, string $type): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => $handle,
        'account_type' => $type,
        'status' => 'active',
        'primary_email' => $handle.'@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('dispatches SyncSubdomainToKvJob for a partna account', function () {
    Bus::fake();

    $id = backfillSeedUser('solo1', 'partna');

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($job) => $job->userId === $id);
    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 1);
});

// The cutover cohort is mixed. A filter that silently excludes business
// accounts would leave half the sitepages unroutable after a fresh-KV
// provision, while still reporting success.
it('dispatches for business accounts too', function () {
    Bus::fake();

    backfillSeedUser('solo2', 'partna');
    backfillSeedUser('shop1', 'business');

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 2')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 2);
});

it('skips users with no handle', function () {
    Bus::fake();

    backfillSeedUser('solo3', 'partna');

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'handle' => null,
        'handle_lc' => null,
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'nohandle@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 1);
});

it('--dry-run reports the cohort and dispatches nothing', function () {
    Bus::fake();

    backfillSeedUser('solo4', 'partna');

    $this->artisan('partna:backfill-user-kv-entries', ['--dry-run' => true])
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
