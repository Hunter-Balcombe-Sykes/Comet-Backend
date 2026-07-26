<?php

namespace App\Jobs\V5;

use App\Models\V5\UserPlatform;
use App\Services\V5\ItemService;
use App\Services\V5\Scraping\ScraperResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

// V5 RefreshPlatformJob — fetches fresh data from a platform and ingests items into content pools.
class RefreshPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        private readonly string $userPlatformId,
    ) {}

    public function handle(ScraperResolver $resolver, ItemService $items): void
    {
        $up = UserPlatform::with('platformDefinition.categories')->find($this->userPlatformId);
        if (! $up || ! $up->is_enabled) {
            Log::warning('v5.refresh.skipped', ['user_platform_id' => $this->userPlatformId, 'reason' => 'not found or disabled']);
            return;
        }

        $slug = $up->platformDefinition->slug ?? '';
        if (! $slug) {
            Log::warning('v5.refresh.no_slug', ['user_platform_id' => $this->userPlatformId]);
            return;
        }

        // Run the scraper
        try {
            $result = $resolver->scrape($slug, $up->identifier_value ?? '');
        } catch (\Throwable $e) {
            Log::error('v5.refresh.scrape_failed', [
                'user_platform_id' => $this->userPlatformId,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (! $result || empty($result['items'] ?? [])) {
            Log::info('v5.refresh.no_items', ['user_platform_id' => $this->userPlatformId, 'slug' => $slug]);
            return;
        }

        // Determine which pool(s) these items go into
        $poolName = $this->poolForSlug($slug, $result['items'][0]['item_type'] ?? 'link');

        // Ingest items
        $ingested = $items->ingest(
            $up,
            $poolName,
            $result['items'],
            $result['profile'] ?? []
        );

        Log::info('v5.refresh.done', [
            'user_platform_id' => $this->userPlatformId,
            'slug' => $slug,
            'pool' => $poolName,
            'created' => $ingested['created'],
            'updated' => $ingested['updated'],
        ]);
    }

    private function poolForSlug(string $slug, string $itemType): string
    {
        return match (true) {
            in_array($slug, ['youtube', 'vimeo', 'twitch']) => 'watch',
            in_array($slug, ['spotify', 'apple-music', 'soundcloud', 'bandcamp', 'youtube-music', 'tidal', 'mixcloud']) => 'music',
            in_array($slug, ['apple-podcast', 'apple-podcasts']) => 'podcasts',
            in_array($slug, ['eventbrite', 'humanitix', 'ticketmaster', 'ticketek', 'oztix']) => 'events',
            in_array($slug, ['fresha', 'square']) => 'services',
            in_array($slug, ['uber-eats', 'doordash', 'square-online', 'square-ordering']) => 'menu',
            in_array($slug, ['shopify', 'woocommerce', 'gumroad']) => 'products',
            in_array($slug, ['instagram', 'pinterest']) => 'media',
            in_array($slug, ['google-business']) => 'media',
            in_array($slug, ['opentable']) => 'events',
            $itemType === 'video' || $itemType === 'embed' => 'watch',
            $itemType === 'track' => 'music',
            $itemType === 'podcast episode' => 'podcasts',
            $itemType === 'event' => 'events',
            $itemType === 'service' => 'services',
            $itemType === 'menu item' => 'menu',
            $itemType === 'product' => 'products',
            $itemType === 'media' => 'media',
            default => 'links',
        };
    }
}
