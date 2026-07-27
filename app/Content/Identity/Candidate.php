<?php

namespace App\Content\Identity;

/** A possible duplicate the resolver refuses to decide alone. */
final readonly class Candidate
{
    public function __construct(
        public string $left,
        public string $right,
        public string $evidence,
    ) {}
}
