<?php

use App\DTOs\Moderation\DecisionDto;

it('exposes typed decision fields', function () {
    $dto = new DecisionDto(
        decisionType: 'hide_site',
        reason: 'repeated spam',
        secondStaffApprovalId: null,
    );
    expect($dto->decisionType)->toBe('hide_site');
    expect($dto->reason)->toBe('repeated spam');
    expect($dto->secondStaffApprovalId)->toBeNull();
});

it('captures the CSAM override two-staff approver id', function () {
    $dto = new DecisionDto(
        decisionType: 'override_csam_auto_action',
        reason: 'confirmed false positive',
        secondStaffApprovalId: '11111111-1111-1111-1111-111111111111',
    );
    expect($dto->secondStaffApprovalId)->toBe('11111111-1111-1111-1111-111111111111');
});
