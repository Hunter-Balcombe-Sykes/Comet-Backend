<?php

use App\Models\Core\Staff\StaffAuditEntry;
use Tests\TestCase;

uses(TestCase::class);

it('uses the audit.staff_audit_log table', function () {
    expect((new StaffAuditEntry)->getTable())->toBe('audit.staff_audit_log');
});

it('has uuid primary key with non-incrementing string type', function () {
    $model = new StaffAuditEntry;
    expect($model->incrementing)->toBeFalse()
        ->and($model->getKeyType())->toBe('string');
});

it('casts payload_summary to array and timestamps to datetime', function () {
    $model = new StaffAuditEntry;
    $casts = $model->getCasts();
    expect($casts)->toHaveKey('payload_summary', 'array')
        ->and($casts)->toHaveKey('created_at', 'datetime');
});

it('exposes a working factory', function () {
    expect(StaffAuditEntry::factory()->make())->toBeInstanceOf(StaffAuditEntry::class);
});
