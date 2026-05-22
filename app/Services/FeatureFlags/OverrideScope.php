<?php

namespace App\Services\FeatureFlags;

use InvalidArgumentException;

final class OverrideScope
{
    private function __construct(
        public readonly string $professionalId,
    ) {
        if ($professionalId === '') {
            throw new InvalidArgumentException('OverrideScope professionalId must not be empty string');
        }
    }

    public static function forProfessional(string $professionalId): self
    {
        return new self(professionalId: $professionalId);
    }
}
