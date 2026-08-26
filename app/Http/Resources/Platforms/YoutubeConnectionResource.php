<?php

namespace App\Http\Resources\Platforms;

/**
 * YouTube channel selection: flat header fields + the latest-video tile and the
 * Shape/tail defined by TileConnectionResource.
 */
class YoutubeConnectionResource extends TileConnectionResource
{
    /**
     * @return array<string, mixed>
     */
    protected function flatFields(): array
    {
        return [
            'handle' => $this->resource['handle'] ?? null,
            // The CHANNEL's own avatar (stored by YoutubeConnect since day
            // one, dropped here until plan 04 step A, 2026-08-27) — the
            // connect summary shows the account's face, not the brand glyph.
            'avatarUrl' => $this->resource['avatarUrl'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'description' => $this->resource['description'] ?? null,
            'link' => $this->resource['link'] ?? null,
            'thumbnail' => $this->resource['thumbnail'] ?? null,
        ];
    }
}
