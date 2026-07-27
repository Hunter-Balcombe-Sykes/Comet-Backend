<?php

namespace App\Catalog\Contracts;

use App\Catalog\Surface;
use RuntimeException;

// Thrown by a SurfaceContract::assert() implementation when a surface breaks
// its declared invariant — one message shape for every contract failure.
final class ContractViolation extends RuntimeException
{
    public static function for(Surface $surface, string $rule): self
    {
        return new self("catalog contract violation [{$surface->key}]: {$rule}");
    }
}
