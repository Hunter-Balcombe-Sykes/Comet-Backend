<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\PreAccountBuildEvent;
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
use App\Services\PreAccount\BuildProgress;
use App\Services\Shop\DiscountCodeAdopter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        /**
         * The suggestions-inbox intent this run is ANSWERING
         * (SuggestionsController::accept). Null for every discovery lane.
         *
         * An accept is an explicit user answer, which changes two things:
         * the deep-page suggestion rule no longer applies (the question it
         * exists to ask has been asked), and a seed that fails must settle
         * the intent rather than leave it standing as though nothing
         * happened.
         */
        public readonly ?string $acceptedIntentId = null,
        /**
         * A discount code the link tile's title carried (DiscountCodeSniffer,
         * 2026-09-02) — adopted by the storefront this probe mints, fill-if-empty.
         */
        public readonly ?string $discountCode = null,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    /**
     * An accept gets its own uniqueness slot.
     *
     * uniqueFor is 300s and the discovery probe that CREATED the suggestion
     * keys on the same (user, url) pair, so a user who answered the card
     * inside that window had their accept silently dropped while the 202
     * still reported success — the exact "Adding..." that never finishes.
     */
    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->url).($this->acceptedIntentId !== null ? ':accept' : '');
    }

    /**
     * Owe the setup walk this probe's answer (2026-09-05, st_ali retest #3).
     *
     * A discovery probe lands 15–45s after the harvest that dispatched it,
     * and the walk's platforms loader used to release on the harvest's own
     * terminal — the store card then appeared only on a refresh, or never
     * caught the eye. Every discovery dispatch site calls this first, in the
     * dispatcher's own process; handle() and failed() write the matching
     * terminal under the same token, so overlapping probes close only
     * themselves (BuildProgress). A no-op outside a live build.
     */
    public static function owe(string $userId, string $url): void
    {
        BuildProgress::noteForUser($userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Looking for your store', [
            BuildProgress::TOKEN => self::token($url),
        ]);
    }

    private static function token(string $url): string
    {
        return 'store:'.substr(sha1($url), 0, 16);
    }

    /** The discovery lane's own terminal — the accept lane answers on `shop`. */
    private function settleOwed(bool $resolved): void
    {
        if ($this->acceptedIntentId !== null) {
            return;
        }
        BuildProgress::noteForUser(
            $this->userId,
            PreAccountBuildEvent::STAGE_PLATFORMS,
            $resolved ? PreAccountBuildEvent::STATUS_LANDED : PreAccountBuildEvent::STATUS_SKIPPED,
            $resolved ? 'Found your store' : 'No store there',
            [BuildProgress::TOKEN => self::token($this->url)],
        );
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
                // Same deep-page rule as the probe arm below (final critic,
                // 2026-08-20): the classifier names 'shop' by HOST alone, so a
                // deep path on a recognised shop host is exactly the 4barbers
                // affiliate shape and must be a question, not an auto-connect.
                'shop' => $this->seedStore($brands, $user, $this->url, $this->isDeepPage()),
                default => $this->probe($generic, $brands, $products, $user, $canonicalizer, $observer),
            };
        } catch (Throwable $e) {
            report($e);
            $resolved = false;
        }

        if (! $resolved && ! $this->suggestOnly) {
            $links->seedCustom($user, $this->url);
        }
        if ($resolved && $this->discountCode !== null) {
            app(DiscountCodeAdopter::class)->adopt($user, $this->url, $this->discountCode);
        }

        // An accept that resolved is settled by the reconciler on its way
        // through PlacementPolicy, exactly as any placement is. An accept
        // that did NOT resolve is two different things, and only one of them
        // is ours to answer — see settleAcceptedIntent().
        if (! $resolved && $this->acceptedIntentId !== null) {
            $this->settleAcceptedIntent();
            // The accept lane wrote `shop` STARTED at the tick (2026-09-05);
            // a miss closes it, or the walk waits the full stale window.
            BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_SKIPPED, "Couldn't read your store");
        }
        $this->settleOwed($resolved);

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
            //
            // ALWAYS suggest-only (critic blocker, 2026-08-20): a product
            // link is the classic "shop my friend's boutique" shout-out
            // shape — auto-connecting its store would attribute someone
            // else's business to the scanned account. Same principle T9b
            // pins for media parents: from an ITEM, the parent is a
            // QUESTION. (The paste lane keeps its auto-connect — a person
            // pasting their own product asked for it.)
            if ($seeded) {
                $origin = $this->origin($this->url);
                if ($origin !== null) {
                    try {
                        $brands->seed($user, $origin, self::ORIGIN, suggestOnly: true);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }

            return $seeded;
        }

        // FI-10 (T5 live, 2026-08-20): a REACHABLE DEEP page on a store
        // domain is shout-out shaped — an affiliate/discount page
        // (4barbers.com.au/pages/matsui-… with "Discount Code Hayley10")
        // auto-connected someone ELSE'S supply shop as the scanned account's
        // store and imported its whole catalogue. Same principle as the
        // product-page rule above: from a deep page, the store is a
        // QUESTION. A link to the store's ROOT stays an auto-connect — a
        // homepage in your own bio (natalieanne.com, onefour.store) is you
        // naming your store. The UNREACHABLE arm below keeps its
        // origin-probe auto-connect deliberately: its live case
        // (natalieanne.com/pages/… 404ing) was the owner's own stale link,
        // and a dead page offers no markers to judge affiliation by.
        // T24/issue 18 (2026-08-28): a CATALOG view is root-equivalent. FI-10's
        // live incident was /pages/<someone-else's-brand> with a discount code
        // — an affiliate shape. drsleek.com.au/collections/all is the opposite
        // shape: the store's own full catalogue, linked from the owner's own
        // bio ("Shop our Award-Winning Beard Serum"), and the suggest-only
        // arm parked it in an inbox no unclaimed account ever reads. Bare
        // /collections[/x], /shop and /store paths auto-connect like the
        // root; /pages/*, single /products/<x> and everything else stay the
        // question FI-10 made them.
        $path = trim((string) parse_url($this->url, PHP_URL_PATH), '/');
        $catalogShaped = preg_match('#^(collections(/[^/]+)?|shop|store)/?$#i', $path) === 1;
        $deepPage = $path !== '' && ! $catalogShaped;

        if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE && is_string($read['storeUrl'])) {
            return $this->seedStore($brands, $user, $read['storeUrl'], $deepPage);
        }

        if ($read['outcome'] === GenericShopScraper::OUTCOME_NO_PRODUCT) {
            return $this->seedStore($brands, $user, $this->url, $deepPage);
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

    /**
     * Whether this URL's own path makes it a deep page — a question rather
     * than an auto-connect (FI-10).
     *
     * Never true on the accept lane. The rule exists to stop an UNASKED
     * auto-connect attributing someone else's storefront to this account;
     * once the owner has answered the card, re-applying it downgraded the
     * placement to Choose and the reconciler wrote the intent straight back
     * to 'proposed' — the card returned and nothing ever connected.
     */
    private function isDeepPage(): bool
    {
        return $this->acceptedIntentId === null
            && trim((string) parse_url($this->url, PHP_URL_PATH), '/') !== '';
    }

    private function seedStore(StoreBrandSeeder $brands, User $user, string $url, bool $deepPage = false): bool
    {
        // `confirmed` is what makes the ACCEPT lane able to place at all since
        // 2026-09-03 (PlacementPolicy mints Place only for a request the user
        // made). acceptedIntentId is exactly that signal — SuggestionsController
        // sets it and no discovery lane does. Without it an accept would loop:
        // re-decide the same URL, get Choose again, and settle the intent it
        // was answering as unservable.
        $result = $brands->seed(
            $user,
            $url,
            self::ORIGIN,
            suggestOnly: $this->suggestOnly || $deepPage,
            confirmed: $this->acceptedIntentId !== null,
        );

        // A placement of ANY kind other than Note is a RESOLUTION — the
        // routing system already filed what it needed to (a Choose
        // suggestion, a cap-reached Swap, an outright refusal), and handle()
        // must not also card the link on top of it. Widened 2026-09-05: this
        // used to require suggestOnly||deepPage, so a plain root-URL
        // discovery on a sign-up build — which PlacementPolicy can only ever
        // answer with Choose (2026-09-03: sign-up builds connect nothing by
        // themselves) — fell through the qualifier and got BOTH a filed
        // intent AND a duplicate custom-link card (live: jrlusa.com,
        // squeakprobarber signup). Note is excluded on purpose: its own
        // contract is "kept as a link, never dropped" (PlacementPolicy's own
        // words) — a weak or ungoverned match still wants its card. 'capped'
        // is a real connection that only lost the shop_brands DISPLAY row;
        // the card would sit under a store that is, in fact, connected.
        $resolved = $result['outcome'] === 'placed'
            || $result['outcome'] === 'capped'
            || ($result['outcome'] === 'not_placed' && $result['verdict'] !== null && $result['verdict'] !== 'note');

        // Resolved without a store to fill (a cap Swap, a hold): nothing
        // will write the `shop` terminal the accept lane's STARTED is
        // waiting on, so close it here (2026-09-05).
        if ($resolved && $this->acceptedIntentId !== null && $result['outcome'] !== 'placed') {
            BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_SKIPPED, "Couldn't connect your store yet");
        }

        return $resolved;
    }

    /**
     * Record that the user's answer was tried and could not be served.
     *
     * 'unservable' rather than the probe's own reason: block_reason is wire
     * vocabulary the inbox renders in words, and the set is deliberately
     * small. Which probe missed, and why, is the log's job below.
     *
     * Scoped to the user and to a LIVE state so a dismissal, or a placement
     * that landed by another route while this ran, is never overwritten.
     *
     * And scoped to a block_reason nothing better has been said about. An
     * unresolved accept is one of two things:
     *
     *  · a probe MISS — seed() returns before ever reaching the reconciler,
     *    so nothing settles the intent at all. That is what this is for.
     *  · a HOLD — seed() reconciles and THEN returns 'not_placed'. The
     *    reconciler has already written the real reason (cap_reached,
     *    conflict, gate), which is both more informative and differently
     *    actionable: cap_reached renders a Swap with a Replace button, where
     *    unservable renders a Try again that can only fail the same way
     *    forever. Left alone.
     */
    private function settleAcceptedIntent(): void
    {
        DB::table('routing.source_intents')
            ->where('id', $this->acceptedIntentId)
            ->where('user_id', $this->userId)
            ->whereIn('state', ['proposed', 'blocked'])
            // 'needs_confirmation' and NULL both mean "recognised, nothing
            // decided"; re-settling an existing 'unservable' keeps a retry
            // idempotent.
            ->where(fn ($q) => $q->whereNull('block_reason')
                ->orWhereIn('block_reason', ['needs_confirmation', 'unservable']))
            ->update([
                'state' => 'blocked',
                'block_reason' => 'unservable',
                'updated_at' => now(),
            ]);

        Log::info('commerce_probe.accept_unservable', [
            'user_id' => $this->userId,
            'intent_id' => $this->acceptedIntentId,
            'url' => $this->url,
        ]);
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
        $this->settleOwed(false);

        // Zero-loss (M-9 critic, 2026-08-21): handle()'s own miss path cards
        // the link, but a JOB-level death (timeout, worker kill after tries)
        // used to drop it entirely — counted 'connected' by the importer that
        // delegated it, present nowhere. Card it here, best-effort, unless
        // the caller was suggest-only (a paste already wrote its own card).
        if ($this->suggestOnly) {
            return;
        }

        try {
            $user = User::find($this->userId);
            if ($user !== null && ! $user->isPendingDeletion()) {
                app(CustomLinkSeeder::class)->seedCustom($user, $this->url);
            }
        } catch (Throwable $cardError) {
            report($cardError);
        }
    }
}
