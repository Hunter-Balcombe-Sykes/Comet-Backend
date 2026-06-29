<?php

namespace App\Services\Platforms\Payloads;

// Typed READ boundary for the LinkCardScraper-family "branded card" payloads:
//   • custom links             {kind:'link', url, name, description, favicon, logo}
//   • booking/reservations     {provider:'custom', source, url, name, favicon, logo}
//     custom fallback
//   • online-ordering entries  {id, provider, source, url, name, favicon, logo,
//                               data:{pickupUrl?, deliveryUrl?, type?, time?, fees?, …}}
// VERBATIM-preserving (raw array + typed accessors): online-ordering writes the
// consolidated payload back on merge-on-add, and the booking/reservations/custom
// cards render publicly — so the DTO must inject no canonical-null keys. `data()`
// returns the nested ordering sub-map verbatim (same passthrough as
// FreshaSelection::services()). The WRITE construction (snapshotOrMinimal spreads,
// mergeStorePayload) stays literal; this is the read side.
final readonly class CardPayload
{
    /** @param array<string,mixed> $raw the stored card payload, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function url(): ?string
    {
        return $this->str('url');
    }

    public function name(): ?string
    {
        return $this->str('name');
    }

    public function description(): ?string
    {
        return $this->str('description');
    }

    public function favicon(): ?string
    {
        return $this->str('favicon');
    }

    public function logo(): ?string
    {
        return $this->str('logo');
    }

    public function provider(): ?string
    {
        return $this->str('provider');
    }

    public function source(): ?string
    {
        return $this->str('source');
    }

    public function kind(): ?string
    {
        return $this->str('kind');
    }

    public function id(): ?string
    {
        return $this->str('id');
    }

    /** @return array<string,mixed> the nested ordering data map, verbatim, or [] */
    public function data(): array
    {
        return is_array($this->raw['data'] ?? null) ? $this->raw['data'] : [];
    }

    /** @return array<string,mixed> the stored card, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function str(string $key): ?string
    {
        return is_string($this->raw[$key] ?? null) ? $this->raw[$key] : null;
    }
}
