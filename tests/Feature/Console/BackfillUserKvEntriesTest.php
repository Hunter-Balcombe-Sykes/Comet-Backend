<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
});

it('dispatches SyncSubdomainToKvJob for each individual professional', function () {
    Bus::fake();

    $individualId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $individualId,
        'handle' => 'solo1',
        'handle_lc' => 'solo1',
        'account_type' => 'individual',
        'status' => 'active',
        'primary_email' => 'i@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('partna:backfill-user-kv-entries')
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($job) => $job->userId === $individualId);
    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 1);
});

it('--dry-run reports the cohort and dispatches nothing', function () {
    Bus::fake();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'handle' => 'solo2',
        'handle_lc' => 'solo2',
        'account_type' => 'individual',
        'status' => 'active',
        'primary_email' => 'i2@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('partna:backfill-user-kv-entries', ['--dry-run' => true])
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
