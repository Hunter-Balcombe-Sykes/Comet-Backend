<?php

namespace App\Catalog\Contracts;

use App\Catalog\Surface;

// A pluggable invariant a surface opts into via SurfaceBuilder::contract(...).
// Each concrete contract's assert() runs against the built Surface and throws
// ContractViolation on the first broken rule.
interface SurfaceContract
{
    /** @throws ContractViolation */
    public static function assert(Surface $surface): void;
}
