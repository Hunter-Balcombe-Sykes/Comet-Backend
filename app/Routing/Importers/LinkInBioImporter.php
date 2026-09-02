<?php

namespace App\Routing\Importers;

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\SecretParams;
use App\Routing\ShortLinkExpander;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Content\LinkPoolReader;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\InstagramIdentitySync;
use App\Services\Platforms\LinkInBioApiUnroller;
use App\Services\Platforms\LinkInBioDetector;
use App\Services\Platforms\LinkInBioInlinePayloadReader;
use App\Services\Platforms\MediaSeeder;
use App\Services\Platforms\ScrapeCreators\KomiLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\LinkbioLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\LinkmeLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\LinktreeLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\PillarLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\Shop\DiscountCodeSniffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Link-in-bio unroll (Linktree, Beacons, Stan…), on the new router.
 *
 * Nearly the same shape as WebsiteImporter, with one rule that is NOT
 * incidental: a bio-link page is itself a platform, and its own chrome —
 * pricing, blog, help centre, signup — sits in the same anchor soup as the
 * two or three links the account owner actually put there. Measured on a real
 * page (2026-07-20): 58 anchors, 55 of them Linktree's own, all on
 * linktr.ee itself. Skipping same-host links is what stops an unroll turning
 * a user's page into a directory of someone else's marketing.
 *
 * That rule is carried forward verbatim from LinkInBioScanJob, which this
 * replaces once its findings-merge path is migrated too.
 *
 * ONE run, N pages. An Instagram bio harvest hands over every URL it found in
 * one profile (bio text, `external_url`, the link sticker) — that is a single
 * acquisition of one account, and recording it as N separate runs would both
 * burn N of the user's daily slots (ImportRun::DAILY_LIMIT) and lie about what happened. The batch
 * is therefore the unit: one `routing.import_runs` row, one shared dedupe
 * table, one shared link budget.
 */
class LinkInBioImporter
{
    /**
     * Hard cap on links considered from ONE RUN — not per page. A batch is one
     * acquisition, so it gets one budget; a single-URL import is a batch of
     * one and sees the identical cap it always had.
     */
    private const MAX_LINKS = 300;

    /** Hard cap on pages fetched in one run. */
    private const MAX_PAGES = 50;

    /** Run kinds this importer may record. Mirrors routing.import_runs.kind. */
    private const KINDS = ['link_in_bio', 'bio_harvest'];

    /** Probe budget per RUN — parity with the legacy RouteContext::DEFAULT_MAX_PROBES. */
    private const MAX_PROBES = 18;

    /**
     * Substrings that identify a Cloudflare refusal page rather than the site.
     * Two products, two shapes: a managed challenge the browser can solve, and
     * a firewall block that nothing can. Both are 403 to us.
     */
    private const CHALLENGE_MARKERS = [
        'Just a moment',
        'Attention Required',
        '__cf_chl',
        'cf-browser-verification',
        'Checking your browser',
    ];

