<?php

namespace App\DTOs\Moderation;

/**
 * Immutable input DTO for a staff triage action on a moderation case.
 * Both fields are optional — null priority preserves the existing value.
 */
final class TriageDto
{
    public function __construct(
        public readonly ?int $priority,
        public readonly ?string $notes,
    ) {}
}
