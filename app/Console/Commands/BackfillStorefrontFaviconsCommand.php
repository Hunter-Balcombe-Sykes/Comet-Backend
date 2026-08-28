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
            // content.storefronts is a sidecar on content.collections, and
            // ordering platforms carry one too. ShopConnections::storeQuery()
            // filters on this same kind, so without it here the candidate
            // count included rows storeByCollection() would then refuse — 21
            // of 33 on dev, dropped with no line of output, which reads as
            // coverage that did not happen.
            ->where('c.kind', 'storefront')
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
        $unresolvable = 0;

        foreach ($candidates as $row) {
            // Counted, never a bare continue: every candidate must be
            // accounted for in the summary, or the numbers silently stop
            // adding up and the run reads as more complete than it was.
            $store = $shop->storeByCollection((string) $row->collection_id);
            if ($store === null) {
                $unresolvable++;

                continue;
            }

            $source = $store->url ?: $store->sourceUrl;
            if (! is_string($source) || trim($source) === '') {
                $unresolvable++;

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

        if ($unresolvable > 0) {
            $this->warn("  {$unresolvable} candidate(s) could not be read back as a store and were skipped.");
        }

        return self::SUCCESS;
    }
}
