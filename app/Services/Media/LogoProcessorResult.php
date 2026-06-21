<?php

namespace App\Services\Media;

/**
 * Typed result of a POST /process call to the self-hosted logo processor.
 *
 * @see LogoProcessorClient
 */
final class LogoProcessorResult
{
    /**
     * @param  string  $pngTransparent  Decoded PNG bytes (RGBA) — the background-removed logo.
     * @param  string|null  $svg  SVG markup when the mark was a clean vectorization candidate, else null.
     * @param  array<string, mixed>  $meta  removed / vectorized / reason / width / height / colors / coverage.
     */
    public function __construct(
        public readonly string $pngTransparent,
        public readonly ?string $svg,
        public readonly array $meta,
    ) {}

    /** True when the processor produced a usable SVG (passed its gate + validation). */
    public function vectorized(): bool
    {
        return is_string($this->svg) && trim($this->svg) !== '';
    }
}
