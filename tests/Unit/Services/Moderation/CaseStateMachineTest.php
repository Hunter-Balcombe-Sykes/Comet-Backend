<?php

use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\CaseStateMachine;
use App\Services\Moderation\IllegalCaseTransition;

it('allows open -> triaged', function () {
    $case = ModerationCase::factory()->make(['status' => 'open']);
    (new CaseStateMachine)->transition($case, 'triaged');
    expect($case->status)->toBe('triaged');
});

it('allows triaged -> under_review', function () {
    $case = ModerationCase::factory()->make(['status' => 'triaged']);
    (new CaseStateMachine)->transition($case, 'under_review');
    expect($case->status)->toBe('under_review');
});

it('allows under_review -> resolved', function () {
    $case = ModerationCase::factory()->make(['status' => 'under_review']);
    (new CaseStateMachine)->transition($case, 'resolved');
    expect($case->status)->toBe('resolved');
});

it('allows under_review -> triaged (release)', function () {
    $case = ModerationCase::factory()->make(['status' => 'under_review']);
    (new CaseStateMachine)->transition($case, 'triaged');
    expect($case->status)->toBe('triaged');
});

it('treats resolved as terminal', function () {
    $case = ModerationCase::factory()->make(['status' => 'resolved']);
    expect(fn () => (new CaseStateMachine)->transition($case, 'open'))
        ->toThrow(IllegalCaseTransition::class);
});

it('rejects open -> under_review (must go via triaged)', function () {
    $case = ModerationCase::factory()->make(['status' => 'open']);
    expect(fn () => (new CaseStateMachine)->transition($case, 'under_review'))
        ->toThrow(IllegalCaseTransition::class, 'open -> under_review');
});

it('allows open -> auto_actioned for CSAM-style cases', function () {
    $case = ModerationCase::factory()->make(['status' => 'open']);
    (new CaseStateMachine)->transition($case, 'auto_actioned');
    expect($case->status)->toBe('auto_actioned');
});

it('allows auto_actioned -> resolved (staff confirms or overrides)', function () {
    $case = ModerationCase::factory()->make(['status' => 'auto_actioned']);
    (new CaseStateMachine)->transition($case, 'resolved');
    expect($case->status)->toBe('resolved');
});
