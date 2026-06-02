<?php

use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use Tests\TestCase;

// Opt in to the full Laravel bootstrap — the Pest.php default only binds
// TestCase for tests/Feature; this unit test exercises the real model + DB,
// so it needs facades resolved.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupModerationCasesTable();
});

it('creates a moderation case via factory with sensible defaults', function () {
    $owner = User::factory()->create();
    $case = ModerationCase::factory()->forOwner($owner)->create();

    expect($case->case_type)->toBe('content_report');
    expect($case->severity)->toBe(2);
    expect($case->status)->toBe('open');
    expect($case->signal_count)->toBe(1);
    expect($case->priority)->toBe(5);
    expect($case->reportable_type)->toBe('Site');
});

it('uses the moderation schema (not public)', function () {
    expect((new ModerationCase)->getTable())->toBe('moderation.cases');
});
