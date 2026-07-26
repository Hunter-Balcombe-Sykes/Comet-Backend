<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Spotify scraper — resolves any open.spotify.com URL (track, album, artist,
// playlist) to embed info via the public oEmbed endpoint. No auth needed.
//
// Normalizes the oEmbed response into V5 items + profile, extracting the embed
// iframe URL from either the iframe_url field (Spotify) or the html snippet
// (fallback). Replaces the oEmbed path of OEmbedService.
class SpotifyScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://open.spotify.com/oembed';
    protected string $authType = 'none';

    /**
     * Fetch oEmbed data for a Spotify URL.
     *
     * @return array{items: list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>, profile: array{display_name:?string, profile_pic_url:?string}}
     */
    public function fetch(string $identifier): array
    {
        $data = $this->apiGet('', ['url' => $identifier]);
        if (! $data) {
            return ['items' => [], 'profile' => []];
        }

        $embedUrl = $data['iframe_url'] ?? null;
        if (! $embedUrl && isset($data['html']) && is_string($data['html'])) {
            if (preg_match('/src="([^"]+)"/i', $data['html'], $m)) {
                $embedUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        $itemId = $data['id'] ?? md5($identifier);
        $title = $data['title'] ?? null;
        $thumbnail = $data['thumbnail_url'] ?? null;
        $authorName = $data['author_name'] ?? null;

        $values = [
            ['field_name' => 'title', 'value' => $title, 'format' => 'text'],
            ['field_name' => 'author_name', 'value' => $authorName, 'format' => 'text'],
            ['field_name' => 'author_url', 'value' => $data['author_url'] ?? null, 'format' => 'url'],
            ['field_name' => 'thumbnail_url', 'value' => $thumbnail, 'format' => 'image'],
        ];
        if ($embedUrl) {
            $values[] = ['field_name' => 'embed_url', 'value' => $embedUrl, 'format' => 'url'];
        }

        $items = [[
            'identifier' => $itemId,
            'name' => $title,
            'item_type' => match ($data['type'] ?? '') {
                'playlist' => 'track',
                default => 'track',
            },
            'values' => $values,
        ]];

        // Add embed item if we have an embed URL
        if ($embedUrl && $title) {
            $items[] = $this->buildEmbedItem(
                embedUrl: $embedUrl,
                title: $title,
                thumbnail: $thumbnail,
                provider: 'Spotify',
                originalIdentifier: $itemId,
            );
        }

        return [
            'items' => $items,
            'profile' => [
                'display_name' => $authorName,
                'profile_pic_url' => $thumbnail,
            ],
        ];
    }
}
