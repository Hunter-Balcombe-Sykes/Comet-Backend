<?php

namespace App\Services\V5\Scraping;

use App\Services\V5\Scraping\Platforms\AppleMusicScraper;
use App\Services\V5\Scraping\Platforms\ApplePodcastsScraper;
use App\Services\V5\Scraping\Platforms\FreshaScraper;
use App\Services\V5\Scraping\Platforms\GoogleBusinessScraper;
use App\Services\V5\Scraping\Platforms\OpenTableScraper;
use App\Services\V5\Scraping\Platforms\ShopifyScraper;
use App\Services\V5\Scraping\Platforms\SoundCloudScraper;
use App\Services\V5\Scraping\Platforms\SpotifyScraper;
use App\Services\V5\Scraping\Platforms\SquareScraper;
use App\Services\V5\Scraping\Platforms\VimeoScraper;
use App\Services\V5\Scraping\Platforms\WooCommerceScraper;

// V5 ScraperResolver maps a platform slug or URL to the correct scraper
// instance. Uses Laravel's container for DI resolution so scrapers receive
// their dependencies (SafeUrlFetcher, FetchBudget, etc.) automatically.
//
// Usage:
//   $resolver = app(ScraperResolver::class);
//   $scraper = $resolver->resolve('spotify');
//   $items   = $scraper->fetch('https://open.spotify.com/track/...');
//
// Or resolve by URL (auto-detect platform from hostname):
//   $scraper = $resolver->resolveForUrl('https://vimeo.com/channels/staffpicks');
class ScraperResolver
{
    /** Platform slug → scraper class. */
    private const SCRAPER_MAP = [
        'apple_music' => AppleMusicScraper::class,
        'apple_podcasts' => ApplePodcastsScraper::class,
        'fresha' => FreshaScraper::class,
        'google_business' => GoogleBusinessScraper::class,
        'opentable' => OpenTableScraper::class,
        'shopify' => ShopifyScraper::class,
        'soundcloud' => SoundCloudScraper::class,
        'spotify' => SpotifyScraper::class,
        'square' => SquareScraper::class,
        'vimeo' => VimeoScraper::class,
        'woocommerce' => WooCommerceScraper::class,
    ];

    /** URL host fragments → platform slug. Listed longest-first for specificity. */
    private const HOST_MAP = [
        'open.spotify.com' => 'spotify',
        'soundcloud.com' => 'soundcloud',
        'music.apple.com' => 'apple_music',
        'podcasts.apple.com' => 'apple_podcasts',
        'vimeo.com' => 'vimeo',
        'opentable.com.au' => 'opentable',
        'opentable.com' => 'opentable',
        'myshopify.com' => 'shopify',
        'shopify.com' => 'shopify',
        'fresha.com' => 'fresha',
        'goo.gl' => 'google_business',
        'google.com' => 'google_business',
        'google.' => 'google_business',
    ];

    /**
     * Resolve a scraper for the given platform slug.
     *
     * @return BaseScraper|null The scraper instance, or null if the platform is unknown.
     */
    public function resolve(string $platform): ?BaseScraper
    {
        $class = self::SCRAPER_MAP[$platform] ?? null;
        if ($class === null) {
            return null;
        }

        // Use the container so DI works (SafeUrlFetcher, FetchBudget, etc.)
        return app($class);
    }

    /**
     * Resolve a scraper by detecting the platform from a URL.
     *
     * @return BaseScraper|null The scraper instance, or null if the host is unknown.
     */
    public function resolveForUrl(string $url): ?BaseScraper
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::HOST_MAP as $pattern => $slug) {
            if (str_contains($host, $pattern)) {
                return $this->resolve($slug);
            }
        }

        return null;
    }

    /**
     * Return all registered platform slugs.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys(self::SCRAPER_MAP);
    }

    /**
     * Check if a platform slug is registered.
     */
    public function has(string $platform): bool
    {
        return isset(self::SCRAPER_MAP[$platform]);
    }
}