    /**
     * The five link-in-bio services ScrapeCreators reads (Item 10b, 2026-09-01
     * — the quartet generalizing Item 8's Linktree-only lane). One endpoint +
     * one normalizer per service: the recorded payloads share no row shape
     * (Komi hides invisible modules, Pillar splits links/products/socials,
     * Lnk.Bio says text-not-title, Linkme nests grouped webLinks), so a
     * parameterized single normalizer was rejected — each stays a thin
     * per-endpoint pass, the Wave-1 pattern.
     *
     * Hosts match exactly or by subdomain — Komi pages LIVE on subdomains
     * (<user>.komi.io), and www. rides the same rule. clk.bio is Lnk.Bio's
     * own reachable mirror of lnk.bio (see retireFloorCards' history); the
     * vendor documents lnk.bio URLs, so the mirror rewrites onto the
     * canonical host before the call — a vendor refusal of the rewrite just
     * falls through to the HTML parse that already reads clk.bio today.
     */
    private const VENDOR_SERVICES = [
        'linktree' => ['path' => '/v1/linktree', 'normalizer' => LinktreeLinksNormalizer::class, 'hosts' => ['linktr.ee']],
        'komi' => ['path' => '/v1/komi', 'normalizer' => KomiLinksNormalizer::class, 'hosts' => ['komi.io']],
        'pillar' => ['path' => '/v1/pillar', 'normalizer' => PillarLinksNormalizer::class, 'hosts' => ['pillar.io']],
        'linkbio' => ['path' => '/v1/linkbio', 'normalizer' => LinkbioLinksNormalizer::class, 'hosts' => ['lnk.bio', 'clk.bio']],
        'linkme' => ['path' => '/v1/linkme', 'normalizer' => LinkmeLinksNormalizer::class, 'hosts' => ['link.me']],
    ];

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkInBioApiUnroller $api,
        private readonly LinkInBioInlinePayloadReader $inline,
        private readonly LinkRoutingService $routing,
        private readonly CustomLinkSeeder $seeder,
        private readonly EventsSeeder $events,
        private readonly MediaSeeder $media,
        private readonly ShortLinkExpander $expander,
        private readonly LinkInBioDetector $bioDetector,
        private readonly LinkPoolReader $linkReader,
    ) {}

    /**
     * @param  string|list<string>  $bioPageUrls  one page, or the list a bio harvest produced
     * @param  string  $kind  'link_in_bio' (a page the user named) | 'bio_harvest' (URLs lifted off a profile)
     * @return array{outcome: string, observations: int, connected: int, suggested: int, noted: int, dropped: int, skipped_chrome: int, pages: int, pages_unavailable: int, unavailable_reasons: array<string, int>}
     */
    /** Vendor tile titles by lowercased url — the discount-code sniff reads them (2026-09-02). */
    private array $vendorTitles = [];

    /** Contact tiles the vendor lane surfaced (mailto:/tel:) — folded into publicContact (2026-09-02). */
    private array $vendorContacts = [];

    public function import(User $user, string|array $bioPageUrls, string $kind = 'link_in_bio'): array
    {
        $this->vendorTitles = [];
        $this->vendorContacts = [];
        if (! in_array($kind, self::KINDS, true)) {
            $kind = 'link_in_bio';
        }

        $pages = $this->normalisePages($bioPageUrls);
        $empty = ['observations' => 0, 'connected' => 0, 'suggested' => 0, 'noted' => 0, 'items' => 0, 'probed' => 0, 'placed_platforms' => [], 'contacts' => 0, 'dropped' => 0, 'folded' => 0, 'skipped_chrome' => 0, 'pages' => 0, 'pages_unavailable' => 0, 'unavailable_reasons' => [], 'bio_url_seeded' => false];

        if ($pages === []) {
            return ['outcome' => 'unavailable'] + $empty;
        }

        // source_url records the first page: the column is one URL, and the
        // full list lives in the run's detail rather than being truncated into
        // a column that cannot hold it.
        $runId = ImportRun::start((string) $user->id, $kind, $pages[0]);
        if ($runId === null) {
            return ['outcome' => 'cooldown'] + $empty;
        }

        $context = RoutingContext::forUser($user, $kind, $runId);
        $tally = ['connected' => 0, 'suggested' => 0, 'noted' => 0, 'items' => 0, 'probed' => 0, 'placed_platforms' => [], 'contacts' => 0, 'dropped' => 0, 'folded' => 0, 'skipped_chrome' => 0];
        $seen = [];
        $probedHosts = [];
        $placedKeys = [];
        $droppedReasons = [];
        $unavailable = 0;
        $unavailableReasons = [];
        $unrolled = [];
        $pageCount = count($pages);
        $pageDelayMs = (int) config('partna.routing.link_in_bio.page_delay_ms', 250);

        foreach ($pages as $index => $pageUrl) {
            $response = $this->fetcher->tryFetch($pageUrl);

            if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
                // Vendor rescue (Item 10b): the vendor lane must not die with
                // the fetch it exists to replace — lnk.bio 403s both of
                // SafeUrlFetcher's UAs at the edge, so for that whole host
                // class the lane inside unroll() would never run. An empty
                // body runs unroll()'s vendor arm alone (no anchors, no
                // inline payload to read); a vendor yield makes the page a
                // normal acquisition, and any miss falls into the
                // unavailable accounting below exactly as before.
                if ($this->vendorService($pageUrl) !== null) {
                    $before = count($seen);
                    $this->unroll($pageUrl, '', $context, $tally, $seen, $probedHosts, $placedKeys, $droppedReasons);
                    if (count($seen) > $before) {
                        $unrolled[] = $pageUrl;
                        $this->paceNextFetch($index, $pageCount, $pageDelayMs);

                        continue;
                    }
                }

                $unavailable++;
                $reason = $this->unavailableReason($response);
                $unavailableReasons[$reason] = ($unavailableReasons[$reason] ?? 0) + 1;

                // A bot challenge is the only reason worth waking someone for:
                // it means the host is refusing US specifically, and no amount
                // of parsing will fix it (bio.link, heylink.me, direct.me and
                // beacons.ai sit behind one). Every other reason is the page's
                // own problem and stays in the run detail tally.
                if ($reason === 'bot_challenge') {
                    Log::warning('platforms.link_in_bio.host_blocked_us', [
                        'user_id' => (string) $user->id,
                        'host' => strtolower((string) parse_url($pageUrl, PHP_URL_HOST)),
                        'status' => $response['status'] ?? null,
                    ]);
                }

                // SCALE-5: a refused request still hit the host — a 403 burst
                // is exactly what escalates a soft throttle into a hard block.
                $this->paceNextFetch($index, $pageCount, $pageDelayMs);

                continue;
            }

            // Which PAGE yielded is not derivable from the run-wide
            // $observations, and the retire below must not treat "some page
            // unrolled" as "this page unrolled". Record both the requested URL
            // and the one we landed on: a card may have been floored for
            // either, depending on which run created it.
            $before = count($seen);
            $this->unroll($response['finalUrl'], $response['body'], $context, $tally, $seen, $probedHosts, $placedKeys, $droppedReasons);
            if (count($seen) > $before) {
                $unrolled[] = $pageUrl;
                $unrolled[] = $response['finalUrl'];
            }

            $this->paceNextFetch($index, $pageCount, $pageDelayMs);
        }

        $fetched = count($pages) - $unavailable;
        $observations = count($seen);

        // Suppression is READ, not absorbed (critic, 2026-08-20): a
        // tombstoned item/event counts in its lane's tally as HANDLED (no
        // card may carry it), and this says how many of those handled were
        // deliberate no-writes.
        $tally['tombstoned'] = $this->media->tombstonedThisRun() + $this->events->tombstonedThisRun();

        // Zero-yield floor (N2): a MATCHED bio host that unrolls to nothing is
        // a silent total loss — the detector claimed the URL, so no other path
        // will ever write it (InstagramAutoSync dispatches this job and
        // `continue`s: "Nothing about the bio-link URL itself is persisted").
        // linkin.bio (an Ember SPA, zero anchors in the delivered shell) is the
        // live case. Keyed on nothing-routable, so an all-own-chrome page floors
        // identically.
        //
        // The `$fetched > 0` guard this used to carry made the floor inert in
        // the one case that needs it most. Five detector hosts — bio.link,
        // heylink.me, direct.me, lnk.bio, beacons.ai — sit behind a Cloudflare
        // challenge or WAF block and answer 403 to both of SafeUrlFetcher's UA
        // attempts. Every page then counts as unavailable, `$fetched` is 0, the
        // floor did not fire, and the user's bio link was dropped ENTIRELY: no
        // links AND no card, strictly worse than the inert card linkin.bio got.
        // A URL we could not read is still a URL the owner published, so it
        // floors too. `$pages` is non-empty here — the `$pages === []` early
        // return above guarantees it.
        $bioUrlSeeded = false;
        if ($observations === 0) {
            $this->seeder->seedCustom($user, $pages[0]);
            $bioUrlSeeded = true;
        } else {
            // The floor's mirror image. A card is only right while the page
            // yields nothing, and the same URL can be imported again — the
            // paste lane (RoutingController) dispatches LinkInBioScanJob on
            // demand — so a host that was rate-limited on the first try, or one
            // we have only just learned to read (clk.bio, 2026-08-24), would
            // otherwise leave the owner holding the inert card AND the links
            // that came out of it.
            $this->retireFloorCards($user, $unrolled);
        }

        // Every page down is the same failure the single-page path always
        // reported. Some down is 'partial' — a caller must be able to tell
        // "found nothing" apart from "could not look".
        $outcome = match (true) {
            $fetched === 0 => 'unavailable',
            $unavailable > 0 => 'partial',
            default => 'ok',
        };

        ImportRun::finish(
            $runId,
            $outcome,
            observations: $observations,
            intents: $tally['connected'] + $tally['suggested'],
            errorClass: $fetched === 0 ? 'fetch_failed' : null,
            // #PRIV-5: minimiseUrl(), not redactUrl() — detail->pages is
            // Scope B (routing.import_runs.detail), a non-secret PII
            // carrier. No uniqueness constraint rides on this column.
            // dropped_reasons is the durable half of the drop trace: the log
            // line ages out, this row does not, so "which link went where"
            // stays answerable per run without replaying the page (#R2).
            detail: $tally + ['dropped_reasons' => $droppedReasons, 'pages' => array_map(SecretParams::minimiseUrl(...), $pages), 'pages_unavailable' => $unavailable, 'unavailable_reasons' => $unavailableReasons, 'bio_url_seeded' => $bioUrlSeeded],
        );

        // Conflict parity with the legacy job: the findings themselves are
        // folded into GET /platforms/instagram/synced at read time
        // (SyncFindingsBridge, B4) — but the legacy path also TOLD the user,
        // and a fold nobody opens is a finding nobody sees. Same dedupe-key
        // shape as LinkInBioScanJob's, so re-runs do not stack.
        $conflicts = DB::table('routing.source_intents')
            ->where('import_run_id', $runId)
            ->where('state', 'blocked')
            ->where('block_reason', 'conflict')
            ->count();

        if ($conflicts > 0 && ! $user->isUnclaimed()) {
            // Unclaimed guard carried from the legacy job: a pre-claim user
            // has no dashboard to read /account/platforms in, so the bell
            // would ring an empty room (and the PRIV-2 strip's spirit is that
            // provisional accounts accumulate no engagement surfaces).
            app(FindingsNotifier::class)->notify(
                (string) $user->id,
                'link-in-bio-findings:'.$user->id.':'.sha1($pages[0]),
                'We found more in your bio link',
                'Your link-in-bio page mentions an integration that clashes with one you have connected — review it in Integrations.',
            );
        }

        // Contact tiles → the person's public contact, fill-if-empty (owner,
        // 2026-09-02): the same fold the Instagram seeder uses.
        $contactsApplied = 0;
        if ($this->vendorContacts !== []) {
            $email = null;
            $phone = null;
            foreach ($this->vendorContacts as $contact) {
                if (($contact['kind'] ?? null) === 'email' && $email === null && filter_var((string) ($contact['value'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                    $email = (string) $contact['value'];
                }
                if (($contact['kind'] ?? null) === 'phone' && $phone === null && preg_match('/^\+?[0-9 ().-]{6,20}$/', (string) ($contact['value'] ?? '')) === 1) {
                    $phone = (string) $contact['value'];
                }
            }
            if ($email !== null || $phone !== null) {
                try {
                    app(InstagramIdentitySync::class)->applyPublicContact($user, $email, $phone);
                    $contactsApplied = ($email !== null ? 1 : 0) + ($phone !== null ? 1 : 0);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
        $tally['contacts'] = $contactsApplied;
        $tally['placed_platforms'] = array_values(array_unique(array_filter((array) ($tally['placed_platforms'] ?? []), 'is_string')));

        return ['outcome' => $outcome, 'observations' => $observations, 'pages' => $fetched, 'pages_unavailable' => $unavailable, 'unavailable_reasons' => $unavailableReasons, 'bio_url_seeded' => $bioUrlSeeded] + $tally;
    }

    /**
     * Why a page could not be read — so "the host refuses us" stops looking
     * identical to "the page is gone".
     *
     * The old log said only `pages_unavailable: 1`, which cannot tell a bot
     * block from a 404 or a dead domain. That mattered: four detector hosts
     * (bio.link, heylink.me, direct.me, beacons.ai) serve a Cloudflare refusal
     * at the EDGE, so nothing downstream — not an API seam, not a better
     * parser — can reach them, and we had no way to notice it was happening.
     *
     * `bot_challenge` covers both of Cloudflare's shapes: the managed JS
     * challenge ("Just a moment…") and the hard WAF block ("Attention
     * Required!"). Both arrive as 403 AFTER SafeUrlFetcher has already retried
     * with its honest-bot UA, so reaching here means both attempts were
     * refused and the User-Agent was not the reason.
     *
     * @param  array{status:int, body:string}|null  $response
     */
    private function unavailableReason(?array $response): string
    {
        // No response at all: DNS failure, SSRF refusal, or transport error.
        if ($response === null) {
            return 'unreachable';
        }

        $status = $response['status'];
        $body = $response['body'];

        if (in_array($status, [401, 403, 503], true)) {
            foreach (self::CHALLENGE_MARKERS as $marker) {
                if (str_contains($body, $marker)) {
                    return 'bot_challenge';
                }
            }

            return 'refused';
        }

        return match (true) {
            $status === 404 || $status === 410 => 'not_found',
            $status >= 500 => 'server_error',
            // A 200 that reaches here had an empty body — the shell served
            // nothing at all, which is not the same as being turned away.
            $status === 200 => 'empty_body',
            default => 'http_'.$status,
        };
    }

    /**
     * SCALE-5: space out successive fetches at ONE bio-link host inside a
     * single import. Never before the first page, never after the last —
     * skipped entirely on a single-page import, which is the common case.
     *
     * Gated on `runningInConsole()` (the same idiom DefersRecompute uses):
     * the only caller, LinkInBioScanJob, is a queued job, but the `sync`
     * queue driver runs a job inline in the web request — a sleep on that
     * path would be a worse bug than the WAF block it exists to prevent.
     */
    private function paceNextFetch(int $index, int $pageCount, int $delayMs): void
    {
        if ($delayMs <= 0 || $index >= $pageCount - 1 || ! app()->runningInConsole()) {
            return;
        }

        Sleep::for($delayMs)->milliseconds();
    }

    /**
     * @param  array{connected:int, suggested:int, noted:int, items:int, probed:int, dropped:int, folded:int, skipped_chrome:int}  $tally
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $probedHosts
     * @param  array<string, string>  $placedKeys  surface:identifier => first canonical URL
     * @param  array<string, int>  $droppedReasons
     */
    /**
     * A "share this page" button, identified by the page's own URL appearing in
     * the candidate's query string.
     *
     * Keyed on the page URL rather than a host or path list because the seven
     * on one Lnk.Bio page agree on nothing else: facebook.com/sharer.php?u=,
     * wa.me/?text=, twitter.com/intent/tweet?text=,
     * social-plugins.line.me/lineit/share?url=, story.kakao.com/share?url=,
     * reddit.com/submit?url=, linkedin.com/sharing/share-offsite/?url=. Three
     * different path shapes, two hosts with no path at all, and five hosts the
     * harvester has no rule for — but every one of them carries the page it
     * shares. Matching on both the raw and encoded form covers either style.
     */
    private function isShareWidget(string $url, string $baseUrl): bool
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '' || preg_match_all('~https?://[^\s&"\']+~i', urldecode($query), $m) === 0) {
            return false;
        }

        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        foreach ($m[0] as $embedded) {
            $host = strtolower((string) parse_url($embedded, PHP_URL_HOST));
            if ($host !== '' && ($host === $ownHost || $this->bioDetector->matches($embedded))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop any custom card standing for a page this run just unrolled.
     *
     * Compares scheme-/www-/trailing-slash-insensitively, the same $pageKey
     * idiom InstagramAutoSync dedupes bio links with — seedCustom() stores a
     * canonicalised form, so an exact string match would miss its own card.
     * LinkPoolReader::remove() discharges all three cache lanes.
     *
     * @param  list<string>  $unrolled  pages that actually yielded, not every page in the run
     */
    private function retireFloorCards(User $user, array $unrolled): void
    {
        $keys = [];
        foreach ($unrolled as $page) {
            $keys[$this->pageKey($page)] = true;
        }

        // cardsForSite(), never cards(): the latter PROVISIONS the pool section
        // as a side-effect of reading, which would make this success path
        // require site.sections where it never did before (it turned
        // TombstoneResurrectionTest red). No section means no cards means
        // nothing to retire — the honest answer.
        foreach ($this->linkReader->cardsForSite($user->site) as $card) {
            $url = $card['url'] ?? null;
            if (is_string($url) && isset($keys[$this->pageKey($url)])) {
                $this->linkReader->remove($user, $card['id']);
            }
        }
    }

    /**
     * Fill-if-empty: an owner-set code is never overwritten. Matched by the
     * store's host so a tile url with a path still finds the storefront.
     */
    private function adoptDiscountCode(User $user, string $url, string $code): void
    {
        $host = strtolower((string) preg_replace('~^www\.~', '', (string) parse_url($url, PHP_URL_HOST)));
        if ($host === '') {
            return;
        }
        try {
            $n = DB::connection('pgsql')->table('content.storefronts')
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('discount_code')->orWhere('discount_code', ''))
                ->where(fn ($q) => $q->where('url', 'like', '%'.$host.'%')->orWhere('source_url', 'like', '%'.$host.'%'))
                ->update(['discount_code' => $code, 'updated_at' => now()]);
            Log::info('link_in_bio.discount_code_adopted', ['user_id' => (string) $user->id, 'host' => $host, 'code' => $code, 'rows' => $n]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function pageKey(string $url): string
    {
        return strtolower(rtrim(preg_replace('~^https?://(?:www\.)?~i', '', $url) ?? $url, '/'));
    }

    private function unroll(string $baseUrl, string $body, RoutingContext $context, array &$tally, array &$seen, array &$probedHosts, array &$placedKeys, array &$droppedReasons): void
    {
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        // Some aggregators ship no anchors at all — linkin.bio, taplink.cc and
        // stan.store deliver empty SPA shells, so the harvest below reads zero
        // links off a page that has eight (N2). Two seams answer them: an API
        // read for those three, and an inline-payload parse for liinks.co,
        // whose links are already in the body we just fetched. Both answer null
        // for any host they cannot read — INCLUDING when the API is down — so
        // the anchor pass stays the default and the floor backstops all four.
        $apiLinks = $this->api->unroll($baseUrl)
            ?? $this->inline->read($baseUrl, $body);

        // Item 8 G3 (2026-09-01, owner-approved), generalized to five services
        // by Item 10b: ScrapeCreators fronts the Linktree case — trial-verified
        // exact parity with the __NEXT_DATA__ parse (same 3 links on the
        // recorded ryanfitzsimons page), with the shell-rev fragility
        // transferred to the vendor — and now Komi/Pillar/Lnk.Bio/Linkme ride
        // the same lane off their own recorded payloads. Vendor links lead the
        // list (page order, so connection slots resolve as today), and the
        // inline parse's own answer is UNIONED in behind them rather than
        // discarded: /v1/linktree carries only the tile list, never the
        // socialLinks icon row, and T24/issue 19 (benbohmer/memphislk) proved
        // icon-row socials are unrecoverable from anchors — replacing the
        // parser outright would make them absent, which the lane contract
        // forbids. Dupes fold in $seen below. On ANY vendor miss — no key,
        // budget denied, non-2xx, transport, husk — $vendorLinks is null and
        // this whole block is a no-op: the existing parse runs unchanged.
        $vendorLinks = $this->vendorBioLinks($baseUrl, $context);
        if ($vendorLinks !== null) {
            $apiLinks = array_values(array_unique([...$vendorLinks, ...($apiLinks ?? [])]));
        }

        // The vendor-rescue path arrives with NO body at all (the fetch was
        // refused), and DOMDocument::loadHTML refuses an empty string — the
        // harvester has nothing to read either way.
        $links = $apiLinks ?? ($body === '' ? [] : $this->harvester->allOutboundLinks($body, $baseUrl));

        // F12 (2026-08-20, the natalieannehair stan.store trace): the API and
        // inline unrollers return the platform's TILE links and never see the
        // shell's own footer SOCIAL anchors — her TikTok and Facebook sat in
        // the delivered DOM, unseen. Merge just the SOCIAL-classified anchors
        // back in: tiles stay API-authoritative and APPENDED-to (never
        // replaced), and the shell's legal/asset links (assets.stanwith.me
        // PDFs…) can't classify as social, so no junk rides along. Gated on
        // the API/inline arm answering — when the anchor arm already produced
        // $links this would be a second identical parse for zero new URLs
        // ($seen would fold every one). classify() is grammar+catalog only,
        // no fetches. NB none of the four CURRENT API hosts exercises this:
        // their raw shells ship no social anchors at all (stan's live in its
        // __NUXT__ blob and ride the API instead — see
        // LinkInBioApiUnroller::stanStore). This merge is insurance for a
        // future API/inline host whose shell DOES server-render socials —
        // the natalieannehair miss showed how silently that class of gap
        // hides. Known, accepted exposure: a
        // shell rendering the AGGREGATOR's own social badges would sweep
        // them in, exactly as every anchor-harvested host always has.
        // Socials append AFTER tiles, so MAX_LINKS starvation is only
        // conceivable in multi-page batches.
        if ($apiLinks !== null && $body !== '') {
            $anchorSocials = array_values(array_filter(
                $this->harvester->allOutboundLinks($body, $baseUrl),
                fn (string $link): bool => ($this->harvester->classify($link)['category'] ?? null) === 'social',
            ));
            $links = array_merge($links, $anchorSocials);
        }

        foreach ($links as $url) {
            if (count($seen) >= self::MAX_LINKS) {
                return;
            }

            // The chrome rule. Same host as the bio page itself = the
            // platform's own navigation, not the user's link.
            if ($ownHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                $tally['skipped_chrome']++;

                continue;
            }

            // Same-host is not enough for a vendor that serves one page on two
            // hostnames. Lnk.Bio renders at clk.bio but puts its navbar brand
            // and its "Get Lnk.Bio" referral link on lnk.bio, so the rule above
            // saw a third-party domain and carded the vendor's SIGNUP link onto
            // the owner's page (measured 2026-08-24, the clk.bio fixture).
            // An aggregator host inside an aggregator page is furniture.
            if ($this->bioDetector->matches($url)) {
                $tally['skipped_chrome']++;

                continue;
            }

            // A share widget carries THIS page's URL in its own query string —
            // "post my Lnk.Bio to Facebook" is the page's furniture, not a link
            // the owner published. Seven of them on the clk.bio page (facebook,
            // whatsapp, twitter, line, kakao, reddit, linkedin) each seeded a
            // junk card. Keyed on the page URL rather than a host or path list
            // because line.me/lineit/share and wa.me/?text= share neither.
            if ($this->isShareWidget($url, $baseUrl)) {
                $tally['skipped_chrome']++;

                continue;
            }

            $fingerprint = strtolower(trim($url));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            // FI-9 (T4 live, 2026-08-20): expand HERE, not only inside
            // route(), so every downstream consumer sees the destination —
            // before this, route() expanded internally but handleUnrouted's
            // probe dispatch and card fallback still carried the SHORT url:
            // a tinyurl'd course page was probed as tinyurl.com (reject:
            // shortener → probe wasted) and carded as "tinyurl.com" while
            // its expansion routed separately. Cached, so route()'s own
            // expansion of the same URL is a cache hit, not a second fetch.
            $url = $this->expander->expandIfShort($url);

            $result = $this->routing->route($url, $context);

            match ($result['verdict']) {
                'place' => $this->handlePlaced($url, $result, $context, $tally, $placedKeys),
                'choose', 'hold' => $tally['suggested']++,
                default => $this->handleUnrouted($url, $result, $context, $tally, $probedHosts, $droppedReasons, $placedKeys),
            };
        }
    }

    /**
     * Which VENDOR_SERVICES entry reads this page's host — null for every
     * host the vendor has no endpoint for, so the rest of the catalogue is
     * untouched by the lane. Subdomain matching is the LinkInBioDetector
     * idiom (and load-bearing here: Komi pages are <user>.komi.io).
     */
    private function vendorService(string $pageUrl): ?string
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        foreach (self::VENDOR_SERVICES as $service => $config) {
            foreach ($config['hosts'] as $known) {
                if ($host === $known || str_ends_with($host, '.'.$known)) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * The vendor lane for the five services ScrapeCreators reads,
     * contract-lossy by design (the InstagramScraper::vendorProfileFetch
     * pattern): the owner's outbound URLs in page order, or null — never a
     * failure classification. One budget source ('linkinbio') covers the
     * whole lane — a page is one claim regardless of which service reads it.
     * Claimed before the HTTP call and released on a transport-level null; a
     * billed husk keeps its slot spent.
     *
     * @return list<string>|null
     */
    private function vendorBioLinks(string $pageUrl, RoutingContext $context): ?array
    {
        $service = $this->vendorService($pageUrl);
        if ($service === null) {
            return null;
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim('linkinbio')) {
            return null;
        }

        // The clk.bio mirror rewrite (see VENDOR_SERVICES). Scheme and path
        // survive; only the host moves onto the one the vendor documents.
        $requestUrl = preg_replace('~^(https?://)(?:www\.)?clk\.bio~i', '${1}lnk.bio', $pageUrl) ?? $pageUrl;

        $userId = $context->user === null ? null : (string) $context->user->id;
        $body = $client->get(self::VENDOR_SERVICES[$service]['path'], ['url' => $requestUrl], $userId);
        if ($body === null) {
            $budget->release('linkinbio');

            return null;
        }

        $normalizer = app(self::VENDOR_SERVICES[$service]['normalizer']);
        $page = $normalizer->normalize($body);
        if ($page === null) {
            // The call was made and billed upstream even when the shape is a
            // husk — the slot stays spent; only transport-level nulls release.
            Log::info('scrapecreators.linkinbio.unusable_shape', [
                'service' => $service,
                'user_id' => $userId,
                'import_run_id' => $context->importRunId,
            ]);

            return null;
        }

        foreach ($page['links'] as $row) {
            $title = $row['title'] ?? '';
            if ($title !== '') {
                $this->vendorTitles[strtolower(trim($row['url']))] = $title;
            }
        }
        foreach ($page['contacts'] ?? [] as $contact) {
            $this->vendorContacts[] = $contact;
        }

        return $normalizer->urls($page);
    }

    /**
     * First link per surface+identifier wins the connection; a LATER distinct
     * URL for the same one becomes a card instead of a silent refresh. A
     * creator's "book me" venue link and their "see my services" deep link on
     * the same venue are two links the user published — the reconciler would
     * fold the second into the first connection and its distinct URL would
     * vanish, which is the "nothing vanishes" promise broken (the legacy
     * router's Issue-M rule, carried).
     *
     * @param  array<string, mixed>  $result
     * @param  array{connected:int, suggested:int, noted:int, items:int, probed:int, dropped:int, folded:int, skipped_chrome:int}  $tally
     * @param  array<string, string>  $placedKeys  surface:identifier => first canonical URL
     */
    private function handlePlaced(string $url, array $result, RoutingContext $context, array &$tally, array &$placedKeys): void
    {
        $routed = $result['routedTo'] ?? null;
        $key = is_array($routed)
            ? ($routed['surfaceKey'] ?? '').':'.($routed['identifier'] ?? '')
            : ':';

        // "Distinct" means a distinct CANONICAL, not a distinct raw string
        // (T1.5g round 3, 2026-08-20): the same Spotify artist arrived twice
        // in one run — the __NEXT_DATA__ tile and the shell's own anchor,
        // differing only in tracking params — and the raw-string dedupe let
        // the second one through to a "Spotify – Web Player" card duplicating
        // the connection just made. Same URL is a silent fold; only a
        // genuinely different page for the same account still cards.
        // '://www.' folds too (FI-11, T5 live): a Linktree tile said
        // www.instagram.com/<handle> while its socialLinks row said
        // instagram.com/<handle> — one page, two canonicals, and the second
        // became an "Instagram" card beside the fold.
        $canonical = str_replace('://www.', '://', strtolower(trim((string) ($result['canonicalUrl'] ?? $url))));

        if (is_array($routed) && $context->user !== null) {
            // The setup feed's platforms row reads this (2026-09-02).
            $tally['placed_platforms'][] = (string) ($routed['brandKey'] ?? '');
            // A store tile whose TITLE carries a code ("Gamma+ - CODE: TEEGAN10")
            // gives that store its discount code, fill-if-empty (owner,
            // 2026-09-02); the storefront row is minted inside the placement.
            if (($routed['routingClass'] ?? null) === 'shop') {
                $title = $this->vendorTitles[strtolower(trim($url))] ?? null;
                $code = is_string($title) ? DiscountCodeSniffer::sniff($title) : null;
                if ($code !== null) {
                    $this->adoptDiscountCode($context->user, $url, $code);
                }
            }
        }

        if (isset($placedKeys[$key]) && $context->user !== null) {
            if ($placedKeys[$key] === $canonical) {
                // Its OWN bucket, not 'noted' (critic pass 2): 'noted' claims
                // a card exists — the exact lie #R2 fixed — and a
                // same-canonical fold writes nothing, deliberately.
                $tally['folded']++;

                return;
            }
            $tally['noted']++;
            $this->seeder->seedCustom($context->user, $url);

            return;
        }

        $placedKeys[$key] = $canonical;
        $tally['connected']++;
    }

    /**
     * A link the router did not connect still belongs to the user. Unknown
     * domains get the legacy commerce probe (the new pipeline's probe set is
     * still 1 of 5 — P8 blocker 1), which seeds a product, a store, or its
     * own custom-link fallback; everything else is carded here, because Note
     * cards are caller-owned on this pipeline (RoutingController does the
     * same). Past the probe budget, unknowns are carded directly — a starved
     * link must land somewhere visible, never vanish.
     *
     * One probe per unknown HOST per run, not per URL — five sub-pages of one
     * website are one storefront question, and per-URL probing let a single
     * site's nav exhaust the whole budget (found live 2026-08-10).
     *
     * @param  array<string, mixed>  $result
     * @param  array{connected:int, suggested:int, noted:int, items:int, probed:int, dropped:int, folded:int, skipped_chrome:int}  $tally
     * @param  array<string, true>  $probedHosts
     * @param  array<string, int>  $droppedReasons  reason => count, for the run detail
     */
    private function handleUnrouted(string $url, array $result, RoutingContext $context, array &$tally, array &$probedHosts, array &$droppedReasons, array &$placedKeys): void
    {
        // A pre-account build has no user, so nothing can be carded or probed;
        // the Note is counted where a claimed account would have seeded a
        // card. A REJECT falls through to the drop trace below either way —
        // an unclaimed site loses links as silently as a claimed one did.
        if ($context->user === null && $result['verdict'] === 'note') {
            $tally['noted']++;

            return;
        }

        if ($result['verdict'] === 'note') {
            $classified = $this->harvester->classify($url);

            // T6 (2026-08-20): media ITEMS — a video/track/release/episode
            // URL becomes a real watch/listen pool item (library, never
            // auto-pinned), the media twin of the events arm below. The
            // grammar is MediaPageReader's own (shared via classify), so the
            // scan lane and the paste lane can never disagree about what an
            // item is. A failed read or a tombstoned item cards the link —
            // nothing vanishes. Spends NO commerce budget.
            if (($classified['category'] ?? null) === 'content-item') {
                try {
                    $seeded = $this->media->seedItem($context->user, $url, origin: $context->origin);
                } catch (\Throwable $e) {
                    report($e);
                    $seeded = null;
                }

                if ($seeded !== null) {
                    $tally['items']++;
                } else {
                    $tally['noted']++;
                    $this->seeder->seedCustom($context->user, $url);
                }

                return;
            }

            // Standalone EVENT pages (an Eventbrite /e/… link, a Humanitix
            // event) carry a Note-strength detector at best — never a
            // placement — so they land here. The legacy classifier still
            // knows their shapes; seed them INLINE through EventsSeeder.
            // A single event is an ITEM, not a platform: this path writes
            // the pool item only (withConnectionRow: false — same write as
            // the interactive addEvent verb). It records no payload finding,
            // so nothing would have read a connection row anyway
            // (EventSyncFindingsTest pins the modal contract). An ORGANISER
            // link is a real account and does connect. Spends NO commerce
            // budget: events were never probes. A seeder failure cards the
            // link, never drops it.
            if (in_array($classified['category'] ?? null, ['event', 'event-organiser'], true)) {
                try {
                    $seeded = $classified['category'] === 'event'
                        ? $this->events->seedStandalone($context->user, $classified['platform'], $url, origin: $context->origin)
                        : $this->events->seedAccount($context->user, $classified['platform'], $url);
                } catch (\Throwable $e) {
                    report($e);
                    $seeded = null;
                }

                if ($seeded !== null) {
                    $tally['connected']++;
                } else {
                    $tally['noted']++;
                    $this->seeder->seedCustom($context->user, $url);
                }

                return;
            }
        }

        // 'unknown-domain' ONLY — a registrable key the catalog has never
        // heard of, where a probe answers a real question (product? store?).
        // 'no-rule-matched' is a KNOWN brand's URL in a shape no detector
        // claims (a Fresha /services deep link, a YouTube watch URL): probing
        // a brand we already recognise wastes a budget slot to rediscover it,
        // so those go straight to a card — the legacy classified-host
        // behaviour, carried.
        $unknown = $result['verdict'] === 'note'
            && ($result['reason'] ?? null) === 'unknown-domain';

        if ($unknown) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            // Budget = distinct probed hosts (one probe per host by
            // construction), so the event dispatches above never eat a slot.
            if (! isset($probedHosts[$host]) && count($probedHosts) < self::MAX_PROBES) {
                $probedHosts[$host] = true;
                // redactUrl(), not minimiseUrl(): the job FETCHES this URL, so
                // tracking params must survive. A miss falls through to
                // seedCustom() (a PUBLIC link card) and a failed job persists
                // the whole payload in public.failed_jobs — either way an
                // unredacted secret must not reach this dispatch (#SEC-7).
                CommerceProbeJob::dispatch((string) $context->user->id, SecretParams::redactUrl($url) ?? '');
                $tally['probed']++;

                return;
            }
        }

        if ($result['verdict'] === 'note') {
            // A KNOWN brand's URL that only a query string kept from its
            // detector (youtube.com/@handle?sub_confirmation=1 — jordan,
            // 2026-09-02): route the bare URL once before carding it.
            if (($result['reason'] ?? null) === 'no-rule-matched') {
                $bare = (string) preg_replace('~[?#].*$~', '', $url);
                if ($bare !== $url && $bare !== '') {
                    $again = $this->routing->route($bare, $context);
                    if (($again['verdict'] ?? null) === 'place') {
                        $this->handlePlaced($bare, $again, $context, $tally, $placedKeys);

                        return;
                    }
                }
            }
            // A page of a platform the person already has connected is not
            // a link card beside the connection — fold it (2026-09-02).
            $slug = is_array($classified ?? null) ? $classified['platform'] : '';
            if ($slug !== ''
                && IntegrationConnection::query()->where('user_id', $context->user->id)->where('platform', $slug)->whereNull('deleted_at')->exists()) {
                $tally['folded']++;

                return;
            }
            $tally['noted']++;
            $this->seeder->seedCustom($context->user, $url);

            return;
        }

        // L-3 + FI-3 (2026-08-20): a shortener reject here is a real link the
        // user published — a NESTED aggregator (their "other" Linktree; depth
        // stays 1, no recursive unroll) or a short link whose expansion
        // failed (dead bit.ly, unreachable on.soundcloud.com). Both used to
        // silently drop; zero-loss means they become cards, exactly like a
        // Note. Tombstoned/malformed/own-infra rejects still drop below.
        if (($result['blockReason'] ?? null) === 'shortener') {
            $tally['noted']++;
            $this->seeder->seedCustom($context->user, $url);

            return;
        }

        // 'reject' is carded nowhere, deliberately: unroutable by the
        // canonicaliser (own-infra, malformed, confusable host) or refused by
        // policy (tombstoned — resurrecting it would break C8). But a dropped
        // link is still a link the user published, and until 2026-08-18 it
        // left NO trace anywhere: it was counted 'noted' (which claims a card
        // that does not exist), never logged, and absent from the run detail.
        // A canva.link page vanished off a live site that way and only the
        // observation row proved it had ever been there (#R2). Counted and
        // logged separately now — the ledger says dropped when it means it.
        $tally['dropped']++;
        $reason = (string) ($result['blockReason'] ?? $result['reason'] ?? 'unroutable');
        $droppedReasons[$reason] = ($droppedReasons[$reason] ?? 0) + 1;

        Log::warning('routing.link_dropped', [
            'user_id' => $context->user === null ? null : (string) $context->user->id,
            'import_run_id' => $context->importRunId,
            'reason' => $reason,
            // minimiseUrl, not the raw URL: a bio link is Scope B PII and this
            // line goes to the shared log stream (#PRIV-5).
            'url' => SecretParams::minimiseUrl($url),
        ]);
    }

    /**
     * Defensive on purpose: the public signature promises list<string>, but a
     * bio harvest builds its list from scraped text, and one null in it must
     * not become an empty fetch.
     *
     * @param  string|array<array-key, mixed>  $urls
     * @return list<string>
     */
    private function normalisePages(string|array $urls): array
    {
        $pages = [];

        foreach ((array) $urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $url = trim($url);
            if ($url === '' || isset($pages[strtolower($url)])) {
                continue;
            }

            $pages[strtolower($url)] = $url;
        }

        return array_slice(array_values($pages), 0, self::MAX_PAGES);
    }
}
