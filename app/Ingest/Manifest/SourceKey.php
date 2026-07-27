<?php

namespace App\Ingest\Manifest;

/**
 * A connector's identity: `{brand}.{stream-family}` (e.g. `bandcamp`,
 * `google_business`). Distinct from a catalog surface key — one connector can
 * feed several surfaces, and one surface can be fed by more than one
 * connector over its lifetime (that is the whole point of versioned
 * capabilities).
 */
final readonly class SourceKey
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
            throw new \InvalidArgumentException("Source key must be snake_case: {$value}");
        }
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
