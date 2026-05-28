<?php

namespace App\DTOs\Moderation;

/**
 * Input for ModerationDecisionService::decide().
 * secondStaffApprovalId is required for override_csam_auto_action decisions
 * and must differ from the deciding staff's id (enforced by the service).
 */
final class DecisionDto
{
    public function __construct(
        public readonly string $decisionType,
        public readonly string $reason,
        public readonly ?string $secondStaffApprovalId,
    ) {}
}
