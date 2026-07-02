<?php

namespace App\Services\Design\Scan;

use RuntimeException;

/**
 * Brand-scan transport failure, classified for the stored failure taxonomy:
 * disabled | fetch | timeout | quota | error. The analyzer stores the kind on
 * the ok:false analysis document so reconciliation never retries in a loop and
 * "why did this site not style" is answerable later.
 */
class BrandScanException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
