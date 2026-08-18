<?php

// tests/Support/Fixtures/FixtureMutator.php

namespace Tests\Support\Fixtures;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Derive edge cases from ONE recorded fixture so they stay shaped like reality:
 * a dropped field, a null, a zero, a key-case change (the actor swap that made
 * every IG site nameless — SIGNUP-2). Immutable: each call returns a new instance,
 * and the payload it holds is never mutated in place — every builder method copies
 * the array first (PHP arrays copy on write, so this is a cheap value-semantics
 * copy, not a deep clone loop) so two chains built from the same Recorded::json()
 * payload can never contaminate each other.
 */
final class FixtureMutator
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    public function without(string ...$dotKeys): self
    {
        $p = $this->payload;
        foreach ($dotKeys as $k) {
            Arr::forget($p, $k);
        }

        return new self($p);
    }

    public function nullify(string ...$dotKeys): self
    {
        $p = $this->payload;
        foreach ($dotKeys as $k) {
            Arr::set($p, $k, null);
        }

        return new self($p);
    }

    public function set(string $dotKey, mixed $value): self
    {
        $p = $this->payload;
        Arr::set($p, $dotKey, $value);

        return new self($p);
    }

    public function emptyArray(string ...$dotKeys): self
    {
        $p = $this->payload;
        foreach ($dotKeys as $k) {
            Arr::set($p, $k, []);
        }

        return new self($p);
    }

    // Recursive: real actor payloads nest (e.g. latestPosts[].postId), and the
    // camelCase→snake_case actor swap this reproduces (SIGNUP-2) can land at any
    // depth, not just the top level.
    public function snakeCaseKeys(): self
    {
        return new self(self::rekey($this->payload, fn (string $k) => Str::snake($k)));
    }

    public function camelCaseKeys(): self
    {
        return new self(self::rekey($this->payload, fn (string $k) => Str::camel($k)));
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return $this->payload;
    }

    /** @param array<mixed> $arr */
    private static function rekey(array $arr, callable $fn): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $nk = is_string($k) ? $fn($k) : $k;
            $out[$nk] = is_array($v) ? self::rekey($v, $fn) : $v;
        }

        return $out;
    }
}
