<?php

namespace App\Services\V5\Scraping;

use App\Services\V5\Scraping\Platforms\AppleMusicScraper;
use App\Services\V5\Scraping\Platforms\ApplePodcastsScraper;
use App\Services\V5\Scraping\Platforms\BandcampScraper;
use App\Services\V5\Scraping\Platforms\DoorDashMenuScraper;
use App\Services\V5\Scraping\Platforms\EventbriteScraper;
use App\Services\V5\Scraping\Platforms\FreshaScraper;
use App\Services\V5\Scraping\Platforms\GoogleBusinessScraper;
use App\Services\V5\Scraping\Platforms\HumanitixScraper;
use App\Services\V5\Scraping\Platforms\InstagramScraper;
use App\Services\V5\Scraping\Platforms\OpenTableScraper;
use App\Services\V5\Scraping\Platforms\PinterestScraper;
use App\Services\V5\Scraping\Platforms\ShopifyScraper;
use App\Services\V5\Scraping\Platforms\SkoolScraper;
use App\Services\V5\Scraping\Platforms\SoundCloudScraper;
use App\Services\V5\Scraping\Platforms\SpotifyScraper;
use App\Services\V5\Scraping\Platforms\SquareMenuScraper;
use App\Services\V5\Scraping\Platforms\SquareScraper;
use App\Services\V5\Scraping\Platforms\StravaClubScraper;
use App\Services\V5\Scraping\Platforms\TwitchScraper;
use App\Services\V5\Scraping\Platforms\UberEatsMenuScraper;
use App\Services\V5\Scraping\Platforms\VimeoScraper;
use App\Services\V5\Scraping\Platforms\WooCommerceScraper;
use App\Services\V5\Scraping\Platforms\YoutubeMusicScraper;
use App\Services\V5\Scraping\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Log;

// V5 ScraperResolver — maps platform slug → scraper instance → fetch → items.
class ScraperResolver
{
    /** Platform slug (hyphenated) → scraper class. */
    private const SCRAPER_MAP = [
        'youtube' => YoutubeScraper::class,
        'youtube-music' => YoutubeMusicScraper::class,
        'vimeo' => VimeoScraper::class,
        'twitch' => TwitchScraper::class,
        'pinterest' => PinterestScraper::class,
        'bandcamp' => BandcampScraper::class,
        'spotify' => SpotifyScraper::class,
        'apple-music' => AppleMusicScraper::class,
        'apple-podcasts' => ApplePodcastsScraper::class,
        'apple-podcast' => ApplePodcastsScraper::class,
        'soundcloud' => SoundCloudScraper::class,
        'eventbrite' => EventbriteScraper::class,
        'humanitix' => HumanitixScraper::class,
        'skool' => SkoolScraper::class,
        'strava' => StravaClubScraper::class,
        'fresha' => FreshaScraper::class,
        'square' => SquareScraper::class,
        'opentable' => OpenTableScraper::class,
        'shopify' => ShopifyScraper::class,
        'woocommerce' => WooCommerceScraper::class,
        'instagram' => InstagramScraper::class,
        'google-business' => GoogleBusinessScraper::class,
        'uber-eats' => UberEatsMenuScraper::class,
        'doordash' => DoorDashMenuScraper::class,
        'square-online' => SquareMenuScraper::class,
    ];

    /** Resolve a scraper by platform slug. Returns null if unknown. */
    public function resolve(string $slug): ?BaseScraper
    {
        $class = self::SCRAPER_MAP[$slug] ?? null;
        return $class ? app($class) : null;
    }

    /**
     * Scrape a platform and return items in V5 format.
     *
     * @param string $slug Platform slug (e.g. 'spotify', 'youtube')
     * @param string $identifier The URL or handle to scrape
     * @return array{items: array, profile: array}|null
     */
    public function scrape(string $slug, string $identifier): ?array
    {
        $scraper = $this->resolve($slug);
        if (! $scraper) {
            Log::warning('v5.scraper.unknown', ['slug' => $slug]);
            return null;
        }

        try {
            $result = $scraper->fetch($identifier);
            Log::info('v5.scraper.ok', [
                'slug' => $slug,
                'items' => count($result['items'] ?? []),
            ]);
            return $result;
        } catch (\Throwable $e) {
            Log::error('v5.scraper.failed', [
                'slug' => $slug,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** All registered slug → class pairs. */
    public function all(): array { return self::SCRAPER_MAP; }

    /** Check if a slug is registered. */
    public function has(string $slug): bool { return isset(self::SCRAPER_MAP[$slug]); }
}
