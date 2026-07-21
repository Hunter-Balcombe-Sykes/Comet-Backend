<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\WebsiteScan\AboutTextExtractor;
use App\Services\WebsiteScan\DesignKitAccentApplier;
use App\Services\WebsiteScan\FaviconFetcher;
use App\Services\WebsiteScan\MenuTextExtractor;
use App\Services\WebsiteScan\PdfLinkDetector;
use App\Services\WebsiteScan\WebsiteAccentExtractor;
use App\Services\WebsiteScan\WebsiteLogoCandidateExtractor;
use App\Services\WebsiteScan\WorkplaceContentApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Single entry point for everything that happens when a user's
// previous_website is set/changed: about text, menu (HTML + PDF),
// general link-harvesting, accent colour, and logo candidates — one plain
// fetch, no headless render, no separate "design scan" job. Independent of
// (and, since Part B, the sole replacement for) the old headless-browser
// website-style-analysis pipeline.
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
// calling the real seed() wholesale instead of its private sub-methods.
class ScanPreviousWebsiteContentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    public int $timeout = 60;

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
        MenuTextExtractor $menuText,
        PdfLinkDetector $pdfLinks,
        WorkplaceContentApplier $contentApplier,
        MenuScanApplier $menuApplier,
        GoogleBusinessAutoSync $googleBusinessAutoSync,
        FaviconFetcher $faviconFetcher,
        WebsiteAccentExtractor $accentExtractor,
        DesignKitAccentApplier $accentApplier,
        WebsiteLogoCandidateExtractor $logoCandidateExtractor,
        LogoAutoGrabber $logoAutoGrabber,
    ): void {
        $user = User::find($this->userId);
        $site = Site::find($this->siteId);
        if ($user === null || $site === null) {
            return;
        }

        $response = $fetcher->tryFetch($this->url);
        $html = is_array($response) && ($response['status'] ?? 0) === 200 ? (string) ($response['body'] ?? '') : '';
        if ($html === '') {
            return;
        }
        $baseUrl = $response['finalUrl'] ?? $this->url;

        // About text — fill-if-empty.
        $workplace = Workplace::firstOrNew(['site_id' => $this->siteId]);
        $contentApplier->applyDescription($workplace, $about->extract($html, $baseUrl));

        // Menu (HTML) — food-Business only.
        if (AccountCapabilities::for($user)->can_use_menu) {
            $items = $menuText->extract($html, $baseUrl);
            $pdfs = $pdfLinks->find($html, $baseUrl);

            // Nothing on the homepage itself — a dedicated "/menu" page one hop
            // away is a common real-world pattern (confirmed live: a real
            // restaurant's homepage had neither a JSON-LD menu nor a PDF link,
            // while its own /menu page carried two clean PDFs). One bounded
            // extra same-site fetch, only when the homepage came up empty —
            // not a crawl.
            if ($items === [] && $pdfs === []) {
                $menuPageUrl = $this->findMenuPageLink($harvester->allOutboundLinks($html, $baseUrl), $baseUrl);
                if ($menuPageUrl !== null) {
                    $menuResponse = $fetcher->tryFetch($menuPageUrl);
                    $menuHtml = is_array($menuResponse) && ($menuResponse['status'] ?? 0) === 200
                        ? (string) ($menuResponse['body'] ?? '') : '';
                    if ($menuHtml !== '') {
                        $menuBaseUrl = $menuResponse['finalUrl'] ?? $menuPageUrl;
                        $items = $menuText->extract($menuHtml, $menuBaseUrl);
                        $pdfs = $pdfLinks->find($menuHtml, $menuBaseUrl);
                    }
                }
            }

            if ($items !== []) {
                $menuApplier->apply($user, $items, enrichOnly: true, source: 'website-scan');
            }
            // Menu (PDF) — separate job, don't OCR inline.
            if ($pdfs !== []) {
                WebsiteMenuPdfScanJob::dispatch($this->userId, $pdfs[0])->delay(now()->addSeconds(30));
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

        // Accent colour + logo candidates — one shared favicon fetch, no
        // headless render, reuses the exact $html/$baseUrl already fetched
        // above (no second main-page request).
        $favicon = $faviconFetcher->fetch($html, $baseUrl);
        $accentHex = $accentExtractor->extract($html, $favicon['bytes'] ?? null);
        $accentApplier->apply($this->siteId, $accentHex);

        $logoCandidates = $logoCandidateExtractor->extract($html, $baseUrl);
        if ($favicon !== null) {
            $logoCandidates[] = ['kind' => 'icon', 'url' => $favicon['url'], 'sizes' => '', 'type' => ''];
        }
        if ($logoCandidates !== []) {
            $logoAutoGrabber->grabIfEmpty($user, $site, $logoCandidates);
        }
    }

    /**
     * First same-site link whose path looks like a menu page — a bounded
     * single hop, not a crawl. Same-site only: a third-party ordering
     * platform's own "menu" page (e.g. an OrderMate/UberEats deep link) isn't
     * the business's own content and is already captured separately by the
     * general link-harvesting below.
     *
     * @param  list<string>  $links
     */
    private function findMenuPageLink(array $links, string $baseUrl): ?string
    {
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($ownHost === '') {
            return null;
        }

        foreach ($links as $url) {
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $ownHost) {
                continue;
            }
            if (preg_match('~menu~i', (string) parse_url($url, PHP_URL_PATH)) === 1) {
                return $url;
            }
        }

        return null;
    }
}
