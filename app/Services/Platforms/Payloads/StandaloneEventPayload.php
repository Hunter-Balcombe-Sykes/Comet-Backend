<?php

namespace App\Services\Platforms\Payloads;

// Typed READ boundary for a STANDALONE event row ('event-<id>', under eventbrite /
// humanitix / events-custom). Stored shape: {kind:'event', id, name, …event fields}.
// VERBATIM-preserving; event() returns the payload MINUS the internal `kind`
// discriminator (which the readers strip before spreading the event into the wire).
final readonly class StandaloneEventPayload
{
    /** @param array<string,mixed> $raw the stored standalone-event blob, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function id(): ?string
    {
        return isset($this->raw['id']) && is_string($this->raw['id']) ? $this->raw['id'] : null;
    }

    /** @return array<string,mixed> the event object, internal `kind` removed */
    public function event(): array
    {
        $event = $this->raw;
        unset($event['kind']);

        return $event;
    }

    /** @return array<string,mixed> the stored blob, byte-for-byte (kind retained) */
    public function toArray(): array
    {
        return $this->raw;
    }
}
