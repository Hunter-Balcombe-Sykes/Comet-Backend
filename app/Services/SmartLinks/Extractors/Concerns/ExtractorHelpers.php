<?php

namespace App\Services\SmartLinks\Extractors\Concerns;

/**
 * Shared pure value-normalisation helpers for SmartLink extractors;
 * consolidated from per-class copies (SLOP-1/2/3).
 */
trait ExtractorHelpers
{
    /** Cast to a trimmed non-empty string, or null. */
    private function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    /** Last dot-segment of type string, e.g. "content.music.track" → "track". */
    private function subTypeFromType(string $type): ?string
    {
        $parts = explode('.', $type);

        return end($parts) ?: null;
    }

    /** Last-resort brand name from a hostname, e.g. "www.gymshark.com" → "Gymshark". */
    private function hostBrand(string $host): string
    {
        $host = preg_replace('/^www\./', '', $host);
        $label = explode('.', $host)[0] ?? $host;

        return ucfirst($label);
    }
}
