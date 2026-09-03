<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Routing\ShortLinkExpander;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Support\Facades\Log;
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
        private readonly ShortLinkExpander $expander,
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
     * @return array{findings: list<array<string,mixed>>, unmatched: list<array<string,mixed>>, scans: int}
     */
    public function seed(string $userId, array $bioLinks, bool $autoConnectBooking = false, ?RouteContext $ctx = null): array
    {
        // Dominant case today: the Apify actor returns no bio fields at all, so
        // the connect job calls this with []. Skip the user lookup entirely.
        if ($bioLinks === []) {
            return ['findings' => [], 'unmatched' => [], 'scans' => 0];
        }

        $user = User::find($userId);

        $findings = [];

        $scans = 0;
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

        // Make the docblock's "the context wins" true by construction rather
        // than by every caller remembering to pass both consistently. The
        // LinkInBioScanJob dispatch below reads this scalar while the booking
        // arm reads $ctx->autoConnectBooking, so a caller that passed only a
        // marked context would silently unroll every aggregator page with
        // auto-connect OFF — and one that passed only the scalar would turn it
        // ON for a run that said not to, which is the unsafe direction.
        $autoConnectBooking = $ctx->autoConnectBooking;

        // FI-12 (T6 live, livplumbarber): one page, two harvest shapes —
        // externalUrl carries http://…square.site, the bio-TEXT regex yields
        // the same host which urlish() defaults to https:// — and the second
        // pass hit the seenPlatforms slot and carded a page whose connection
        // had just been made. Dedupe by scheme-/www-/slash-insensitive form
        // before routing: one page routes once.
        $seenPages = [];

        foreach ($bioLinks as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);

            // Expand shorteners FIRST, before the aggregator check and before
            // classify() — LinkRouter owns an expander, but nothing on this
            // path ever reaches it: classify() returns null for bit.ly (a
            // shortener is not a platform), so the link is filed as unmatched
            // and the router, expander and all, is never called.
            //
            // Found live 2026-08-28 on xia_tattoo, whose whole online presence
            // sits behind one bit.ly: the account produced zero routing
            // observations and zero links. A shortener in a bio is the
            // COMMON case, not an edge — it can hide an aggregator page, a
            // booking link or a shop, and every one of those is lost before
            // this. Expansion is cached both ways by the expander, and
            // expandIfShort() returns the input unchanged for everything else,
            // so this costs nothing on the ordinary path.
            //
            // Deliberately ahead of the dedupe below: the whole point is that
            // two different short links can resolve to the SAME page, and the
            // expanded form is the only form that can be compared.
            $url = $this->expander->expandIfShort($url);

            $pageKey = strtolower(rtrim(preg_replace('~^https?://(?:www\.)?~i', '', $url) ?? $url, '/'));
            if (isset($seenPages[$pageKey])) {
                continue;
            }
            $seenPages[$pageKey] = true;

            try {
                if ($this->linkInBioDetector->matches($url)) {
                    // A curated link-in-bio page (Linktree/Milkshake/Beacons/
                    // Stan Store) isn't itself classifiable — it's a page to
                    // unroll, not a platform to connect. Scanned async because
                    // its own fetch can be slow or JS-heavy and would risk
                    // blowing InstagramConnectJob's timeout inline. Nothing
                    // about the bio-link URL itself is persisted.
                    // Setup progress (2026-09-02): the platforms row is owed from here.
                    BuildProgress::noteForUser($userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Checking your link page');
                    LinkInBioScanJob::dispatch($userId, $url, $autoConnectBooking);
                    $scans++;

                    continue;
                }

                $classified = $this->harvester->classify($url);
                if ($classified === null) {
                    $unmatched[] = ['url' => $url, 'label' => $this->hostLabel($url)];

                    continue;
                }

                // An Instagram link found in an Instagram bio is never an
                // Instagram SUGGESTION (owner, 2026-09-03). We are here because
                // this user's own Instagram is already connected — that is how
                // we read the bio at all — so any other instagram.com URL in it
                // belongs to someone else: the salon they work at, a friend, a
                // brand. Routed like any other link it became an
                // instagram.profile candidate, and against an existing primary
                // that renders as a "Change to" swap offering to replace the
                // person's real account with the venue's.
                //
                // Dropped rather than kept as an unmatched custom link: the
                // owner's rule is that these handles are a MEANS (follow them
                // for online stores and a resolvable Google Business), not
                // something to put on the page. Chaining them is the bio-mention
                // lane's job and is not wired from here yet — logged so that
                // work can see what it would have received.
                if ($classified['platform'] === 'instagram') {
                    Log::info('instagram.bio_link.instagram_not_suggested', [
                        'user_id' => $userId,
                        'host' => parse_url($url, PHP_URL_HOST),
                    ]);

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
                // UNLESS the route says handled (F3, 2026-08-20): a seeded
                // event/media POOL ITEM and a filed ordering/reservation Swap
                // offer all return custom(handled: true), which per
                // RouteResult's own contract means "carried elsewhere — no
                // caller writes a card for it". Surfacing those built a
                // duplicate link card beside the real item/offer.
                if ($result->outcome === 'custom' && $result->unmatched === [] && ! $result->handled) {
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

        return ['findings' => $findings, 'unmatched' => $unmatched, 'scans' => $scans];
    }

    /** Override — adds Facebook normalizer support to the trait's implementation. */
    protected function socialUsername(string $platform, string $url): ?string
    {
        if ($platform === 'facebook') {
            // Delegate to the same parser the manual connect form uses (G4-4) —
            // a standalone regex here shares its blind spot for reserved path
            // segments (pages/people/etc.).
            $parsed = ($this->facebookNormalizer)($url);
            $username = (string) ($parsed['username'] ?? '');

            return $username !== '' ? $username : null;
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
