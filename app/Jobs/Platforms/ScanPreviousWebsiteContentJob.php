<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Routing\Importers\WebsiteImporter;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Design\DesignKitAutopilot;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Http\MetadataParser;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\LinkInBioDetector;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\PreAccount\BuildProgress;
use App\Services\WebsiteScan\AboutProseExtractor;
use App\Services\WebsiteScan\AboutTextExtractor;
use App\Services\WebsiteScan\ContactEmailExtractor;
use App\Services\WebsiteScan\FaviconFetcher;
use App\Services\WebsiteScan\MenuTextExtractor;
use App\Services\WebsiteScan\PdfLinkDetector;
use App\Services\WebsiteScan\SquarespaceMenuExtractor;
use App\Services\WebsiteScan\VisibleTextExtractor;
use App\Services\WebsiteScan\WebsiteAccentExtractor;
use App\Services\WebsiteScan\WebsiteLogoCandidateExtractor;
use App\Services\WebsiteScan\WorkplaceContentApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Single entry point for everything that happens when a user's
// previous_website is set/changed: logo candidates + accent colour + font
// (business accounts only — done FIRST, see the docblock at the top of
// handle()), about text (JSON-LD/meta + a richer heading-prose heuristic),
// contact email, menu (HTML + PDF), and general link-harvesting — one plain
// fetch, no headless render, no separate "design scan" job. Independent of
// (and, since Part B, the sole replacement for) the old headless-browser
// website-style-analysis pipeline. Gallery/content-photo grabbing from a
// previous website was dropped from this job entirely (owner, 2026-09-05) —
// logos only.
//
// Consciously-accepted side effect: GoogleBusinessAutoSync::seed()'s social
// branch, when it finds an Instagram link among the harvested links,
// dispatches its own real, budget-metered Apify scrape of that Instagram
// profile. Calling seed() wholesale here means ANY account with a
// previous_website — not only accounts that connected an actual Google
// Business Profile — can trigger that paid Instagram scrape, if their
// website happens to link to Instagram. Accepted: this is exactly the kind
// of gap-fill this job exists for, the call is already budget-capped at the
// seed() level, and the alternative (reinventing a partial, ungated copy of
// seed()'s social handling to exclude this one branch) reintroduces the
// capability-bypass bug class this job's own design specifically avoids by
// calling the real seed() wholesale instead of its private sub-methods. NOTE
// for future readers: seedInstagram() (GoogleBusinessAutoSync) guards this
// with an existing-connection has() check BEFORE any budget claim — a
// second run of this job finds that placeholder and short-circuits to a
// (harmless, if confusing) conflict finding rather than a second charge. Do
// not "simplify away" that has() guard; it is the only thing standing
// between a re-run and a second Apify bill on this path.
//
// NOT auto-retried ($tries = 1, below): a second handle() re-dispatches
// WebsiteMenuPdfScanJob (Mistral OCR) and WebsiteMenuHtmlScanJob
// (MenuAiExtractor structuring), both billed and both deliberately
// $tries = 1 themselves specifically to prevent an automatic re-bill. This
// job retrying itself was silently defeating that policy — ShouldBeUnique's
// 300s lock blocks a second concurrent *dispatch*, not a second *attempt* of
// the same instance, so it never protected against this.
class ScanPreviousWebsiteContentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // AI spend downstream (see class docblock): a retry re-dispatches billed
    // OCR/AI sub-jobs whose own tries = 1 exists to prevent exactly that.
    // RunSourceJob precedent: "a fetch is not safely re-runnable by the
    // queue... could double-charge a billed effect".
    public int $tries = 1;

    /** @var list<int> Moot at one attempt; declared for the job-hygiene policy (JobHygienePolicyTest), matching WebsiteMenuPdfScanJob's idiom. */
    public array $backoff = [30];

    public int $timeout = 60;

    // A mid-scan timeout must fail terminally, not retry the fan-out above.
    // Plain declared property with a declared default (not promoted, not
    // readonly) so a job enqueued before this property existed restores with
    // the default instead of fatalling on read — matches
    // GeneratePreAccountSiteJob's $failOnTimeout.
    public bool $failOnTimeout = true;

    // Matches EnrichLinkCardJob (same directory, same 60s timeout): 300s comfortably
    // exceeds the run itself, leaving queue-wait budget. No default means UniqueLock
    // falls back to `?? 0` and RedisLock treats 0 as "no expiry" (plain SETNX) — a
    // worker killed mid-job (OOM, deploy, timeout) would strand that lock forever.
    public int $uniqueFor = 300;

    /** Safety cap on how many PDFs get their own scan job from one page — not a "pick one" limit. */
    private const MAX_PDF_SCANS = 12;

    /** Checked against a PDF link's own text AND its URL path — either counts. */
    private const MENU_KEYWORDS = [
        'menu', 'wine', 'drink', 'food', 'beverage', 'dessert',
        'breakfast', 'lunch', 'dinner', 'cocktail', 'brunch',
    ];

    /** A page must clear this length before an AI structuring call is worth spending. */
    private const MIN_DENSE_TEXT_CHARS = 200;

    /** ...and carry at least this many price-shaped lines (VisibleTextExtractor puts each block on its own line, so a real price column reliably shows up as lines that are JUST a number). */
    private const MIN_PRICE_LINES = 3;

    public function __construct(
        public readonly string $userId,
        public readonly string $siteId,
        public readonly string $url,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':website-content-scan';
    }

    public function handle(
        SafeUrlFetcher $fetcher,
        WebsiteLinkHarvester $harvester,
        AboutTextExtractor $about,
        AboutProseExtractor $aboutProse,
        ContactEmailExtractor $contactEmailExtractor,
        MenuTextExtractor $menuText,
        PdfLinkDetector $pdfLinks,
        WorkplaceContentApplier $contentApplier,
        MenuScanApplier $menuApplier,
        GoogleBusinessAutoSync $googleBusinessAutoSync,
        FaviconFetcher $faviconFetcher,
        WebsiteAccentExtractor $accentExtractor,
        WebsiteLogoCandidateExtractor $logoCandidateExtractor,
        LogoAutoGrabber $logoAutoGrabber,
        MetadataParser $metadataParser,
        SquarespaceMenuExtractor $squarespaceExtractor,
        VisibleTextExtractor $visibleTextExtractor,
    ): void {
        $user = User::find($this->userId);
        $site = Site::find($this->siteId);
        if ($user === null || $site === null) {
            return;
        }
        // A link-in-bio page is not a website to scan (no about text, no logo,
        // no menu) — it is a bundle of links to unroll (overnight F15).
        if (app(LinkInBioDetector::class)->matches($this->url)) {
            LinkInBioScanJob::dispatch($this->userId, $this->url);

            return;
        }

        $response = $fetcher->tryFetch($this->url);
        $html = is_array($response) && $response['status'] === 200 ? (string) $response['body'] : '';
        if ($html === '') {
            // OBS-4: breadcrumb only, deliberately — a one-off fetch failure
            // is routine internet weather. The observed status distinguishes
            // a permanently-blocking WAF/403 from a blip; that pattern is a
            // log query's job, not an exception's (Nightwatch alerts on
            // exceptions, not log queries).
            Log::warning('platforms.previous_website_scan_empty', [
                'user_id' => $this->userId,
                'site_id' => $this->siteId,
                'url' => $this->url,
                'status' => is_array($response) ? $response['status'] : null,
            ]);

            return;
        }
        $baseUrl = $response['finalUrl'] ?? $this->url;

        // Design evidence (logo/accent/font) runs FIRST, right after the
        // fetch, off only $html/$baseUrl already in hand — no further network
        // call needed to reach it. Moved here 2026-09-05: it used to run LAST,
        // after menu OCR dispatch, general link-harvesting
        // (GoogleBusinessAutoSync::seed()) and WebsiteImporter's own network
        // calls — several more round trips a worker torn down mid-job
        // (Laravel Cloud hibernation, suspected live on St Ali/squeakprobarber
        // retests) could lose before ever reaching the logo. The highest-
        // value, fastest part of this job now runs while the worker is most
        // likely still alive, and answers the 'website' stage itself instead
        // of leaning on the gallery scan's terminal note to close it out —
        // gallery/content-photo grabbing is dropped from this job entirely
        // (owner, 2026-09-05): logos only from here on.
        //
        // Only makes sense when the workplace's brand IS the site's identity
        // (a business) — a partna account's workplace website is someone
        // else's brand (owner, 2026-08-19): its logo must never become the
        // site's mark and its colours must never become the site's accent.
        if (AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            $favicon = $faviconFetcher->fetch($html, $baseUrl);
            $themeColor = $accentExtractor->themeColorFromHtml($html);
            $faviconColor = isset($favicon['bytes']) ? $accentExtractor->dominantColorFromImage($favicon['bytes']) : null;

            $logoCandidates = $logoCandidateExtractor->extract($html, $baseUrl);
            if ($favicon !== null) {
                $logoCandidates[] = ['kind' => 'icon', 'url' => $favicon['url'], 'sizes' => '', 'type' => ''];
            }

            $logoDecisions = [];
            if ($logoCandidates !== []) {
                // The decisions array is the grabber's per-candidate audit trail
                // (LogoAutoGrabber's docblock expects the caller to keep it) —
                // logged, not persisted: "why didn't my logo get picked up" is a
                // support question answered from logs, not a product surface.
                $logoDecisions = $logoAutoGrabber->grabIfEmpty($user, $site, $logoCandidates);
                Log::info('website_scan.logo_grab', [
                    'user_id' => $this->userId,
                    'site_id' => $this->siteId,
                    'decisions' => $logoDecisions,
                ]);
            }

            $logoFound = count(array_filter($logoDecisions, static fn (array $d) => ! str_starts_with($d['outcome'], 'rejected:')));
            // Setup progress (2026-09-05): the website stage started at
            // dispatch (WorkplaceObserver::dispatchContentScan) and is owed an
            // answer by this job — written at the END of handle(), not here
            // (st_ali retest #3): the importer below is what routes the
            // site's socials, and closing the stage at the logo let the walk
            // release its platforms loader two seconds before "YouTube
            // synced" landed.
            $websiteNote = [
                $logoFound > 0 ? PreAccountBuildEvent::STATUS_LANDED : PreAccountBuildEvent::STATUS_SKIPPED,
                $logoFound > 0 ? 'Found your logo' : 'No logo found on your website',
            ];

            // Accent resolution — one immediate pass for the tiers already in
            // hand (theme-color/favicon). SiteMediaObserver chains a second
            // pass once a logo asset reaches READY with a dominant colour.
            ResolveSiteAccentJob::dispatch($this->siteId, $themeColor, $faviconColor);

            // §13's second half of website evidence: the font keyword
            // classifier. Same $html as above (no extra fetch), same
            // fill-if-empty discipline as the accent — a font the user (or an
            // earlier scan) already chose is never touched.
            $autopilot = app(DesignKitAutopilot::class);
            $autopilot->persistFillIfEmpty($this->siteId, $autopilot->fromWebsiteEvidence($html)['proposals']);
        } else {
            Log::info('website_scan.design_evidence_skipped', [
                'user_id' => $this->userId,
                'site_id' => $this->siteId,
                'reason' => 'workplace_brand_is_site_identity=false',
            ]);
            $websiteNote = [PreAccountBuildEvent::STATUS_SKIPPED, 'Looked at your website'];
        }

        // About text — plain JSON-LD/meta fill-if-empty first, then the
        // richer heading-prose heuristic (preferred when found — see
        // WorkplaceContentApplier::applyProseDescription()'s precedence).
        // Contact email — mailto: + JSON-LD, homepage first.
        $workplace = Workplace::firstOrNew(['site_id' => $this->siteId]);
        // site_id is not mass-assignable (#SEC-17) — the new-row branch needs it set explicitly.
        $workplace->site_id = $this->siteId;
        $contentApplier->applyDescription($workplace, $about->extract($html, $baseUrl));
        $proseText = $aboutProse->extract($html);
        $email = $contactEmailExtractor->extract($html, $baseUrl);

        // One-hop /about + /contact — only for whichever came up empty on the
        // homepage, fetched CONCURRENTLY (stacking sequential one-hop fetches
        // risks this job's 60s timeout).
        if ($proseText === null || $email === null) {
            $outboundLinks = $harvester->allOutboundLinks($html, $baseUrl);
            $hopUrls = [];
            if ($proseText === null) {
                $aboutUrl = $this->findPageLink($outboundLinks, $baseUrl, 'about');
                if ($aboutUrl !== null) {
                    $hopUrls['about'] = $aboutUrl;
                }
            }
            if ($email === null) {
                $contactUrl = $this->findPageLink($outboundLinks, $baseUrl, 'contact');
                if ($contactUrl !== null) {
                    $hopUrls['contact'] = $contactUrl;
                }
            }

            if ($hopUrls !== []) {
                $hopResponses = $fetcher->fetchMany(array_values($hopUrls));

                if ($proseText === null && isset($hopUrls['about'])) {
                    $aboutResponse = $hopResponses[$hopUrls['about']] ?? null;
                    $aboutHtml = is_array($aboutResponse) && $aboutResponse['status'] === 200
                        ? (string) $aboutResponse['body'] : '';
                    if ($aboutHtml !== '') {
                        $proseText = $aboutProse->extract($aboutHtml);
                    }
                }

                if ($email === null && isset($hopUrls['contact'])) {
                    $contactResponse = $hopResponses[$hopUrls['contact']] ?? null;
                    $contactHtml = is_array($contactResponse) && $contactResponse['status'] === 200
                        ? (string) $contactResponse['body'] : '';
                    if ($contactHtml !== '') {
                        $contactBaseUrl = $contactResponse['finalUrl'] ?? $hopUrls['contact'];
                        $email = $contactEmailExtractor->extract($contactHtml, $contactBaseUrl);
                    }
                }
            }
        }

        $contentApplier->applyProseDescription($workplace, $proseText);
        $contentApplier->applyContactEmail($workplace, $email);

        // Menu (HTML) — food-Business only.
        if (AccountCapabilities::for($user)->can_use_menu) {
            $items = $menuText->extract($html, $baseUrl);
            $pdfs = $pdfLinks->find($html, $baseUrl);
            $menuPageHtml = $html;

            // Nothing on the homepage itself — a dedicated "/menu" page one hop
            // away is a common real-world pattern (confirmed live: a real
            // restaurant's homepage had neither a JSON-LD menu nor a PDF link,
            // while its own /menu page carried two clean PDFs). One bounded
            // extra same-site fetch, only when the homepage came up empty —
            // not a crawl. The schema.org hasMenu/menu JSON-LD pointer (when
            // present) is an authoritative signal for WHICH link is the menu
            // page, checked ahead of the plain path-substring guess.
            if ($items === [] && $pdfs === []) {
                $menuPageUrl = $this->resolveMenuPageUrl($html, $baseUrl, $harvester, $metadataParser);
                if ($menuPageUrl !== null) {
                    $menuResponse = $fetcher->tryFetch($menuPageUrl);
                    $menuHtml = is_array($menuResponse) && $menuResponse['status'] === 200
                        ? (string) $menuResponse['body'] : '';
                    if ($menuHtml !== '') {
                        $menuBaseUrl = $menuResponse['finalUrl'] ?? $menuPageUrl;
                        $items = $menuText->extract($menuHtml, $menuBaseUrl);
                        $pdfs = $pdfLinks->find($menuHtml, $menuBaseUrl);
                        $menuPageHtml = $menuHtml;
                    }
                }
            }

            if ($items !== []) {
                $menuApplier->apply($user, $items, enrichOnly: true, source: 'website-scan');
            }

            // Menu (PDF) — one scan job per menu-relevant PDF found (owner
            // direction 2026-07-23: a drinks/wine list counts the same as
            // food, the old $pdfs[0] cap silently dropped every PDF but the
            // first). Naively-found PDFs are keyword-filtered first so an
            // unrelated document (T&Cs, a press kit) doesn't burn an OCR call.
            $relevantPdfs = array_values(array_filter(
                $pdfs,
                fn (array $pdf) => $this->isMenuRelevantPdf($pdf['url'], $pdf['text']),
            ));
            foreach (array_slice($relevantPdfs, 0, self::MAX_PDF_SCANS) as $pdf) {
                // #LIFE-9: a manual Horizon retry of THIS job re-runs handle()
                // from scratch, so without this claim it would re-dispatch (and
                // re-bill) the OCR call for a PDF a prior attempt already sent.
                // See claimSubJobDispatch()'s docblock. Same claim window also
                // silently narrows BackfillPreviousWebsiteContentScanCommand's
                // documented re-scan escape hatch — see that command's docblock.
                if (! $this->claimSubJobDispatch('pdf', $pdf['url'])) {
                    continue;
                }

                // 9e: no stagger delay — the input (the PDF url) is already in
                // hand, and the scraping queue's own worker count bounds how
                // many OCR calls run at once. The old 30+15n stagger was pure
                // dead time on the menu's path to the page.
                WebsiteMenuPdfScanJob::dispatch($this->userId, $pdf['url']);
            }

            // Menu (HTML fallback) — only when the narrow JSON-LD lookup above
            // found nothing on whichever page we ended up checking. Tiered,
            // cheapest first: (1) Squarespace's own stable menu-block markup,
            // free and exact when it hits; (2) the general AI-structuring
            // reuse (same call already used for PDF/Google-photo OCR text),
            // gated behind a density pre-filter so an unrelated page never
            // reaches a billed AI call.
            if ($items === []) {
                $squarespaceItems = $squarespaceExtractor->extract($menuPageHtml);
                if ($squarespaceItems !== []) {
                    $menuApplier->apply($user, $squarespaceItems, enrichOnly: true, source: 'website-scan');
                } else {
                    $visibleText = $visibleTextExtractor->extract($menuPageHtml);
                    // #LIFE-9: same claim as the PDF loop above, keyed on the
                    // extracted text itself so a re-scraped but byte-identical
                    // page still dedupes against a prior attempt's dispatch.
                    // Same backfill-command interaction noted at the PDF claim
                    // site above applies here too.
                    if ($this->looksMenuDense($visibleText) && $this->claimSubJobDispatch('html', $visibleText)) {
                        // 9e: input already in hand — the old +30s was dead time.
                        WebsiteMenuHtmlScanJob::dispatch($this->userId, $visibleText);
                    }
                }
            }
        }

        // General link-harvesting — reuse the already-public, already-gated
        // seed() wholesale, not private sub-methods (the fix for the
        // capability-gate-bypass bug class this job's design deliberately avoids).
        $harvested = $harvester->harvestHtml($html, $baseUrl);

        // Same ruling as the design-evidence gate at the top of this method,
        // applied to IDENTITY instead of brand: when this website is the
        // workplace's, the socials on it are the venue's and (on a staff page)
        // its staff's. seed()'s social branch would file them as this
        // account's own — for lukemunnn it produced a conflict finding whose
        // `apply` payload swapped their own Instagram for the shop's.
        //
        // Dropped at the INPUT rather than inside seedSocials(): seed() is
        // called wholesale here precisely so its own capability gates cannot
        // be bypassed (see this class's docblock), and the fact that this
        // particular page is not the user's own is the CALLER's knowledge, not
        // the seeder's. The Google-listing connect path is untouched — that
        // one is still governed by owner ruling R14 (2026-08-18).
        if ($harvested !== [] && ! AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            unset($harvested['socials']);
            Log::info('website_scan.workplace_socials_skipped', [
                'user_id' => $this->userId,
                'reason' => 'workplace_brand_is_site_identity=false',
            ]);
        }

        if ($harvested !== []) {
            // seed() returns the findings LIST directly (unlike InstagramAutoSync's
            // ['findings' => …, 'unmatched' => …] wrapper).
            $findings = array_filter($googleBusinessAutoSync->seed($this->userId, $harvested, null, null), 'is_array');

            // This job runs from an observer on website change — no modal, no
            // HTTP response. A conflict finding (found link clashing with an
            // existing connection) previously vanished with the job; the bell
            // is its only surface. Conflicts only — clean seeds are already
            // visible as real connections in Integrations.
            $hasConflict = array_filter($findings, static fn (array $f) => ($f['outcome'] ?? null) === 'conflict') !== [];
            if ($hasConflict) {
                app(FindingsNotifier::class)->notify(
                    $this->userId,
                    'website-scan-findings:'.$this->userId.':'.sha1($this->url),
                    'We found more on your website',
                    'Your website mentions an integration that clashes with one you have connected — review it in Integrations.',
                );
            }
        }

        // T8 (owner, 2026-08-20): route EVERY outbound link on the page
        // through the P8 pipeline — WebsiteImporter was built for exactly
        // this and sat dormant, which is why a shop link, an event page or a
        // video on a scanned previous website left ZERO trace (no card, no
        // probe, no observation). It records its own import run, respects
        // its own daily cap, and its note arms seed real pool items.
        //
        // AFTER the harvestHtml seed above, deliberately (critic blocker,
        // 2026-08-20): the importer running FIRST auto-placed a thin
        // instagram.profile connection off the site's own Instagram link,
        // which made seedInstagram()'s has() guard skip the rich Apify
        // connect and emit a false 'clashes with one you have connected'
        // bell on virtually every first scan. Seed first: the rich connect
        // wins, and the importer's later route of the same link aliases onto
        // it (the reconciler's #R4 identity resolution) instead of racing it.
        try {
            $imported = app(WebsiteImporter::class)->import($user, $baseUrl);
            if ($imported['outcome'] !== 'ok') {
                // A capped/unreachable import must not be invisible (the
                // 10/day ImportRun cap writes no run row when it refuses).
                Log::info('website_scan.importer_skipped', [
                    'user_id' => $this->userId,
                    'outcome' => $imported['outcome'],
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        // The site's OWN root as a storefront (2026-09-05, st_ali retest #3).
        // The importer routes every link on the page, but a link back to the
        // same domain is an unknown-domain note by design (65 of them on
        // stali.com.au), so a business whose website IS its Shopify store
        // only ever got a store card when an Instagram bio link happened to
        // point at it. One suggest-only probe of the root; StoreBrandSeeder
        // decides whether there is a store there, and a plain website files
        // nothing. Owed on the platforms stage so the walk waits for it.
        try {
            CommerceProbeJob::owe($this->userId, $baseUrl);
            CommerceProbeJob::dispatch($this->userId, $baseUrl, suggestOnly: true);
        } catch (Throwable $e) {
            report($e);
        }

        BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_WEBSITE, $websiteNote[0], $websiteNote[1]);
    }

    /**
     * The menu page URL to try, preferring the authoritative schema.org
     * hasMenu/menu JSON-LD pointer (Restaurant/FoodEstablishment/
     * LocalBusiness) when a business's own structured data names it
     * directly — confirmed present but previously unread on a real test
     * site — falling back to the same-site "path contains 'menu'" guess.
     */
    private function resolveMenuPageUrl(string $html, string $baseUrl, WebsiteLinkHarvester $harvester, MetadataParser $metadataParser): ?string
    {
        return $this->menuPointerUrl($html, $baseUrl, $metadataParser)
            ?? $this->findPageLink($harvester->allOutboundLinks($html, $baseUrl), $baseUrl, 'menu');
    }

    /**
     * schema.org's own `hasMenu` (FoodEstablishment) or the older, still
     * commonly-emitted `menu` (Text or URL) property — either shape is
     * accepted defensively, matching MenuTextExtractor::asList()'s existing
     * singular-vs-list normalization idiom elsewhere in this pipeline. Only
     * a URL string is useful here (an inline embedded Menu object would need
     * a second, different read path — out of scope, the path-guess fallback
     * covers that case fine).
     */
    private function menuPointerUrl(string $html, string $baseUrl, MetadataParser $metadataParser): ?string
    {
        $parsed = $metadataParser->parse($html, $baseUrl);
        foreach (['Restaurant', 'FoodEstablishment', 'CafeOrCoffeeShop', 'BarOrPub', 'LocalBusiness'] as $type) {
            $node = $parsed->jsonLdOfType($type);
            if ($node === null) {
                continue;
            }
            $pointer = $node['hasMenu'] ?? $node['menu'] ?? null;
            if (is_string($pointer) && trim($pointer) !== '') {
                return $metadataParser->absolutize($pointer, $baseUrl);
            }
        }

        return null;
    }

    /**
     * First same-site link whose path contains the given keyword — a bounded
     * single hop, not a crawl. Same-site only: a third-party page carrying
     * the keyword (e.g. an OrderMate/UberEats "menu" deep link) isn't the
     * business's own content and is already captured separately by the
     * general link-harvesting below. Shared by the menu/about/contact
     * one-hop resolvers — same path-substring guess, different keyword.
     *
     * @param  list<string>  $links
     */
    private function findPageLink(array $links, string $baseUrl, string $keyword): ?string
    {
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($ownHost === '') {
            return null;
        }

        foreach ($links as $url) {
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $ownHost) {
                continue;
            }
            if (preg_match('~'.preg_quote($keyword, '~').'~i', (string) parse_url($url, PHP_URL_PATH)) === 1) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Atomically claims the right to dispatch one billed OCR/AI sub-job
     * (#LIFE-9). Cache::add() is SETNX — true only for the FIRST caller to
     * claim a given (kind, content) pair; every later claim of the same pair
     * (a manual Horizon retry re-running this job's handle() from scratch)
     * returns false and the caller must skip the dispatch. Keyed on content
     * (the PDF's URL, or the extracted visible text for the HTML path), not
     * on this job's own attempt — the content is what makes the vendor call
     * idempotent, and $tries=1 already means there is only ever one "attempt"
     * for the queue's own purposes.
     *
     * TTL matches Horizon's own failed-job retention (`horizon.trim.failed`,
     * default 10080 minutes = 7 days): a "Retry" button only exists on a job
     * Horizon still lists as failed, so a claim that outlives trimming
     * already covers every click that could ever happen — no reason to hold
     * it (or the spend refusal it backs) any longer.
     *
     * A refused claim is logged (review round 2): a bare skip here previously
     * looked identical to "nothing to scan" both to a manual Horizon retry
     * and to BackfillPreviousWebsiteContentScanCommand's "Dispatched N"
     * count, which does not distinguish a real dispatch from a refused one.
     */
    private function claimSubJobDispatch(string $kind, string $payload): bool
    {
        $claimed = Cache::add(
            CacheKeyGenerator::websiteScanSubJobDispatched($this->userId, $kind, sha1($payload)),
            true,
            (int) config('horizon.trim.failed', 10080) * 60,
        );

        if (! $claimed) {
            Log::warning('website_scan.subjob_dispatch_already_claimed', [
                'user_id' => $this->userId,
                'site_id' => $this->siteId,
                'kind' => $kind,
                'note' => 'billed sub-job dispatch skipped — an identical dispatch was already claimed within the claim window (#LIFE-9)',
            ]);
        }

        return $claimed;
    }

    /** Menu-relevance keyword match against a PDF's own link text OR its URL path — either counts. */
    private function isMenuRelevantPdf(string $url, string $text): bool
    {
        $haystack = strtolower($text.' '.(string) parse_url($url, PHP_URL_PATH));
        foreach (self::MENU_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cheap sanity check before spending a billed AI structuring call —
     * mirrors GoogleMenuPhotoScanJob's own OCR-text-density filter idea, but
     * keyed on price-shaped lines rather than raw length alone (a long "About
     * us" page easily clears a length-only bar). VisibleTextExtractor puts
     * each block-level element on its own line, so a real price column
     * reliably shows up as several lines that are JUST a number — true
     * whether the site prints "$14" or a bare "14".
     */
    private function looksMenuDense(string $text): bool
    {
        if (mb_strlen($text) < self::MIN_DENSE_TEXT_CHARS) {
            return false;
        }

        $priceLines = 0;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\$?\d{1,4}(?:\.\d{1,2})?$/', trim($line)) === 1) {
                $priceLines++;
            }
        }

        return $priceLines >= self::MIN_PRICE_LINES;
    }

    public function failed(Throwable $e): void
    {
        // Setup progress (2026-09-02): an owed stage gets its answer.
        BuildProgress::noteForUser((string) $this->userId, PreAccountBuildEvent::STAGE_WEBSITE, PreAccountBuildEvent::STATUS_FAILED, "Couldn't read your website");
        report($e);
        Log::error('website_scan.content_scan_failed', [
            'user_id' => $this->userId,
            'site_id' => $this->siteId,
            'url' => $this->url,
            'error' => $e->getMessage(),
            // Single-attempt by design (see class docblock) — a Horizon
            // "Retry failed job" click re-runs the whole billed OCR/AI fan-out.
            'note' => 'single-attempt job; manual Horizon retry re-bills OCR/AI sub-jobs',
        ]);
    }
}
