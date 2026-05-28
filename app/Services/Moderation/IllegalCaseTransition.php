<?php

namespace App\Services\Moderation;

use RuntimeException;

class IllegalCaseTransition extends RuntimeException
{
    public static function forStatuses(string $from, string $to): self
    {
        return new self("Illegal case transition: {$from} -> {$to}");
    }
}
