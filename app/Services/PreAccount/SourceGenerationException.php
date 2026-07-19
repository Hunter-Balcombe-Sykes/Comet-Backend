<?php

namespace App\Services\PreAccount;

use App\Models\Core\User\PreAccountBuild;
use RuntimeException;

// Thrown by SiteSourceGenerator::generate() when a pre-account build can't be
// produced. $failureCode is one of PreAccountBuild::FAILURE_* and is written
// straight onto the build row by the caller (GeneratePreAccountSiteJob).
class SourceGenerationException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }

    public static function sourceNotFound(): self
    {
        return new self(PreAccountBuild::FAILURE_SOURCE_NOT_FOUND, 'Source profile not found.');
    }

    public static function scrapeFailed(string $detail = ''): self
    {
        return new self(PreAccountBuild::FAILURE_SCRAPE_FAILED, 'Source scrape failed. '.$detail);
    }
}
