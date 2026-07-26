<?php

namespace App\Console\Commands\V5;

use App\Models\V5\UserPlatform;
use App\Services\V5\ItemService;
use App\Services\V5\Scraping\ScraperResolver;
use Illuminate\Console\Command;

// V5 refresh command — test harness for running scrapers and ingesting items.
// Usage: php artisan v5:refresh youtube --user=019f936e-115f-7203-bd51-7459da0d1959
class RefreshPlatformCommand extends Command
{
    protected $signature = 'v5:refresh
        {slug : Platform slug (youtube, spotify, instagram, etc.)}
        {--identifier= : URL or handle to scrape}
        {--user= : User ID to associate items with}
        {--pool= : Content pool name override}';

    protected $description = 'Scrape a platform and ingest items into a V5 content pool';

    public function handle(ScraperResolver $resolver, ItemService $items): int
    {
        $slug = $this->argument('slug');
        $identifier = $this->option('identifier');
        $userId = $this->option('user');

        if (! $resolver->has($slug)) {
            $this->error("Unknown platform: {$slug}");
            $this->line('Available: ' . implode(', ', array_keys($resolver->all())));
            return self::FAILURE;
        }

        // If no identifier provided, look up the user's platform connection
        if (! $identifier && $userId) {
            $up = UserPlatform::where('user_id', $userId)
                ->whereHas('platformDefinition', fn ($q) => $q->whereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE ?", ["%{$slug}%"]))
                ->first();
            if ($up) {
                $identifier = $up->identifier_value;
                $this->info("Using stored identifier: {$identifier}");
            }
        }

        if (! $identifier) {
            $this->error('No identifier provided. Use --identifier or --user with a connected platform.');
            return self::FAILURE;
        }

        $this->info("Scraping {$slug} with identifier: {$identifier}");

        $result = $resolver->scrape($slug, $identifier);

        if (! $result) {
            $this->error('Scrape returned no results.');
            return self::FAILURE;
        }

        $itemCount = count($result['items'] ?? []);
        $this->info("Scraped {$itemCount} items.");

        if ($itemCount > 0 && $userId) {
            $up = UserPlatform::where('user_id', $userId)
                ->whereHas('platformDefinition', fn ($q) => $q->whereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE ?", ["%{$slug}%"]))
                ->first();

            if ($up) {
                $poolName = $this->option('pool') ?? $this->poolForSlug($slug, $result['items'][0]['item_type'] ?? 'link');
                $ingested = $items->ingest($up, $poolName, $result['items'], $result['profile'] ?? []);
                $this->info("Pool: {$poolName} | Created: {$ingested['created']} | Updated: {$ingested['updated']} | Profile: " . ($ingested['profileUpdated'] ? 'yes' : 'no'));
            } else {
                $this->warn('No user platform connection found — items not ingested. Use --user to specify a user.');
            }
        }

        if ($itemCount > 0) {
            $this->line('');
            $this->table(
                ['#', 'Name', 'Type', 'Identifier'],
                collect($result['items'])->take(5)->map(fn ($i, $k) => [$k + 1, $i['name'] ?? '-', $i['item_type'] ?? '-', $i['identifier'] ?? '-'])->toArray()
            );
            if ($itemCount > 5) $this->line("... and " . ($itemCount - 5) . " more");
        }

        return self::SUCCESS;
    }

    private function poolForSlug(string $slug, string $itemType): string
    {
        return match (true) {
            in_array($slug, ['youtube', 'vimeo', 'twitch']) => 'watch',
            in_array($slug, ['spotify', 'apple-music', 'soundcloud', 'bandcamp', 'youtube-music']) => 'music',
            in_array($slug, ['apple-podcasts', 'apple-podcast']) => 'podcasts',
            in_array($slug, ['eventbrite', 'humanitix']) => 'events',
            in_array($slug, ['fresha', 'square']) => 'services',
            in_array($slug, ['uber-eats', 'doordash', 'square-online']) => 'menu',
            in_array($slug, ['shopify', 'woocommerce']) => 'products',
            in_array($slug, ['instagram', 'pinterest']) => 'media',
            in_array($slug, ['google-business']) => 'media',
            $itemType === 'video' => 'watch',
            $itemType === 'track' => 'music',
            $itemType === 'podcast episode' => 'podcasts',
            $itemType === 'event' => 'events',
            default => 'links',
        };
    }
}
