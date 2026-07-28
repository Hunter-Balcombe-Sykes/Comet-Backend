<?php

namespace App\Ingest\Projection;

/**
 * A read-instrumented view over a landed doc. Projectors read fields THROUGH
 * this rather than touching the array, which buys two things no field-map DSL
 * would:
 *
 *   1. We learn which vendor paths are actually load-bearing, so
 *      `ingest:volatility-audit` can fail CI when a path is declared volatile
 *      AND read by a projector — the case where change detection silently
 *      ignores something that matters.
 *   2. Fixture coverage becomes measurable: a fixture that never populates a
 *      path a projector reads is an incomplete fixture, and we can say so.
 *
 * Plain PHP with instrumented reads, not a mapping language (plan §4).
 */
class RecordView
{
    /** @var list<string> */
    private array $reads = [];

    /** @param array<string, mixed> $doc */
    public function __construct(private readonly array $doc, private readonly ?string $key = null) {}

    /**
     * The vendor's stable record key (the Record's own key, not a doc path).
     * Some connectors put the identity ONLY here — Spotify's oEmbed doc, for
     * example, carries a title but not the entity path it describes.
     */
    public function key(): ?string
    {
        return $this->key;
    }

    public function string(string $path, ?string $default = null): ?string
    {
        $value = $this->read($path);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function int(string $path, ?int $default = null): ?int
    {
        $value = $this->read($path);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $path, ?float $default = null): ?float
    {
        $value = $this->read($path);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $path, ?bool $default = null): ?bool
    {
        $value = $this->read($path);

        return is_bool($value) ? $value : $default;
    }

    /** @return list<mixed> */
    public function list(string $path): array
    {
        $value = $this->read($path);

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, mixed> */
    public function map(string $path): array
    {
        $value = $this->read($path);

        return is_array($value) ? $value : [];
    }

    public function has(string $path): bool
    {
        return $this->read($path) !== null;
    }

    /** Paths this projector actually consulted. */
    public function reads(): array
    {
        return array_values(array_unique($this->reads));
    }

    private function read(string $path): mixed
    {
        $this->reads[] = $path;

        $cursor = $this->doc;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
