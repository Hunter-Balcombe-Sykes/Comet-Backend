<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\User\User;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use Throwable;

// Seeds connections from the links found in an Instagram bio.
//
// 2026-07-25 refactor (link classification consolidation): classification and
// routing live in LinkRouter now. This class walks the bio links, hands each to
// LinkRouter::route(), and translates the RouteResult back into the
// findings/unmatched contract the Instagram synced modal reads. Two gates it
// used to own are gone by decision, not by accident:
//   - DISC-7 (can_autosync_scraped_connections) — Decision 7, repealed.
//   - RULING 1 (socials gated on google_business_full_sync) — Decision 8,
//     repealed. Every account type now gets scraped socials auto-connected.
//     GoogleBusinessAutoSync::seedSocials keeps its own gate; that asymmetry is
//     deliberate, not an oversight.
//
// handleClassifiedLink() and ACTIONABLE were deleted with the refactor (Phase
// 7) — LinkRouter reproduces every invariant they carried: the per-run
// first-link-per-platform dedupe (now RouteContext::seenPlatforms), the
// LIFE-106 booking XOR lock with route-to-custom-on-contention, the
// soft-delete tombstone guard, per-link fault isolation, and the "never
// silently dropped" contract.
//
// Best-effort: each link is isolated in its own try/catch.
class InstagramAutoSync
{
    use BuildsAutoSyncFindings {
        // The trait's socialUsername() handles tiktok/x/linkedin; the override
        // below adds Facebook via the shared normalizer and delegates the rest
        // here. Aliasing is the only way to reach a trait's INSTANCE method
        // from a class that overrides it — `Trait::method()` is a static call
        // and throws "Call to protected method ... from scope".
        socialUsername as private traitSocialUsername;
    }

    public function __construct(
        private readonly WebsiteLinkHarvester $harvester,
        private readonly FacebookNormalizer $facebookNormalizer,
        private readonly LinkInBioDetector $linkInBioDetector,
        private readonly LinkRouter $router,
    ) {}

    /**
     * Contract: $userId MUST be server-derived — the real caller is
     * InstagramConnectionSeeder::seed(), itself invoked with the $userId
     * InstagramConnectJob was dispatched with — never raw request input. There
     * is no ownership check inside this method (it writes IntegrationConnection
     * rows keyed on the given id unconditionally); a future controller-invoked
     * caller must authorizeForUser($user, 'update', ...) at the call site
     * before reaching here, the same way every other mutating controller path does.
     *
     * @param  list<mixed>  $bioLinks  raw bio links (InstagramScraper::bioLinks() output — defensively typed here too)
     * @param  ?RouteContext  $ctx  the CALLER's run context when the caller routes more
     *                              links after this returns (InstagramConnectionSeeder's
     *                              second pass over `unmatched`). Supplying it makes the
     *                              two passes ONE run; when it is supplied it is
     *                              authoritative, so $autoConnectBooking must already be
     *                              set on it.
     * @return array{findings: list<array<string,mixed>>, unmatched: list<array<string,mixed>>}
     */
    public function seed(string $userId, array $bioLinks, bool $autoConnectBooking = false, ?RouteContext $ctx = null): array
    {
        // Dominant case today: the Apify actor returns no bio fields at all, so
        // the connect job calls this with []. Skip the user lookup entirely.
        if ($bioLinks === []) {
            return ['findings' => [], 'unmatched' => []];
        }

        $user = User::find($userId);

        $findings = [];
        $unmatched = [];

        // ONE context for the whole run — carries first-link-per-platform
        // dedupe and the commerce probe budget across every link below. A
        // per-link instance would disable both.
        //
        // The caller's context wins when there is one, because a bio scrape is
        // ONE run even though it routes in two passes: this loop, then
        // InstagramConnectionSeeder's sweep over what lands in `unmatched`. With
        // a context each, pass 2 began with an empty seen-platforms map and
        // re-decided links this loop had already settled — a second link to one
        // platform came back here as 'custom' (homeless, wants a card), then
        // pass 2 read it as a fresh candidate and turned it into a conflict
        // whose finding nobody kept. No card, no connection, no finding.
        //
        // Origin comes from the CALLER, not from being Instagram: a bio link is
        // the account holder's own either way, but only a staff/ManyChat build
        // has nobody present to answer "whose menu is this?". Every other origin
        // shows them a picker, so hardcoding true here would pre-empt it.
        $ctx ??= new RouteContext(autoConnectBooking: $autoConnectBooking);

        foreach ($bioLinks as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);

            try {
                if ($this->linkInBioDetector->matches($url)) {
                    // A curated link-in-bio page (Linktree/Milkshake/Beacons/
                    // Stan Store) isn't itself classifiable — it's a page to
                    // unroll, not a platform to connect. Scanned async because
                    // its own fetch can be slow or JS-heavy and would risk
                    // blowing InstagramConnectJob's timeout inline. Nothing
                    // about the bio-link URL itself is persisted.
                    LinkInBioScanJob::dispatch($userId, $url, $autoConnectBooking);

                    continue;
                }

                $classified = $this->harvester->classify($url);
                if ($classified === null) {
                    $unmatched[] = ['url' => $url, 'label' => $this->hostLabel($url)];

                    continue;
                }

                // A missing user reads as no routing — fail closed, exactly as
                // the old capability derivation would have if it could not
                // resolve a $user at all.
                if ($user === null) {
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                    continue;
                }

                $result = $this->router->route($user, $url, $ctx);

                // Take the router's own findings/unmatched verbatim rather than
                // rebuilding them from the outcome. That is what preserves the
                // seeded-vs-CONFLICT distinction the synced modal's Swap surface
                // needs: a conflict writes nothing and must not be reported as a
                // seed. Rebuilding from `outcome === 'seeded'` alone is how the
                // first cut of this refactor lost every conflict finding.
                foreach ($result->findings as $finding) {
                    $findings[] = $finding;
                }
                foreach ($result->unmatched as $miss) {
                    $unmatched[] = $miss;
                }

                // A gate denial returns 'custom' with no unmatched entry of its
                // own — surface it here so the link is still offered as a
                // custom-link suggestion rather than silently dropped.
                if ($result->outcome === 'custom' && $result->unmatched === []) {
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];
                }

                // 'pending' deliberately adds nothing: the probe job owns that
                // link and falls back to seedCustom() itself on a miss, so
                // surfacing it would have autoSaveUnmatchedLinks re-route the same
                // URL — a duplicate dispatch and a second write for one bio link.
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['findings' => $findings, 'unmatched' => $unmatched];
    }

    /** Override — adds Facebook normalizer support to the trait's implementation. */
    protected function socialUsername(string $platform, string $url): string
    {
        if ($platform === 'facebook') {
            // Delegate to the same parser the manual connect form uses (G4-4) —
            // a standalone regex here shares its blind spot for reserved path
            // segments (pages/people/etc.).
            $parsed = ($this->facebookNormalizer)($url);

            return $parsed['username'] ?? '';
        }

        return $this->traitSocialUsername($platform, $url);
    }

    /** Domain-derived fallback label for a genuinely unclassified link ("linktr.ee", not the full URL). */
    private function hostLabel(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('~^www\.~i', '', $host) ?? $host;

        return $host !== '' ? $host : $url;
    }
}
