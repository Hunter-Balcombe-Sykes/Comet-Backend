<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Shared base for the three "most-recent tile + highlights" platforms
 * (YouTube, Apple Music, Apple Podcasts). The saved selection is a flat array
 * of platform-specific header fields, then the canonical nested `latest` tile,
 * then the `highlights` list.
 *
 * The allowlist that matters — and the one that drifted in CONS-1 — is the
 * TOP-LEVEL key set. Subclasses own their header fields via flatFields(); the
 * base owns the shared `latest`/`highlights` tail. Nested scraper items inside
 * `latest`/`highlights` pass through verbatim (never re-allowlisted).
 *
 * `$this->resource` is the selection ARRAY (array offset access, not model).
 */
abstract class TileConnectionResource extends ApiResource
{
    /**
     * Platform-specific leading + header fields, in contract order.
     *
     * @return array<string, mixed>
     */
    abstract protected function flatFields(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->flatFields(),
            'latest' => $this->resource['latest'] ?? null,
            'highlights' => $this->resource['highlights'] ?? [],
        ];
    }
}
