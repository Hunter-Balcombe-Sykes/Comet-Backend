<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Design\DesignKitAutopilot;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Http\MetadataParser;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\LinkInBioDetector;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\WebsiteScan\AboutProseExtractor;
use App\Services\WebsiteScan\AboutTextExtractor;
use App\Services\WebsiteScan\ContactEmailExtractor;
use App\Services\WebsiteScan\FaviconFetcher;
use App\Services\WebsiteScan\MenuTextExtractor;
use App\Services\WebsiteScan\PdfLinkDetector;
use App\Services\WebsiteScan\SquarespaceMenuExtractor;
use App\Services\WebsiteScan\VisibleTextExtractor;
use App\Services\WebsiteScan\WebsiteAccentExtractor;
use App\Services\WebsiteScan\WebsiteGalleryCandidateExtractor;
use App\Services\WebsiteScan\WebsiteLogoCandidateExtractor;
use App\Services\WebsiteScan\WorkplaceContentApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Single entry point for everything that happens when a user's
// previous_website is set/changed: about text (JSON-LD/meta + a richer
// heading-prose heuristic), contact email, menu (HTML + PDF),
// general link-harvesting, accent colour, logo candidates, and gallery
// photos — one plain fetch, no headless render, no separate "design scan"
// job. Independent of (and, since Part B, the sole replacement for) the old
// headless-browser website-style-analysis pipeline.
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
    private const MAX_PDF_SCANS = 5;

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
        WebsiteGalleryCandidateExtractor $galleryCandidateExtractor,
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

        // About text — plain JSON-LD/meta fill-if-empty first, then the
        // richer heading-prose heuristic (preferred when found — see
        // WorkplaceContentApplier::applyProseDescription()'s precedence).
        // Contact email — mailto: + JSON-LD, homepage first.
        $workplace = Workplace::firstOrNew(['site_id' => $this->siteId]);
        $contentApplier->applyDescription($workplace, $about->extract($html, $baseUrl));
        $proseText = $aboutProse->extract($html);
        $email = $contactEmailExtractor->extract($html, $baseUrl);

        // One-hop /about + /contact — only for whichever came up empty on the
        // homepage, fetched CONCURRENTLY (stacking sequential one-hop fetches
        // risks this job's 60s timeout before reaching accent/logo below).
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
            foreach (array_slice($relevantPdfs, 0, self::MAX_PDF_SCANS) as $index => $pdf) {
                WebsiteMenuPdfScanJob::dispatch($this->userId, $pdf['url'])
                    ->delay(now()->addSeconds(30 + $index * 15));
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
                    if ($this->looksMenuDense($visibleText)) {
                        WebsiteMenuHtmlScanJob::dispatch($this->userId, $visibleText)->delay(now()->addSeconds(30));
                    }
                }
            }
        }

        // General link-harvesting — reuse the already-public, already-gated
        // seed() wholesale, not private sub-methods (the fix for the
        // capability-gate-bypass bug class this job's design deliberately avoids).
        $harvested = $harvester->harvestHtml($html, $baseUrl);
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

        // Gallery photos — its own dispatched job (like the PDF/HTML menu
        // scans above): downloading+validating+uploading several candidate
        // photos can run long, same rationale as those jobs being split out
        // of this job's own 60s window. Fills an EMPTY gallery pool only.
        $galleryCandidates = $galleryCandidateExtractor->extract($html, $baseUrl);
        if ($galleryCandidates !== []) {
            WebsiteGalleryScanJob::dispatch($this->userId, $this->siteId, $galleryCandidates)
                ->delay(now()->addSeconds(30));
        }

        // Everything below is DESIGN evidence — the site's logo, accent and
        // font read off this website. That only makes sense when the
        // workplace's brand IS the site's identity (a business). A partna
        // account's workplace website is someone else's brand (owner,
        // 2026-08-19): its logo must never become the site's mark and its
        // colours must never become the site's accent.
        if (! AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            Log::info('website_scan.design_evidence_skipped', [
                'user_id' => $this->userId,
                'site_id' => $this->siteId,
                'reason' => 'workplace_brand_is_site_identity=false',
            ]);

            return;
        }

        // Accent colour candidates + logo candidates — one shared favicon
        // fetch, no headless render, reuses the exact $html/$baseUrl already
        // fetched above (no second main-page request). Accent RESOLUTION
        // itself is deferred to ResolveSiteAccentJob (dispatched below, after
        // the logo/gallery work is kicked off) so those async palette tiers
        // get a real shot once their processing has landed.
        $favicon = $faviconFetcher->fetch($html, $baseUrl);
        $themeColor = $accentExtractor->themeColorFromHtml($html);
        $faviconColor = isset($favicon['bytes']) ? $accentExtractor->dominantColorFromImage($favicon['bytes']) : null;

        $logoCandidates = $logoCandidateExtractor->extract($html, $baseUrl);
        if ($favicon !== null) {
            $logoCandidates[] = ['kind' => 'icon', 'url' => $favicon['url'], 'sizes' => '', 'type' => ''];
        }
        if ($logoCandidates !== []) {
            // The decisions array is the grabber's per-candidate audit trail
            // (LogoAutoGrabber's docblock expects the caller to keep it) —
            // logged, not persisted: "why didn't my logo get picked up" is a
            // support question answered from logs, not a product surface.
            $decisions = $logoAutoGrabber->grabIfEmpty($user, $site, $logoCandidates);
            Log::info('website_scan.logo_grab', [
                'user_id' => $this->userId,
                'site_id' => $this->siteId,
                'decisions' => $decisions,
            ]);
        }

        // Accent resolution — dispatched TWICE, not run inline. Once now
        // (covers theme-color/favicon, already available — same latency as
        // before this change) and once delayed (covers the logo/gallery
        // tiers above, which are async and this job has no direct handle to
        // chain onto — LogoAutoGrabber/GalleryAutoGrabber dispatch their own
        // variant-processing jobs several layers down via MediaUploadService,
        // opaque to this caller). Cheap to over-dispatch: DesignKitAccentApplier
        // (inside ResolveSiteAccentJob) is fill-if-empty, so the second run is
        // a no-op once the first (or a manual edit) already set an accent.
        // 120s comfortably covers the gallery job's own 30s delay plus its
        // processing, and gives the logo pipeline's typically-fast (but
        // occasionally slow, cold-container) round-trip a fair shot — not a
        // guarantee; a genuinely slower run just misses this pass and waits
        // for the next website re-scan.
        ResolveSiteAccentJob::dispatch($this->siteId, $themeColor, $faviconColor);
        ResolveSiteAccentJob::dispatch($this->siteId, $themeColor, $faviconColor)
            ->delay(now()->addSeconds(120));

        // §13's second half of website evidence: the font keyword classifier.
        // Same $html as everything above (no extra fetch), same fill-if-empty
        // discipline as the accent — a font the user (or an earlier scan)
        // already chose is never touched.
        $autopilot = app(DesignKitAutopilot::class);
        $autopilot->persistFillIfEmpty($this->siteId, $autopilot->fromWebsiteEvidence($html)['proposals']);
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
