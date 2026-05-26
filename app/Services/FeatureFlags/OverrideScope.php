<?php

namespace App\Services\FeatureFlags;

use InvalidArgumentException;

final class OverrideScope
{
    private function __construct(
        public readonly string $userId,
    ) {
        if ($userId === '') {
            throw new InvalidArgumentException('OverrideScope userId must not be empty string');
        }
    }

    public static function forUser(string $userId): self
    {
        return new self(userId: $userId);
    }
}
