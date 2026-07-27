<?php

namespace App\Ingest\Message;

/** One vendor record, verbatim. `key` must be stable across runs. */
final readonly class Record extends Message
{
    /** @param array<string, mixed> $doc */
    public function __construct(
        public string $stream,
        public string $key,
        public array $doc,
    ) {}
}
