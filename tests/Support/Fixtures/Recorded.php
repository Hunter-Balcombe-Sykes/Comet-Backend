<?php
// tests/Support/Fixtures/Recorded.php

namespace Tests\Support\Fixtures;

use RuntimeException;

/**
 * Loader for the recorded-reality corpus under tests/fixtures/recorded/.
 * Fixtures are REAL upstream responses captured by `fixtures:capture` (or
 * imported by hand and registered in MANIFEST.json) — never hand-typed
 * payloads. See docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md §5 A1.
 */
final class Recorded
{
    public static function root(): string
    {
        return dirname(__DIR__, 2).'/fixtures/recorded';
    }

    public static function path(string $rel): string
    {
        return self::root().'/'.ltrim($rel, '/');
    }

    public static function raw(string $rel): string
    {
        $path = self::path($rel);
        if (! is_file($path)) {
            throw new RuntimeException("Recorded fixture missing: {$rel}");
        }

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    public static function json(string $rel): array
    {
        $decoded = json_decode(self::raw($rel), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Recorded fixture is not a JSON object/array: {$rel}");
        }

        return $decoded;
    }

    public static function html(string $rel): string
    {
        return self::raw($rel);
    }

    /** @param array<string, mixed> $payload */
    public static function mutate(array $payload): FixtureMutator
    {
        return new FixtureMutator($payload);
    }
}
