<?php

namespace App\Exceptions\Streaming;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;

/** Thrown by TwitchApiClient when Twitch returns HTTP 429. */
class TwitchRateLimitException extends RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct('Twitch API rate limit exceeded.');
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
