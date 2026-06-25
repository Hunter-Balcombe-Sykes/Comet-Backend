<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// DINT-6: weekly prune of non-account reporter PII (reporter_email, reason_details,
// signal_data) on case_signals whose parent case has been resolved for more than the
// retention window (default 90 days). The account-reporter path is handled separately
// by AccountDeletionService::purgeCaseSignalPii().

beforeEach(function () {
    // Route 'pgsql' to an in-memory SQLite instance for isolated test execution.
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'partna.moderation.signal_pii_retention_days' => 90,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    // Sets up both moderation.cases and moderation.case_signals (the helper chain
    // calls setupModerationCasesTable() internally — see tests/Pest.php).
    setupModerationCaseSignalsTable();
});

/**
 * Insert a moderation case and return its id.
 */
function seedModerationCase(string $status, ?string $resolvedAt = null): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('moderation.cases')->insert([
        'id' => $id,
        'case_type' => 'content_report',
        'reportable_type' => 'Site',
        'reportable_id' => (string) Str::uuid(),
        'severity' => 2,
        'status' => $status,
        'signal_count' => 1,
        'auto_actioned' => 0,
        'priority' => 5,
        'resolved_at' => $resolvedAt,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

/**
 * Insert a case_signal and return its id.
 *
 * Omit reporter_user_id to simulate an anonymous (non-account) reporter.
 */
function seedCaseSignal(string $caseId, array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('moderation.case_signals')->insert(array_merge([
        'id' => $id,
        'case_id' => $caseId,
        'signal_source' => 'content_report',
        'signal_data' => '{"details":"verbatim reporter text"}',
        'reporter_user_id' => null,
        'reporter_email' => 'anon@example.com',
        'reporter_ip_hash' => 'sha256hashvalue',
        'reason_code' => 'spam',
        'reason_details' => 'Detailed freetext from anonymous reporter.',
        'dedup_hash' => 'dedup1',
        'created_at' => now()->toDateTimeString(),
    ], $attrs));

    return $id;
}

/**
 * Fetch a case_signal row by id.
 */
function fetchSignal(string $id): ?object
{
    return DB::connection('pgsql')
        ->table('moderation.case_signals')
        ->where('id', $id)
        ->first();
}

it('nulls reporter PII on a signal whose resolved parent case is outside the retention window', function () {
    // Case resolved 100 days ago — beyond the 90-day window.
    $caseId = seedModerationCase('resolved', now()->subDays(100)->toDateTimeString());
    $signalId = seedCaseSignal($caseId);

    $this->artisan('moderation:prune-resolved-signal-pii')->assertExitCode(0);

    $signal = fetchSignal($signalId);

    // PII columns must be erased.
    expect($signal->reporter_email)->toBeNull();
    expect($signal->reason_details)->toBeNull();
    expect($signal->signal_data)->toBe('{}');

    // Non-PII analytics columns must be RETAINED.
    expect($signal->reporter_ip_hash)->toBe('sha256hashvalue');
    expect($signal->reason_code)->toBe('spam');
    expect($signal->dedup_hash)->toBe('dedup1');
    expect($signal->signal_source)->toBe('content_report');
    expect($signal->case_id)->toBe($caseId);
});

it('also prunes signals on auto_actioned cases outside the retention window', function () {
    // auto_actioned is the second "closed" status value per the cases_status_check constraint.
    $caseId = seedModerationCase('auto_actioned', now()->subDays(120)->toDateTimeString());
    $signalId = seedCaseSignal($caseId);

    $this->artisan('moderation:prune-resolved-signal-pii')->assertExitCode(0);

    $signal = fetchSignal($signalId);
    expect($signal->reporter_email)->toBeNull();
    expect($signal->reason_details)->toBeNull();
    expect($signal->signal_data)->toBe('{}');
});

it('leaves signals on an open (unresolved) case untouched', function () {
    // status = 'open' — not in the resolved set; resolved_at is NULL.
    $caseId = seedModerationCase('open', null);
    $signalId = seedCaseSignal($caseId);

    $this->artisan('moderation:prune-resolved-signal-pii')->assertExitCode(0);

    $signal = fetchSignal($signalId);
    expect($signal->reporter_email)->toBe('anon@example.com');
    expect($signal->reason_details)->toBe('Detailed freetext from anonymous reporter.');
});

it('leaves signals on a recently-resolved case within the retention window untouched', function () {
    // Case resolved only 10 days ago — inside the 90-day window.
    $caseId = seedModerationCase('resolved', now()->subDays(10)->toDateTimeString());
    $signalId = seedCaseSignal($caseId);

    $this->artisan('moderation:prune-resolved-signal-pii')->assertExitCode(0);

    $signal = fetchSignal($signalId);
    expect($signal->reporter_email)->toBe('anon@example.com');
    expect($signal->reason_details)->toBe('Detailed freetext from anonymous reporter.');
});

it('dry-run reports eligible signals but mutates nothing', function () {
    $caseId = seedModerationCase('resolved', now()->subDays(100)->toDateTimeString());
    $signalId = seedCaseSignal($caseId);

    $this->artisan('moderation:prune-resolved-signal-pii', ['--dry-run' => true])
        ->assertExitCode(0);

    // --dry-run must not write anything.
    $signal = fetchSignal($signalId);
    expect($signal->reporter_email)->toBe('anon@example.com');
    expect($signal->reason_details)->toBe('Detailed freetext from anonymous reporter.');
    expect($signal->signal_data)->toBe('{"details":"verbatim reporter text"}');
});

it('respects --days override to shorten the window', function () {
    // Resolved 20 days ago — safe under the 90-day default, but inside --days=10.
    $caseId = seedModerationCase('resolved', now()->subDays(20)->toDateTimeString());
    $signalId = seedCaseSignal($caseId);

    $this->artisan('moderation:prune-resolved-signal-pii', ['--days' => 10])->assertExitCode(0);

    $signal = fetchSignal($signalId);
    expect($signal->reporter_email)->toBeNull();
});

it('is idempotent — re-running after PII is already nulled leaves no side effects', function () {
    $caseId = seedModerationCase('resolved', now()->subDays(100)->toDateTimeString());
    $signalId = seedCaseSignal($caseId);

    // First run — erases PII.
    $this->artisan('moderation:prune-resolved-signal-pii')->assertExitCode(0);

    // Second run — whereNotNull('reporter_email') guard means no rows are targeted.
    $this->artisan('moderation:prune-resolved-signal-pii')->assertExitCode(0);

    $signal = fetchSignal($signalId);
    expect($signal->reporter_email)->toBeNull();
    expect($signal->signal_data)->toBe('{}');
});
