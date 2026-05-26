<?php

namespace App\Exceptions\Gdpr;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;

class DataExportInProgressException extends RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(public string $existingExportId)
    {
        parent::__construct('A data export is already in progress for this professional.');
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getHttpHeaders(): array
    {
        return [];
    }
}
