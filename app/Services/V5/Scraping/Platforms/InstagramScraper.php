<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApifyBase;

// V5 InstagramScraper — Apify-based Instagram profile scraper.
// Dispatches the configured Apify actor with { profiles: [handle], includeRecentPosts: true },
// returns mapped items for the media pool, and extracts bio links / caption URLs for V5 routing.
class InstagramScraper extends ApifyBase
{
    protected string $actorName = '';
    protected array $actorInput = [
        'includeRecentPosts' => true,
    ];

    public function __construct(
        \App\Services\Http\SafeUrlFetcher $fetcher,
        \App\Services\V5\Scraping\Budget\ApifyBudget $apifyBudget,
    ) {
        parent::__construct($fetcher, $apifyBudget);
        $this->actorName = config('v5.instagram.actor', 'instagram-profile-scraper');
    }

    /**
     * Fetch Instagram profile data for the given handle.
     *
     * @return array{v5_items: array, bio_links: array, caption_urls: array, profile_pic_url: ?string}
     */
    public function fetch(string $handle): array
    {
        $raw = $this->runActor(['profiles' => [$handle]]);
        if ($raw === null) {
            return ['items' => [], 'bio_links' => [], 'caption_urls' => [], 'profile_pic_url' => null];
        }

        $items = $this->processItems($raw);

        // Profile-level data from the first usable item
        $profilePicUrl = null;
        $bioLinks = [];
        $captionUrls = [];

        if (! empty($items)) {
            $first = $items[0];
            $profilePicUrl = $this->extractProfilePicUrl($first);
            $bioLinks = $this->extractBioLinks($first);

            foreach ($items as $item) {
                $captionUrls = array_merge($captionUrls, $this->extractCaptionUrls($item));
            }
            $captionUrls = array_values(array_unique($captionUrls));
        }

        $this->logSuccess('instagram', 'fetch', count($items));

        return [
            'items' => $items,
            'bio_links' => $bioLinks,
            'caption_urls' => $captionUrls,
            'profile_pic_url' => $profilePicUrl,
        ];
    }

    /** Map raw actor output to V5 media-pool format. */
    protected function mapItem(array $raw): array
    {
        $id = $raw['id'] ?? $raw['pk'] ?? $raw['shortcode'] ?? null;
        if ($id === null) {
            return [];
        }

        $caption = $raw['caption'] ?? $raw['description'] ?? $raw['text'] ?? '';
        $isVideo = $this->isVideoPost($raw);

        $values = [];

        $url = $raw['url'] ?? $raw['display_url'] ?? $raw['displayUrl'] ?? '';
        if ($url !== '') {
            $values[] = ['field_name' => 'url', 'value' => $url, 'format' => 'url'];
        }

        $thumbnail = $raw['thumbnail_src'] ?? $raw['thumbnailSrc'] ?? '';
        if ($thumbnail !== '') {
            $values[] = ['field_name' => 'thumbnail', 'value' => $thumbnail, 'format' => 'url'];
        }

        if (is_string($caption) && $caption !== '') {
            $values[] = ['field_name' => 'caption', 'value' => $caption, 'format' => 'text'];
        }

        $timestamp = $raw['timestamp'] ?? $raw['taken_at'] ?? $raw['takenAt'] ?? '';
        if ($timestamp !== '') {
            $values[] = ['field_name' => 'timestamp', 'value' => (string) $timestamp, 'format' => 'text'];
        }

        $likes = $raw['likes'] ?? $raw['like_count'] ?? $raw['likeCount'] ?? null;
        if ($likes !== null) {
            $values[] = ['field_name' => 'likes', 'value' => (string) $likes, 'format' => 'text'];
        }

        $comments = $raw['comments'] ?? $raw['comment_count'] ?? $raw['commentCount'] ?? null;
        if ($comments !== null) {
            $values[] = ['field_name' => 'comments', 'value' => (string) $comments, 'format' => 'text'];
        }

        $values[] = ['field_name' => 'is_video', 'value' => $isVideo ? 'true' : 'false', 'format' => 'text'];

        return [
            'identifier' => (string) $id,
            'name' => is_string($caption) ? mb_substr($caption, 0, 200) : '',
            'item_type' => $isVideo ? 'video' : 'media',
            'values' => $values,
            'pools' => ['media'],
            'is_video' => $isVideo,
        ];
    }

    protected function isValidItem(array $item): bool
    {
        if (! parent::isValidItem($item)) {
            return false;
        }

        // Must have a usable post identifier
        if (! isset($item['id']) && ! isset($item['pk']) && ! isset($item['shortcode'])) {
            return false;
        }

        // Skip story/highlight items that lack media
        if (isset($item['media_type']) && $item['media_type'] === 0) {
            return false;
        }

        return true;
    }
}
