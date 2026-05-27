<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;
use App\Services\Moderation\ModerationAuditService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupPartnaStaffTable();
    setupAuditModerationEventsTable();
});

it('records a staff action', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'case.triaged', 'Case', '11111111-1111-1111-1111-111111111111', ['notes' => 'spam']);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->actor_kind)->toBe('staff');
    expect($row->actor_staff_id)->toBe($staff->id);
    expect($row->action)->toBe('case.triaged');
    expect($row->target_type)->toBe('Case');
    expect($row->payload)->toBe(['notes' => 'spam']);
});

it('records a system action with null actor_staff_id', function () {
    app(ModerationAuditService::class)
        ->recordSystemAction('csam.auto_action', 'Case', '22222222-2222-2222-2222-222222222222', []);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->actor_kind)->toBe('system');
    expect($row->actor_staff_id)->toBeNull();
});

it('redacts PII keys from payload when configured', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'reporter.contacted', null, null, [
            'safe_field' => 'ok',
            'email'      => 'leak@example.com',
            'raw_ip'     => '203.0.113.1',
        ]);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->payload)->toHaveKey('safe_field');
    expect($row->payload)->not->toHaveKey('email');
    expect($row->payload)->not->toHaveKey('raw_ip');
});
