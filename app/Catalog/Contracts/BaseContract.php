<?php

namespace App\Catalog\Contracts;

use App\Catalog\Surface;

// Shared assertion helpers for SurfaceContract implementations, so every
// concrete contract throws the same ContractViolation shape instead of
// hand-rolling its own message.
abstract class BaseContract
{
    protected static function assertNotEmpty(mixed $value, Surface $s, string $rule): void
    {
        if (empty($value)) {
            throw ContractViolation::for($s, $rule);
        }
    }

    protected static function assertBetween(int $v, int $min, int $max, Surface $s, string $rule): void
    {
        if ($v < $min || $v > $max) {
            throw ContractViolation::for($s, $rule);
        }
    }

    /** Null passes — absence is allowed unless the caller also calls assertNotEmpty(). */
    protected static function assertCapabilityPrefix(?string $name, string $prefix, Surface $s, string $rule): void
    {
        if ($name === null) {
            return;
        }

        if (! str_starts_with($name, $prefix)) {
            throw ContractViolation::for($s, $rule);
        }
    }

    protected static function assertHasDetectors(Surface $s): void
    {
        if ($s->detectors === []) {
            throw ContractViolation::for($s, 'must declare at least one detector');
        }
    }
}
