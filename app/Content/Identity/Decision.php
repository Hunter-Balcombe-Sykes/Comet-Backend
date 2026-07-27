<?php

namespace App\Content\Identity;

/** A human's ruling. Beats every key, in both directions (C8). */
final readonly class Decision
{
    public function __construct(
        public string $left,
        public string $right,
        public string $verdict, // 'same' | 'different'
    ) {}
}
