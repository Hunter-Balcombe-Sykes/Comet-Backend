<?php

namespace App\Exceptions;

use RuntimeException;

class NoRecipientEmailException extends RuntimeException
{
    public function __construct(string $professionalId)
    {
        parent::__construct(sprintf('Professional %s has no recipient email on file.', $professionalId));
    }
}
