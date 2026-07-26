<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 SoundCloud scraper — resolves any soundcloud.com URL (track, playlist,
// artist) to embed info via the public oEmbed endpoint. No auth needed.
//
// Normalizes the oEmbed response into V5 items + profile, extracting the embed
// iframe URL from the oEmbed html snippet (SoundCloud does not return an
// iframe_url field — the iframe is embedded in the html string).
// Replaces the oEmbed path of OEmbedService.
class SoundCloudScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://soundcloud.com/oembed';
    protected string $authType = 'none';

    /**
     * Fetch oEmbed data for a SoundCloud URL.
     *
     * @return array{items: list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>, profile: array{display_name:?string, profile_pic_url:?string}}
     */
    public function fetch(string $identifier): array
    {
        // SoundCloud oEmbed with format=json returns JSON; without the format
        // parameter the endpoint returns XML, which apiGet cannot parse.
        $data = $this->apiGet('', ['format' => 'json', 'url' => $identifier]);
        if (! $data) {
            return ['items' => [], 'profile' => []];
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

        return [
            'items' => [[
                'identifier' => $data['id'] ?? md5($identifier),
                'name' => $data['title'] ?? null,
                'item_type' => 'track',
                'values' => $values,
            ]],
            'profile' => [
                'display_name' => $data['author_name'] ?? null,
                'profile_pic_url' => $data['thumbnail_url'] ?? null,
            ],
        ];
    }
}
