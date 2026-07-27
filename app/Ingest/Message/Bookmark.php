<?php

namespace App\Ingest\Message;

/** Resume point for an incremental stream, stored on ingest.streams.cursor. */
final readonly class Bookmark extends Message
{
    /** @param array<string, mixed> $cursor */
    public function __construct(
        public string $stream,
        public array $cursor,
    ) {}
}
