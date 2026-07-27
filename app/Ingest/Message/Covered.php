<?php

namespace App\Ingest\Message;

use App\Ingest\Landing\Coverage;

/**
 * "For this stream, I saw everything in THIS window." The only thing that
 * makes absence mean anything at all — without a Covered message, a missing
 * record is simply a record we did not see.
 */
final readonly class Covered extends Message
{
    public function __construct(
        public string $stream,
        public Coverage $coverage,
    ) {}
}
