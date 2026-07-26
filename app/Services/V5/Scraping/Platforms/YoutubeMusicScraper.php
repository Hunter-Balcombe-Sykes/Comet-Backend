<?php

namespace App\Services\V5\Scraping\Platforms;

// V5 YouTube Music scraper — shares YouTube's channel-page + RSS feed logic
// but maps items to music.youtube.com links with item_type='track' and an
// embed URL for inline playback. Replaces the old YoutubeMusicItems mapper.
//
// The uploads feed is the same RSS feed as YouTube; the only difference is
// the item format (music.youtube.com links, embed URLs, track type).
class YoutubeMusicScraper extends YoutubeScraper
{
    /**
     * Override to build items with music.youtube.com links and track type.
     */
    protected function buildItem(array $item, string $thumbnail): array
    {
        $videoId = $item['videoId'];

        return [
            'identifier' => "https://music.youtube.com/watch?v={$videoId}",
            'name' => $item['name'],
            'item_type' => 'track',
            'values' => [
                ['field_name' => 'title', 'value' => $item['name'], 'format' => 'text'],
                ['field_name' => 'url', 'value' => "https://music.youtube.com/watch?v={$videoId}", 'format' => 'url'],
                ['field_name' => 'thumbnail', 'value' => $thumbnail, 'format' => 'image'],
                ['field_name' => 'embed_url', 'value' => "https://www.youtube.com/embed/{$videoId}", 'format' => 'url'],
                ['field_name' => 'description', 'value' => $item['description'] ?? '', 'format' => 'text'],
                ['field_name' => 'upload_date', 'value' => $item['date'] ?? '', 'format' => 'date'],
            ],
        ];
    }
}
