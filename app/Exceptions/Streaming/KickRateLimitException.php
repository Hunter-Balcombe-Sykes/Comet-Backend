<?php

namespace App\Exceptions\Streaming;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;

/** Thrown by KickApiClient when Kick returns HTTP 429. */
class KickRateLimitException extends RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct('Kick API rate limit exceeded.');
    }

    public function getHttpStatusCode(): int
    {
        return 429;
    }

    public function getHttpHeaders(): array
    {
        return $this->retryAfter !== null
            ? ['Retry-After' => $this->retryAfter]
            : [];
    }
}
