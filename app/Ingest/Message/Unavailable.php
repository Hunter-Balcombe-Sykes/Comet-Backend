<?php

namespace App\Ingest\Message;

/**
 * The source could not be read this time. Distinct from "the source is empty"
 * — which a connector must never claim by emitting nothing (C5: a zero-item
 * fetch is unavailable, never emptiness).
 */
final readonly class Unavailable extends Message
{
    public function __construct(
        public string $reason,
        public ?int $status = null,
    ) {}
}
