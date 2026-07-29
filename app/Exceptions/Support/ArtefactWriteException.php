<?php

namespace App\Exceptions\Support;

use RuntimeException;

// Thrown by AtomicArtefactWriter when a generated artefact (compiled catalog,
// routing corpus) cannot be written durably — mkdir/file_put_contents/rename
// all fail loudly instead of the caller silently discarding the return value
// (OBS-1, OBS-8).
class ArtefactWriteException extends RuntimeException
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct("Failed to write artefact to {$path}: {$reason}");
    }
}
