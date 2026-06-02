<?php

use App\Console\Commands\NotifyHandleAliasExpiry;
use App\Mail\HandleAliasExpiringMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupHandleAliasesTable();
});

it('sends a T-3 email exactly once per alias and stamps notified_t3_at', function () {
    Mail::fake();

    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'notifytest',
        'handle_lc' => 'notifytest',
        'status' => 'active',
        'primary_email' => 'notifytest@example.test',

        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $aliasId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => $aliasId,
        'user_id' => $proId,
        'handle' => 'oldnotify',
        'reclaim_until' => now()->subDays(11)->toDateTimeString(),
        'expires_at' => now()->addDays(2)->addHours(12)->toDateTimeString(), // within T-3
        'notified_t3_at' => null,
        'notified_t1_at' => null,
        'created_at' => now()->subDays(87)->toDateTimeString(),
        'updated_at' => now()->subDays(87)->toDateTimeString(),
    ]);

    $this->artisan(NotifyHandleAliasExpiry::class)->assertSuccessful();
    Mail::assertQueued(HandleAliasExpiringMail::class, 1);

    // Second run is no-op (notified_t3_at now set)
    $this->artisan(NotifyHandleAliasExpiry::class)->assertSuccessful();
    Mail::assertQueued(HandleAliasExpiringMail::class, 1);

    $updated = DB::connection('pgsql')->table('core.user_handle_aliases')
        ->where('id', $aliasId)->first();
    expect($updated->notified_t3_at)->not->toBeNull();
});
