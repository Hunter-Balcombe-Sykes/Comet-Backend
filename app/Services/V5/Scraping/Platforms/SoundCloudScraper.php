<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 SoundCloud scraper — resolves any soundcloud.com URL (track, playlist,
// artist) to embed info via the public oEmbed endpoint. No auth needed.
//
// Extracts the embed iframe URL from the oEmbed html snippet (SoundCloud does
// not return an iframe_url field — the iframe is embedded in the html string).
// Replaces the oEmbed path of OEmbedService.
class SoundCloudScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://soundcloud.com/oembed';
    protected string $authType = 'none';

    /**
     * Fetch oEmbed data for a SoundCloud URL.
     *
     * @return list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>
     */
    public function fetch(string $identifier): array
    {
        // SoundCloud oEmbed returns JSON by default — do NOT add format=json
        // as it causes a 404 (the endpoint is strict about its parameters).
        $data = $this->apiGet('', ['url' => $identifier]);
        if (! $data) {
            return [];
        }

        $embedUrl = null;
        if (isset($data['html']) && is_string($data['html'])) {
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
            'item_type' => 'track',
            'values' => $values,
        ]];
    }
}
