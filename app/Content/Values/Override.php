<?php

namespace App\Content\Values;

/**
 * A user's typed value for one column. A NULL `value` is meaningful: it is an
 * explicit clear, and must beat every source that would otherwise fill the
 * field. That distinction is why this is an object rather than a nullable
 * value.
 */
final readonly class Override
{
    public function __construct(public mixed $value) {}
}
