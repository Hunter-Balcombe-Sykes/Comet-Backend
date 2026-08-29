<?php

// Feature tests for `moderation:sla-scan` (#W1-LIFE-1): the scan is the
// only mechanism catching cases about to miss their SLA deadline. Before
// this fix its only output was a Log::warning breadcrumb — nothing paged
// on-call. Verifies: one aggregate report per at-risk scan, suppressed by
// a per-severity-band cooldown across runs in the SAME process (CACHE_STORE
// is `array` in tests, so cooldown state cannot be split across it() blocks),
// with an escalation to a higher severity band paging through the cooldown.

use App\Exceptions\Moderation\ModerationSlaBreachRiskException;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    setupAllModerationTables();
    config()->set('partna.moderation.sla.breach_warning_min', 120);
    config()->set('partna.moderation.sla.alert_cooldown_seconds', 3600);
});

it('3 at-risk cases produce exactly one aggregate report', function () {
    Exceptions::fake();

    ModerationCase::factory()->count(3)->create([
        'status' => 'open',
        'severity' => 3,
        'sla_due_at' => now()->addMinutes(30),
    ]);

    $this->artisan('moderation:sla-scan')->assertExitCode(0);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (ModerationSlaBreachRiskException $e) => $e->count === 3 && $e->maxSeverity === 3);
});

it('does not re-report within the cooldown', function () {
    Exceptions::fake();

    ModerationCase::factory()->create([
        'status' => 'open',
        'severity' => 3,
        'sla_due_at' => now()->addMinutes(30),
    ]);

    $this->artisan('moderation:sla-scan')->assertExitCode(0);
    $this->artisan('moderation:sla-scan')->assertExitCode(0);

    Exceptions::assertReportedCount(1);
});

it('escalation to a higher severity pages through the cooldown', function () {
    Exceptions::fake();

    ModerationCase::factory()->create([
        'status' => 'open',
        'severity' => 3,
        'sla_due_at' => now()->addMinutes(30),
    ]);

    $this->artisan('moderation:sla-scan')->assertExitCode(0);
    Exceptions::assertReportedCount(1);

    ModerationCase::factory()->create([
        'status' => 'open',
        'severity' => 5,
        'sla_due_at' => now()->addMinutes(10),
    ]);

    $this->artisan('moderation:sla-scan')->assertExitCode(0);

    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (ModerationSlaBreachRiskException $e) => $e->maxSeverity === 5);
});

it('no at-risk cases reports nothing', function () {
    Exceptions::fake();

    ModerationCase::factory()->create([
        'status' => 'open',
        'severity' => 3,
        'sla_due_at' => now()->addHours(6),
    ]);

    $this->artisan('moderation:sla-scan')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('the per-case breach_risk breadcrumb still fires for every at-risk case', function () {
    Log::spy();

    ModerationCase::factory()->count(3)->create([
        'status' => 'open',
        'severity' => 3,
        'sla_due_at' => now()->addMinutes(30),
    ]);

    $this->artisan('moderation:sla-scan')->assertExitCode(0);

    Log::shouldHaveReceived('warning')
        ->with('moderation.sla.breach_risk', Mockery::type('array'))
        ->times(3);
});
