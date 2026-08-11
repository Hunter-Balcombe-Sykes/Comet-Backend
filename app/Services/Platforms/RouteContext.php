<?php

namespace App\Services\Platforms;

use App\Routing\IriCanonicalizer;
use App\Services\Http\SafeUrlFetcher;

/**
 * Per-RUN context for LinkRouter — carries the seen-platforms dedupe map
 * (Issue M: first bio link per platform wins) and the commerce probe budget
 * (bounded fan-out per link-in-bio page / per scan).
 *
 * MUST be created once per calling LOOP and threaded through every route() call
 * in that loop. A fresh instance per URL silently disables BOTH guards, which
 * is exactly how the probe cap went missing when the inline
 * MAX_COMMERCE_PROBES counters were deleted from LinkInBioScanJob and
 * InstagramConnectionSeeder::autoSaveUnmatchedLinks. CustomLinkSeeder::seed()
 * therefore accepts one; loops pass theirs, and only genuine single-URL entry
 * points (CustomLinksController::addLink) let it default.
 */
final class RouteContext
{
    /**
     * Default probe budget per run — the value both deleted inline counters
     * used (signup-v2 C4). Pinned by CustomLinkSeederTest and
     * LinkInBioScanJobTest, which assert against this constant directly.
     */
    public const DEFAULT_MAX_PROBES = 6;

    /** @var array<string, true> */
    public array $seenPlatforms = [];

    private int $probeCount = 0;

    /**
     * Probes REFUSED because the budget was already spent. Counted separately
     * because the refusal is otherwise invisible: LinkRouter answers a starved
     * link with the same RouteResult::custom() a gate denial gives it, so a
     * caller cannot tell "we looked and it wasn't a shop" from "we never
     * looked". Found live 2026-08-10 — six nav links of one host consumed the
     * whole budget and the three links behind them were never examined.
     */
    private int $probesDenied = 0;

    /**
     * Path fragments that mark a URL as a product page rather than a page on a
     * site. A homepage probe cannot find a specific product, so one of these
     * earns a website its second and last probe of the run.
     *
     * `/shop` and `/store` are absent deliberately — SquarespaceScraper already
     * walks them off the origin (SquarespaceScraper.php:14,31-34), so any URL on
     * the host already covers them. The trailing slashes matter for the same
     * reason: `/products/` catches a specific item, not the `/products`
     * collection root that scraper already reaches. `/p/` is absent as too
     * generic (`/p/about`).
     *
     * Path DEPTH is deliberately not filtered, though ProbeGate refuses >=2
     * internal slashes (Routing/Probes/ProbeGate.php:112). That rule only binds
     * the shop arm: seedShop() reaches the gate via StoreBrandSeeder, but the
     * unclassified arm — every custom-domain store, and the arm the 2026-08-10
     * incident hit — runs CommerceProbeJob::probe() -> readProductPage() on the
     * pasted URL with no gate, and that method is documented to keep deep URLs
     * (GenericShopScraper.php:107-109). Filtering on depth would therefore
     * suppress product detection exactly where it works, and would drop a deep
     * URL into the `:plain` bucket where it could evict the homepage's probe.
     */
    private const PRODUCT_PATH_HINTS = ['/product/', '/products/', '/item/'];

    /**
     * Websites already probed this run. Bounded by the caller — the widest
     * producer, WebsiteLinkHarvester::extractLinks(), caps at 500 unique hrefs
     * (WebsiteLinkHarvester.php:422,444) — so this map cannot grow unbounded
     * unless a future caller becomes the first one that does.
     *
     * @var array<string, true>
     */
    private array $seenSites = [];

    /** Probes NOT spent because this website was already probed this run. */
    private int $sitesDeduped = 0;

    /**
     * Probes NOT spent because no probe could ever fetch the URL. Counted apart
     * from probesDenied: "we never asked" and "we ran out of budget" are
     * different diagnoses, and folding them together would make the pre-filter
     * look like starvation on the run card.
     */
    private int $probesSkippedIneligible = 0;

    /**
     * True when this run originated from a scrape of the user's OWN Instagram —
     * the only origin allowed to auto-connect a booking platform on their behalf.
     *
     * LinkRouter::route() carries no origin parameter and seedBooking genuinely
     * cannot tell its four callers apart, so the decision is made where the
     * context is built. Defaults FALSE: an unmarked call site silently does not
     * auto-connect, which is the safe direction to fail. NOT derivable from
     * payload.source — resolveWrite() hardcodes 'instagram' for every caller,
     * including a dashboard paste.
     */
    public function __construct(
        public readonly int $maxProbes = self::DEFAULT_MAX_PROBES,
        public readonly bool $autoConnectBooking = false,
    ) {}

    /**
     * Claim one probe from this run's budget. False when the budget is spent —
     * the caller must then fall back to a custom link rather than dispatch, so
     * links past the budget keep the pre-refactor straight-to-custom-link
     * behaviour and nothing vanishes.
     *
     * Private so consumeProbeFor() is the only way to spend a probe — the
     * website dedupe is then structural rather than a rule each new call site
     * has to remember.
     */
    private function consumeProbe(): bool
    {
        if ($this->probeCount >= $this->maxProbes) {
            $this->probesDenied++;

            return false;
        }

        $this->probeCount++;

        return true;
    }

