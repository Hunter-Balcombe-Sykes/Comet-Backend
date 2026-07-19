<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupEarlyAccessTable();
});

function seedEarlyAccessRow(string $emailLc, string $createdAt, string $status = 'waitlist'): void
{
    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => $emailLc,
        'email_lc' => $emailLc,
        'type' => 'partna',
        'workplace_or_industry' => 'Test Studio',
        'platforms' => '[]',
        'status' => $status,
        'source' => 'marketing',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('deletes non-converting rows older than the retention window', function () {
    seedEarlyAccessRow('old@example.com', now()->subDays(800)->toDateTimeString());
    seedEarlyAccessRow('recent@example.com', now()->subDays(10)->toDateTimeString());

    $this->artisan('early-access:prune-old-signups')->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'old@example.com')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'recent@example.com')->exists())->toBeTrue();
});

it('never deletes signed_up rows regardless of age', function () {
    seedEarlyAccessRow('converted@example.com', now()->subDays(800)->toDateTimeString(), 'signed_up');

    $this->artisan('early-access:prune-old-signups')->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'converted@example.com')->exists())->toBeTrue();
});

it('deletes nothing on a dry run', function () {
    seedEarlyAccessRow('old@example.com', now()->subDays(800)->toDateTimeString());

    $this->artisan('early-access:prune-old-signups', ['--dry-run' => true])->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'old@example.com')->exists())->toBeTrue();
});

it('honours the --days override', function () {
    seedEarlyAccessRow('old@example.com', now()->subDays(20)->toDateTimeString());

    $this->artisan('early-access:prune-old-signups', ['--days' => 5])->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.early_access_signups')
        ->where('email_lc', 'old@example.com')->exists())->toBeFalse();
});
