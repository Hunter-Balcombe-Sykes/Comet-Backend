<?php

namespace App\Ingest\Message;

/**
 * Something the connector wants recorded but which is not data: a paywalled
 * section, a truncated list, a vendor warning. Surfaces in the run detail;
 * never fails a run on its own.
 */
final readonly class Note extends Message
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
