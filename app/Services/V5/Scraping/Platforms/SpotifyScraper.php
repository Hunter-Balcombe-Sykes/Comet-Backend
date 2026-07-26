<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Spotify scraper — resolves any open.spotify.com URL (track, album, artist,
// playlist) to embed info via the public oEmbed endpoint. No auth needed.
//
// Normalizes the oEmbed response into V5 items, extracting the embed iframe URL
// from either the iframe_url field (Spotify) or the html snippet (fallback).
// Replaces the oEmbed path of OEmbedService.
class SpotifyScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://open.spotify.com/oembed';
    protected string $authType = 'none';

    /**
     * Fetch oEmbed data for a Spotify URL.
     *
     * @return list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>
     */
    public function fetch(string $identifier): array
    {
        $data = $this->apiGet('', ['url' => $identifier]);
        if (! $data) {
            return [];
        }

        $embedUrl = $data['iframe_url'] ?? null;
        if (! $embedUrl && isset($data['html']) && is_string($data['html'])) {
            if (preg_match('/src="([^"]+)"/i', $data['html'], $m)) {
                $embedUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        $values = [
            ['field_name' => 'title', 'value' => $data['title'] ?? null, 'format' => 'text'],
            ['field_name' => 'author_name', 'value' => $data['author_name'] ?? null, 'format' => 'text'],
            ['field_name' => 'author_url', 'value' => $data['author_url'] ?? null, 'format' => 'url'],
            ['field_name' => 'thumbnail_url', 'value' => $data['thumbnail_url'] ?? null, 'format' => 'image'],
        ];
        if ($embedUrl) {
            $values[] = ['field_name' => 'embed_url', 'value' => $embedUrl, 'format' => 'url'];
        }

        return [[
            'identifier' => $data['id'] ?? md5($identifier),
            'name' => $data['title'] ?? null,
            'item_type' => match ($data['type'] ?? '') {
                'playlist' => 'track',
                default => 'track',
            },
            'values' => $values,
        ]];
    }
}
