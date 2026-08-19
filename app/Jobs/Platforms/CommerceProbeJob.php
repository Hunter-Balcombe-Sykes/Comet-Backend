<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkObserver;
use App\Routing\Placement;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\Verdict;
use App\Services\Brand\StoreBrandSeeder;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopProductSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves ONE scanned link into the richest thing it can be.
 *
 * Replaces ProbeCommerceLinksJob (2026-07-25, Phase 10). The key difference:
 * this job calls CustomLinkSeeder::seedCustom() on failure, not seed() —
 * avoiding the mutual recursion between seed() → LinkRouter → seed().
 *
 * Resolution chain:
 *   category 'event'           → EventsSeeder::seedStandalone()
 *   category 'event-organiser' → EventsSeeder::seedAccount()
 *   category 'shop'            → StoreBrandSeeder (probe subsumes detection)
 *   category null (unknown)    → GenericShopScraper probe → product/store/neither
 *
 * Every miss falls through to seedCustom() — "nothing vanishes."
 *
 * WAVE-2C (2026-08-06): seedStore() used to run ShopProviderDetector::detect()
 * then hand the result to the legacy ShopBrandSeeder. StoreBrandSeeder's own
 * probe (LinkProbeWorker) subsumes that detection step entirely, so this job
 * no longer needs ShopProviderDetector at all.
 */
class CommerceProbeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    public int $timeout = 90;

    public int $uniqueFor = 300;

    /**
     * This job is reached from LinkRouter for BOTH a manually-added link
     * (CustomLinksController) and a harvested one (Instagram bio scrape,
     * link-in-bio unroll) — LinkRouter::route() carries no origin parameter,
     * so which of those it was is genuinely not known here. Deliberately NOT
     * 'paste': that origin bypasses the tombstone check (RoutingContext::
     * isDirectRequest()), and overclaiming directness for a link that may
     * have been harvested would risk resurrecting something a user removed.
     * A distinct origin, like every other importer's own string
     * (website_import, the LinkInBioImporter kind), keeps this honest and
     * tombstone-safe.
     */
    private const ORIGIN = 'commerce_probe';

    public function __construct(
        public readonly string $userId,
        public readonly string $url,
        public readonly ?string $category = null,
        public readonly ?string $platform = null,
        // A pasted link (RoutingController::store): a storefront the probe
        // recognises becomes a suggestion for the user to confirm, not a
        // placed store — and the miss path writes no second link card (the
        // paste already wrote one).
        public readonly bool $suggestOnly = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->url);
    }

    public function handle(
        GenericShopScraper $generic,
        StoreBrandSeeder $brands,
        ShopProductSeeder $products,
        EventsSeeder $events,
        CustomLinkSeeder $links,
        IriCanonicalizer $canonicalizer,
        LinkObserver $observer,
    ): void {
        $user = User::find($this->userId);
        if ($user === null || $user->isPendingDeletion()) {
            return;
        }

        try {
            // The event seeders return the resource_id they wrote (LinkRouter
            // needs it to name the row in its finding); here only "did anything
            // land" matters, and $resolved is logged as a bool below.
            $resolved = match ($this->category) {
                'event' => $events->seedStandalone($user, (string) $this->platform, $this->url) !== null,
                'event-organiser' => $events->seedAccount($user, (string) $this->platform, $this->url) !== null,
                'shop' => $this->seedStore($brands, $user, $this->url),
                default => $this->probe($generic, $brands, $products, $user, $canonicalizer, $observer),
            };
        } catch (Throwable $e) {
            report($e);
            $resolved = false;
        }

        if (! $resolved && ! $this->suggestOnly) {
            $links->seedCustom($user, $this->url);
        }

        Log::info('commerce_probe.resolved', [
            'user_id' => $this->userId,
            'category' => $this->category ?? 'probe',
            'resolved' => $resolved,
        ]);
    }

    private function probe(
        GenericShopScraper $generic,
        StoreBrandSeeder $brands,
        ShopProductSeeder $products,
        User $user,
        IriCanonicalizer $canonicalizer,
        LinkObserver $observer,
    ): bool {
        $read = $generic->readProductPage($this->url);

        if ($read['outcome'] === GenericShopScraper::OUTCOME_PRODUCT && is_array($read['product'])) {
            $seeded = $products->seed($user, $read['product'], self::ORIGIN);

            // T7 (2026-08-20): the paste lane auto-connects a product's store
            // (ProductPageAdder → ConnectStoreFromProductJob); the scan lane
            // seeded the product and STOPPED — a scanned Shopify product link
            // never connected its store. Same brain now: ask the store
            // question at the ORIGIN, through StoreBrandSeeder, whose
            // PlacementPolicy owns the tombstone (never resurrect a
            // disconnected store — deliberately NOT ConnectStoreFromProductJob,
            // which carries no tombstone check and is scoped to the
            // user-initiated paste). Best-effort: the product is already in.
            if ($seeded) {
                $origin = $this->origin($this->url);
                if ($origin !== null) {
                    try {
                        $this->seedStore($brands, $user, $origin);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }

            return $seeded;
        }

        if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE && is_string($read['storeUrl'])) {
            return $this->seedStore($brands, $user, $read['storeUrl']);
        }

        if ($read['outcome'] === GenericShopScraper::OUTCOME_NO_PRODUCT) {
            return $this->seedStore($brands, $user, $this->url);
        }

        // T4 (2026-08-20): an UNREACHABLE deep page still gets the store
        // question — asked at the ORIGIN. The storefront probes hang off the
        // origin anyway (ShopifyStorefrontProbe reads /meta.json, never the
        // pasted path), and the live failure was a stale bio link:
        // natalieanne.com/pages/natalie-anne-education 404s for every UA, the
        // ONE probe that host would ever get was spent on it, and the
        // homepage that identifies Shopify instantly was never asked. The
        // origin (not the dead deep URL) becomes the brand's sourceUrl so
        // re-fetches read a page that exists. On a MISS the deep URL's own
        // probe_unreachable note below still writes (R6 unchanged); on a
        // PLACE the trace is the seeder's own rows keyed to the ORIGIN —
        // the deep URL's story is then the custom-link card handle() skips
        // (resolved=true) plus the seeder's intent ledger. Budget note: this
        // fallback spends a real ProbeBudget slot per dead link — accepted
        // (owner, 2026-08-20: waste beats missing a store) with T9 raising
        // the daily caps in step.
        if ($read['outcome'] === GenericShopScraper::OUTCOME_UNREACHABLE) {
            $origin = $this->origin($this->url);
            if ($origin !== null && $this->seedStore($brands, $user, $origin)) {
                return true;
            }
        }

        // Nothing resolved — the page could not be fetched, or came back in a
        // shape the reader could not use. Every OTHER arm of this method ends
        // in a seeder that records its own decision; this one reaches no
        // seeder at all, so without this write a link that fell all the way
        // through to a plain custom link leaves no trace of having been looked
        // at (R6, 2026-08-18 — the companion to the product lane's own gap).
        //
        // Note, not Reject: nothing was refused. The link is kept as a link
        // item by seedCustom() in handle(), which is precisely what
        // Verdict::Note means. Projection::none() because no detector and no
        // probe ever claimed it — LinkObserver writes null confidence for an
        // unmatched projection, so the row cannot read as a weak match.
        $reason = 'probe_'.$read['outcome'];
        $observer->record(
            $canonicalizer->canonicalize($this->url),
            Projection::none($reason),
            new Placement(Verdict::Note, null, null, $reason),
            RoutingContext::forUser($user, self::ORIGIN),
        );

        return false;
    }

    private function seedStore(StoreBrandSeeder $brands, User $user, string $url): bool
    {
        return $brands->seed($user, $url, self::ORIGIN, suggestOnly: $this->suggestOnly)['outcome'] === 'placed';
    }

    /** scheme://host of the probed URL, or null when it has neither. */
    private function origin(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && is_string($host) && $host !== ''
            ? strtolower($scheme).'://'.strtolower($host).'/'
            : null;
    }

    public function failed(Throwable $e): void
    {
        report($e);

        Log::error('platforms.commerce_probe.failed', [
            'user_id' => $this->userId,
            'url' => $this->url,
            'category' => $this->category,
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);
    }
}
