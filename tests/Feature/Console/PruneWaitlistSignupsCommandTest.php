<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// PRIV-8: hard-delete core.waitlist_signups rows from non-converting applicants once
// the retention window (default 730d) has elapsed. The converting-applicant path
// (AccountDeletionService::purgeWaitlistSignup) is tested separately.

beforeEach(function () {
    // Point pgsql at in-memory SQLite, then build the table via the standard helper.
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'partna.waitlist.retention_days' => 730,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    // setupWaitlistTable() (tests/Pest.php) mirrors prod column list including last_submitted_at.
    setupWaitlistTable();
});

/**
 * Insert a waitlist signup row and return its id.
 */
function seedWaitlistSignup(array $attrs): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.waitlist_signups')->insert(array_merge([
        'id' => $id,
        'name' => 'Test Applicant',
        'email' => 'applicant@example.com',
        'email_lc' => 'applicant@example.com',
        'phone' => '+61400000000',
        'applicant_type' => 'professional',
        'industry' => 'mens_grooming',
        'pilot_program_opt_in' => 0,
        'consent_source' => 'waitlist_form',
        'last_submitted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $attrs));

    return $id;
}

function waitlistSignupExists(string $id): bool
{
    return DB::connection('pgsql')->table('core.waitlist_signups')
        ->where('id', $id)->exists();
}

it('hard-deletes a signup whose last_submitted_at exceeds the retention window', function () {
    $id = seedWaitlistSignup([
        'email' => 'old@example.com',
        'email_lc' => 'old@example.com',
        'last_submitted_at' => now()->subDays(800)->toDateTimeString(), // outside 730-day window
    ]);

    $this->artisan('waitlist:prune-old-signups')->assertExitCode(0);

    // The whole row — all applicant PII — must be gone.
    expect(waitlistSignupExists($id))->toBeFalse();
});

it('preserves a recent signup within the retention window', function () {
    $id = seedWaitlistSignup([
        'email' => 'recent@example.com',
        'email_lc' => 'recent@example.com',
        'last_submitted_at' => now()->subDays(100)->toDateTimeString(), // well within 730 days
    ]);

    $this->artisan('waitlist:prune-old-signups')->assertExitCode(0);

    // Row inside the window must survive.
    expect(waitlistSignupExists($id))->toBeTrue();
});

it('dry-run reports eligible rows but deletes nothing', function () {
    $id = seedWaitlistSignup([
        'email' => 'dry@example.com',
        'email_lc' => 'dry@example.com',
        'last_submitted_at' => now()->subDays(800)->toDateTimeString(),
    ]);

    $this->artisan('waitlist:prune-old-signups', ['--dry-run' => true])
        ->assertExitCode(0);

    // --dry-run must leave the row in place.
    expect(waitlistSignupExists($id))->toBeTrue();
});

it('respects --days override to shorten the retention window', function () {
    // A signup 10 days old would normally survive the 730-day default.
    // With --days=5 it should be deleted.
    $id = seedWaitlistSignup([
        'email' => 'override@example.com',
        'email_lc' => 'override@example.com',
        'last_submitted_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    $this->artisan('waitlist:prune-old-signups', ['--days' => 5])->assertExitCode(0);

    expect(waitlistSignupExists($id))->toBeFalse();
});
