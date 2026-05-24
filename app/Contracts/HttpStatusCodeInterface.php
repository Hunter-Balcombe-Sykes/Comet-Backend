<?php

namespace App\Contracts;

/**
 * Domain exceptions implement this to declare their own HTTP status code and
 * response headers. The exception renderer in bootstrap/app.php handles any
 * HttpStatusCodeInterface in the else-branch, so new domain exceptions get
 * correct HTTP semantics without bespoke instanceof checks.
 */
interface HttpStatusCodeInterface
{
    /** HTTP status code this exception maps to (e.g. 429, 409, 423). */
    public function getHttpStatusCode(): int;

    /**
     * Additional HTTP headers to include in the response (e.g. ['Retry-After' => 30]).
     *
     * @return array<string, string|int>
     */
    public function getHttpHeaders(): array;
}
