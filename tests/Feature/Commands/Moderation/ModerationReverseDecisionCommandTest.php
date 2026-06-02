<?php

use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupAllModerationTables();
    setupPartnaStaffTable();
    setupUsersTable();
});

it('writes a new decision with supersedes_decision_id pointing at the original', function () {
    $case = ModerationCase::factory()->resolved()->create();
    $original = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);

    $this->artisan("moderation:reverse-decision {$original->id} --reason=false-positive-confirmed")
        ->assertSuccessful();

    $reversal = Decision::query()
        ->where('supersedes_decision_id', $original->id)
        ->latest('decided_at')
        ->first();

    expect($reversal)->not->toBeNull();
    expect($reversal->decision_type)->toBe('dismiss');
    expect(AuditEvent::query()->where('action', 'decision.reversed')->exists())->toBeTrue();
});

it('does not mutate the original decision', function () {
    $case = ModerationCase::factory()->resolved()->create();
    $original = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);

    $this->artisan("moderation:reverse-decision {$original->id} --reason=false-positive")
        ->assertSuccessful();

    // Original must be unchanged
    expect($original->fresh()->decision_type)->toBe('suspend_user');
});

it('fails when the decision does not exist', function () {
    $this->artisan('moderation:reverse-decision 00000000-0000-0000-0000-000000000000 --reason=test')
        ->assertFailed();
});
