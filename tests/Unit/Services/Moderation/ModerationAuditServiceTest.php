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

it('scrubs PII keys regardless of case (Email, EMAIL, reporter_IP)', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'case.viewed', null, null, [
            'safe'        => 'keep',
            'Email'       => 'mixed@case.com',
            'EMAIL'       => 'upper@case.com',
            'reporter_IP' => '10.0.0.1',
        ]);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->payload)->toHaveKey('safe');
    expect($row->payload)->not->toHaveKey('Email');
    expect($row->payload)->not->toHaveKey('EMAIL');
    expect($row->payload)->not->toHaveKey('reporter_IP');
});

it('scrubs PII keys in nested arrays (recursive)', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'case.viewed', null, null, [
            'safe'    => 'keep',
            'context' => [
                'safe_nested' => 'also keep',
                'email'       => 'nested-leak@example.com',
                'deep'        => [
                    'raw_ip' => '192.168.0.1',
                    'note'   => 'keep this',
                ],
            ],
        ]);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->payload['safe'])->toBe('keep');
    expect($row->payload['context'])->toHaveKey('safe_nested');
    expect($row->payload['context'])->not->toHaveKey('email');
    expect($row->payload['context']['deep'])->not->toHaveKey('raw_ip');
    expect($row->payload['context']['deep']['note'])->toBe('keep this');
});

it('scrubs expanded synonym keys (email_address, ip_address, access_token)', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'case.viewed', null, null, [
            'safe'          => 'keep',
            'email_address' => 'test@example.com',
            'ip_address'    => '203.0.113.5',
            'access_token'  => 'tok_123abc',
        ]);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->payload)->toHaveKey('safe');
    expect($row->payload)->not->toHaveKey('email_address');
    expect($row->payload)->not->toHaveKey('ip_address');
    expect($row->payload)->not->toHaveKey('access_token');
});
