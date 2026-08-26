<?php

namespace App\Console\Commands;

use App\Services\Platforms\StorefrontFaviconScraper;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retro-fit the favicon onto stores connected before the seeder fetched one.
 *
 * StoreBrandSeeder now fetches a favicon when the probe lane carried none
 * (Shopify reads only /meta.json, Big Cartel only its store API). That fixes
 * every store from here on, but nothing re-runs for an already-connected one,
 * so each storefront placed earlier keeps a NULL favicon_url forever — and
 * ShopBrandResource serves that column, so the Platforms table icon stays
 * blank too.
 *
 * One request per candidate store, and only for stores that have NEITHER a
 * favicon nor a logo: queueStoreLogo() prefers the logo, so a store with one
 * already renders and needs nothing.
 *
 * Written as a normal maintenance command rather than a throwaway so it can be
 * re-run — a shop that was unreachable on the first pass is picked up on the
 * next, and a miss is never written back as an empty string that would read as
 * "already has one".
 */
class BackfillStorefrontFaviconsCommand extends Command
{
    protected $signature = 'shop:backfill-favicons
        {--dry-run : Report what would be fetched and write nothing}
        {--limit=0 : Stop after this many candidates (0 = no limit)}';

    protected $description = 'Fetch a favicon for connected storefronts that have neither a favicon nor a logo.';

    public function handle(StorefrontFaviconScraper $favicons, ShopConnections $shop, ShopContentWriter $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $candidates = DB::table('content.storefronts as s')
            ->join('content.collections as c', 'c.id', '=', 's.collection_id')
            ->whereNull('s.favicon_url')
            ->whereNull('s.logo_url')
            ->where('s.is_individual', false)
            ->select('s.collection_id', 'c.user_id')
            ->orderBy('s.collection_id')
            ->get();

        if ($limit > 0) {
            $candidates = $candidates->take($limit);
        }

        $found = 0;
        $missed = 0;

        foreach ($candidates as $row) {
            $store = $shop->storeByCollection((string) $row->collection_id);
            if ($store === null) {
                continue;
            }

            $source = $store->url ?: $store->sourceUrl;
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            $favicon = $favicons->fetch($source);
            if ($favicon === null) {
                // An honest miss. Deliberately NOT written back as '' — that
                // would read as "already has one" and permanently block a
                // later run that would have succeeded.
                $missed++;

                continue;
            }

            $found++;

            if ($dryRun) {
                $this->line("  would set {$store->provider} {$store->externalRef} -> {$favicon}");

                continue;
            }

            $writer->upsertStore($store->with(['faviconUrl' => $favicon]), (string) $row->user_id);
        }

        $verb = $dryRun ? 'would set' : 'set';
        $this->info("Candidates {$candidates->count()}; {$verb} {$found}; still without an icon {$missed}.");

        return self::SUCCESS;
    }
}
