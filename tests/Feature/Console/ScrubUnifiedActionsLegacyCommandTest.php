<?php

// #MIG-1: SQLite can only prove the command is wired up and fails closed on a
// non-Postgres connection — its actual predicates (!~, jsonb_exists_any) are
// Postgres-only syntax that would throw a driver-level error, not a test
// failure, if exercised here. Real behaviour coverage lives in
// tests/Postgres/UnifiedActionsLegacyScrubTest.php (composer test:pg).

use App\Console\Commands\ScrubUnifiedActionsLegacyCommand;
use Illuminate\Contracts\Console\Kernel;

it('is registered under the expected artisan signature', function () {
    expect(array_key_exists(
        'partna:scrub-unified-actions-legacy',
        app(Kernel::class)->all(),
    ))->toBeTrue();
});

it('rejects an unknown --only value before touching the database', function () {
    $this->artisan('partna:scrub-unified-actions-legacy', ['--only' => 'bogus'])
        ->expectsOutputToContain('--only must be one of: action-events|site-settings|page-scores')
        ->assertExitCode(ScrubUnifiedActionsLegacyCommand::FAILURE);
});

it('fails closed with FAILURE on a non-Postgres connection instead of throwing a driver-level syntax error', function () {
    // The default test connection is SQLite (see tests/Pest.php) — this is
    // exactly the case the driver guard exists for.
    $this->artisan('partna:scrub-unified-actions-legacy')
        ->assertExitCode(ScrubUnifiedActionsLegacyCommand::FAILURE);
});
