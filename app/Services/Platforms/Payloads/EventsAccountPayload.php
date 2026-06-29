<?php

namespace App\Services\Platforms\Payloads;

// Typed READ boundary for an events ACCOUNT row (eventbrite / humanitix organiser
// pages). Stored shape: {url, organiser, next?, upcoming[], hiddenEventIds[]}.
// VERBATIM-preserving (raw array + typed accessors) because `upcoming` is a list of
// scraped event objects spread into the dashboard + public wire and passed through
// the public allowlist unchanged — a normalizing DTO would risk injecting null keys
// into the stored blob on write-back. The WRITE side is the static EventsPayload
// builder (accountPayload/withIds), unchanged; this is the read side.
final readonly class EventsAccountPayload
{
    /** @param array<string,mixed> $raw the stored account blob, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function url(): ?string
    {
        return is_string($this->raw['url'] ?? null) ? $this->raw['url'] : null;
    }

    public function organiser(): ?string
    {
        return is_string($this->raw['organiser'] ?? null) ? $this->raw['organiser'] : null;
    }

    /** @return list<mixed> the upcoming-events list, verbatim, or [] */
    public function upcoming(): array
    {
        return is_array($this->raw['upcoming'] ?? null) ? $this->raw['upcoming'] : [];
    }

    /** @return list<mixed> the curated hidden-event id list, verbatim, or [] */
    public function hiddenEventIds(): array
    {
        return is_array($this->raw['hiddenEventIds'] ?? null) ? $this->raw['hiddenEventIds'] : [];
    }

    /** @return array<string,mixed> the stored blob, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }
}
