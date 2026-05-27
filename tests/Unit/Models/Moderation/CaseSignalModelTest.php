<?php

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use Tests\TestCase;

// Opt in to the full Laravel bootstrap — the Pest.php default only binds
// TestCase for tests/Feature; this unit test exercises the real model + DB,
// so it needs facades resolved.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
});

it('belongs to a case', function () {
    $case   = ModerationCase::factory()->create();
    $signal = CaseSignal::factory()->forCase($case)->create();

    expect($signal->case->id)->toBe($case->id);
    expect($signal->signal_source)->toBe('content_report');
    expect($signal->reason_code)->toBe('spam');
});

it('uses the moderation schema', function () {
    expect((new CaseSignal)->getTable())->toBe('moderation.case_signals');
});
