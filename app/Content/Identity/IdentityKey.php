<?php

namespace App\Content\Identity;

/** One piece of identity evidence attached to a source item. */
final readonly class IdentityKey
{
    public function __construct(
        public KeyClass $class,
        public string $value,
    ) {}
}
