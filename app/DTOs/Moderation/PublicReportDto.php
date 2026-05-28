<?php

namespace App\DTOs\Moderation;

/**
 * Immutable input DTO for a public content report.
 * Built from the validated PublicReportRequest before reaching ContentReportService.
 */
final class PublicReportDto
{
    public function __construct(
        public readonly string $targetType,
        public readonly string $targetHandle,
        public readonly string $reasonCode,
        public readonly ?string $details,
        public readonly ?string $reporterEmail,
        public readonly string $reporterIp,
    ) {}
}
