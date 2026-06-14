<?php

namespace App\Support\Concerns;

// Shared helper for trimming and coercing empty/whitespace strings to null so
// NULL and "" mean the same thing at rest. Used by controllers and services
// that normalise optional text fields (caption, alt_text, title, etc.).
trait NormalisesOptionalString
{
    protected function normaliseOptionalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