    /**
     * Claim a probe for $url — at most two per website per run, one for a plain
     * URL and one for a product-looking one, both charged to the same budget.
     *
     * The dedupe lives here rather than at LinkRouter's two call sites so that
     * every path that can spend a probe goes through it. Six nav links of one
     * host spent the entire budget live 2026-08-10 and starved the three links
     * behind them; a probe answers a question about a HOST, so asking the same
     * host twice is the same question.
     */
    public function consumeProbeFor(string $url): bool
    {
        // BEFORE the budget: the cheapest filter must run before the scarcest
        // resource is claimed, and today nothing between here and the fetch will
        // do it. ProbeGate makes the same lexical refusal but only reaches the
        // SHOP arm (LinkProbeWorker -> StoreBrandSeeder); the unclassified arm —
        // the one a creator's affiliate links take — runs
        // GenericShopScraper::readProductPage() with no gate at all. So this is
        // not "the same check, moved earlier": for the arm that matters it
        // suppresses a fetch that genuinely used to happen. That is intended.
        // A shortener is documented as never auto-connected pre-expansion
        // (IriCanonicalizer::SHORTENERS), and spending one of six slots to learn
        // that starves a link behind it.
        if (! self::isProbeable($url)) {
            $this->probesSkippedIneligible++;

            return false;
        }

        $key = $this->probeKey($url);

        if ($key !== null && isset($this->seenSites[$key])) {
            $this->sitesDeduped++;

            return false;
        }

        if (! $this->consumeProbe()) {
            return false;
        }

        if ($key !== null) {
            $this->seenSites[$key] = true;
        }

        return true;
    }

    public function probesUsed(): int
    {
        return $this->probeCount;
    }

    public function sitesDeduped(): int
    {
        return $this->sitesDeduped;
    }

    public function probesDenied(): int
    {
        return $this->probesDenied;
    }

    public function probesSkippedIneligible(): int
    {
        return $this->probesSkippedIneligible;
    }

    /**
     * Could a probe fetch this URL at all? Pure string work — no DNS, no I/O —
     * mirroring the lexical half of ProbeGate::lexicalRefusal() and
     * IriCanonicalizer's own rejections so the two cannot disagree about what is
     * unfetchable.
     *
     * An uncertain HOST fails OPEN (returns true): the URL is probed exactly as
     * it is today. A url so malformed that parse_url() cannot read a scheme from
     * it fails closed, which costs nothing — nothing could have fetched it
     * either. This is a budget filter, not the SSRF defence — SafeUrlFetcher
     * still resolves and validates every hop at fetch time — so being wrong here
     * costs one probe, never a security property.
     */
    private static function isProbeable(string $url): bool
    {
        // A bare "example.com/x" is a legitimate paste shape and is assumed
        // https, exactly as IriCanonicalizer does, so the checks below see a
        // host rather than treating the whole string as a path.
        $candidate = preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) ? $url : 'https://'.ltrim($url, '/');

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // rtrim the root dot and strip IPv6 brackets before ANY comparison,
        // mirroring SafeUrlFetcher::assertSafe(). Both are how a host slips a
        // suffix match while resolving identically: "bit.ly." is neither
        // "bit.ly" nor "*.bit.ly", and parse_url hands back "[::1]" with the
        // brackets still on, which FILTER_VALIDATE_IP rejects as not-an-IP.
        $host = strtolower(rtrim((string) parse_url($candidate, PHP_URL_HOST), '.'));
        $host = trim($host, '[]');

        if ($host === '') {
            // A host we cannot read is not a host we can call unfetchable.
            return true;
        }

        // An IP literal never carries a storefront we would connect, and is the
        // shape every SSRF attempt takes.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (SafeUrlFetcher::deniedHostSuffixes() as $suffix) {
            $suffix = strtolower(trim((string) $suffix));
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return false;
            }
        }

        // Suffix match rather than the registrable-domain test the canonicaliser
        // uses — RouteContext is constructed with `new`, so it has no
        // PublicSuffixList. The looser match only ever over-matches subdomains
        // of a shortener, which are shorteners too.
        foreach (IriCanonicalizer::shortenerDomains() as $shortener) {
            if ($host === $shortener || str_ends_with($host, '.'.$shortener)) {
                return false;
            }
        }

        return true;
    }

    /**
     * This run's budget accounting, for the caller's completion log.
     *
     * @return array{probe_budget: int, probes_spent: int, probes_denied: int, sites_deduped: int, probes_skipped_ineligible: int}
     */
    public function summary(): array
    {
        return [
            'probe_budget' => $this->maxProbes,
            'probes_spent' => $this->probesUsed(),
            'probes_denied' => $this->probesDenied(),
            'sites_deduped' => $this->sitesDeduped(),
            'probes_skipped_ineligible' => $this->probesSkippedIneligible(),
        ];
    }

    /**
     * Lowercased host with one leading `www.` stripped; scheme, port and path
     * ignored. Null when there is no parseable host — an unparseable URL is
     * probed normally rather than deduped together with other unparseable ones.
     *
     * Deliberately coarser than what the probes fetch: the question here is
     * "have I already asked about this website?", not "what exactly did I
     * request?". Residual cases (trailing dot, punycode vs unicode, equivalent
     * IPv6 spellings) fail OPEN — they cost one extra probe rather than wrongly
     * collapsing two sites into one, which is the safe direction and not a bug
     * to be tidied away.
     */
    private function siteKey(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        return (string) preg_replace('~^www\.~', '', $host);
    }

    /** Website plus shape — a site gets one plain probe and one product probe. */
    private function probeKey(string $url): ?string
    {
        $site = $this->siteKey($url);

        if ($site === null) {
            return null;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));

        foreach (self::PRODUCT_PATH_HINTS as $hint) {
            if (str_contains($path, $hint)) {
                return $site.':product';
            }
        }

        return $site.':plain';
    }
}
