<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Jobs\Platforms\ProbeCommerceLinksJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
use Throwable;

// Seeds social + booking connections from the links found in an Instagram bio.
//
// 2026-07-25 refactor: classification and routing now live in LinkRouter.
// This class delegates to LinkRouter::route() for each bio link and translates
// the result back to the findings/unmatched contract the Instagram synced modal
// expects. ACTIONABLE was removed; capability gating and RULING 1 were repealed
// (Decisions 5 + 8). Social auto-sync now works for all account types.
//
// Best-effort: each link is isolated in its own try/catch.
class InstagramAutoSync
{
    use BuildsAutoSyncFindings;

    /** Mutually-exclusive booking providers — now lives on BuildsAutoSyncFindings. */
    // BOOKING_PLATFORMS and ACTIONABLE moved/removed (Phase 2.5 + 7, 2026-07-25).

    public function __construct(
        private readonly WebsiteLinkHarvester $harvester,
        private readonly FacebookNormalizer $facebookNormalizer,
        private readonly LinkInBioDetector $linkInBioDetector,
        private readonly LinkRouter $router,
    ) {}

    /**
     * Contract: $userId MUST be server-derived — the real caller is
     * InstagramConnectionSeeder::seed() (line ~159), itself invoked with the
     * $userId InstagramConnectJob was dispatched with — never raw request
     * input. There is no ownership check inside this method (it writes
     * IntegrationConnection rows keyed on the given id unconditionally); a
     * future controller-invoked caller must authorizeForUser($user, 'update', ...)
     * at the call site before reaching here, the same way every other
     * mutating controller path does.
     *
     * @param  list<mixed>  $bioLinks  raw bio links (InstagramScraper::bioLinks() output — defensively typed here too)
     * @return array{findings: list<array<string,mixed>>, unmatched: list<array<string,mixed>>}
     */
    public function seed(string $userId, array $bioLinks): array
    {
        if ($bioLinks === []) {
            return ['findings' => [], 'unmatched' => []];
        }

        $user = User::find($userId);

        $findings = [];
        $unmatched = [];
        $ctx = new \App\Services\Platforms\RouteContext(maxProbes: 6);

        foreach ($bioLinks as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);

            try {
                if ($this->linkInBioDetector->matches($url)) {
                    LinkInBioScanJob::dispatch($userId, $url);

                    continue;
                }

                $classified = $this->harvester->classify($url);
                if ($classified === null) {
                    $unmatched[] = ['url' => $url, 'label' => $this->hostLabel($url)];

                    continue;
                }

                if ($user === null) {
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                    continue;
                }

                // Route through LinkRouter — creates the typed connection or
                // falls back to custom link. Findings/unmatched contract
                // preserved for the Instagram synced modal.
                $result = $this->router->route($user, $url, $ctx);

                if ($result->outcome === 'seeded') {
                    $findings[] = $this->seededFinding(
                        $result->platform, $result->resourceId, $result->category,
                        $classified['label'], $url,
                    );
                } elseif ($result->outcome === 'custom' || $result->outcome === 'pending') {
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['findings' => $findings, 'unmatched' => $unmatched];
    }

    /**
     * The real per-link handling for a URL ALREADY classified into a known
     * platform — capability gating, dedup, conflict/seed decision, tombstone
     * check. Shared by seed()'s own loop and LinkInBioScanJob (BE2 link-in-bio
     * scan), so a link found one hop into an unrolled link-in-bio page gets
     * IDENTICAL gating to one found directly in the bio.
     *
     * Capability flags are resolved INTERNALLY from $user — never accepted as
     * caller-supplied booleans. That's deliberate: a parameter shape like
     * `bool $canSyncSocial` would let any new caller pass `true` without
     * actually checking anything, reintroducing the exact "gate lives in the
     * wrapper, not the thing that does the write" bug this extraction exists
     * to avoid. Passing the resolved User (not a raw string id) also means
     * AccountCapabilities::for()'s per-instance memoization still works across
     * repeated calls sharing one $user in a loop, instead of re-querying per link.
     *
     * Booking links run the LIFE-106 XOR-locked span (withBookingXorLock +
     * resolveBookingLink below) — the lock arrived after this method was
     * extracted, so both of its callers inherit it for free.
     *
     * Fault-isolated: a throw here is reported and swallowed, never bubbles to
     * the caller's loop — mirrors seed()'s original per-link try/catch, now
     * owned by the method itself so every caller gets it for free.
     *
     * @param  array{platform:string, category:string, label:string}  $classified
     * @param  array<string,true>  $seenPlatforms
     * @param  list<array<string,mixed>>  $findings
     * @param  list<array<string,mixed>>  $unmatched
     */
    public function handleClassifiedLink(User $user, array $classified, string $url, array &$seenPlatforms, array &$findings, array &$unmatched): void
    {
        try {
            $userId = (string) $user->id;
            $caps = AccountCapabilities::for($user);
            // Auto-sync consent gate removed 2026-07-25 — unclaimed users now
            // auto-sync identically to claimed users. The capability is always true.
            $canAutosync = $caps->can_autosync_scraped_connections;
            $canSyncSocial = $caps->google_business_full_sync && $canAutosync;
            $canSyncBooking = $caps->can_use_booking && $canAutosync;

            $platform = $classified['platform'];

            // Commerce categories (signup-v2 C3): stores / standalone events /
            // organiser accounts become typed adds via ProbeCommerceLinksJob —
            // their page fetches must never ride inside the connect job's own
            // timeout budget. Consent-gated exactly like socials (DISC-7): a
            // not-yet-consenting subject keeps the link as a suggestion. The
            // job re-verifies the gate at run time and downgrades to a custom
            // link on any miss, so nothing vanishes.
            if (in_array($classified['category'], ['shop', 'event', 'event-organiser'], true)) {
                if (! $canAutosync) {
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                    return;
                }
                if (isset($seenPlatforms[$platform])) {
                    return; // first commerce link per platform wins this run
                }
                ProbeCommerceLinksJob::dispatch($userId, $url, $classified['category'], $platform);
                $seenPlatforms[$platform] = true;

                return;
            }

            if (! isset(self::ACTIONABLE[$platform])) {
                // Recognised (e.g. YouTube, OpenTable, Instagram) but not
                // something this service auto-syncs — see class docblock.
                $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                return;
            }
            if (self::ACTIONABLE[$platform] === 'social' && ! $canSyncSocial) {
                // Capability-gated (RULING 1): a standard account keeps the
                // link as a custom-link suggestion instead of an auto-synced
                // connection — surfaced, never silently dropped.
                $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                return;
            }
            if (self::ACTIONABLE[$platform] === 'booking' && ! $canSyncBooking) {
                // Sector-gated (2026-07-15): a food-sector business doesn't
                // use booking — same unmatched routing as gated socials, so
                // the link still surfaces as a custom-link suggestion.
                $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                return;
            }
            if (isset($seenPlatforms[$platform])) {
                return; // first HANDLED bio link per platform wins this run
            }

            $write = $this->resolveWrite($platform, $url);

            if (self::ACTIONABLE[$platform] === 'booking') {
                // LIFE-106: unlike social (below), booking's XOR invariant
                // spans THREE platforms (fresha/square/booking), so the
                // WHOLE check-then-write span — conflicting-provider query,
                // existing-row lookup, tombstone check, and the write —
                // must run under ONE lock shared with
                // GoogleBusinessAutoSync::seedBooking (BuildsAutoSyncFindings::
                // withBookingXorLock; see its docblock for why a per-platform
                // lock can't serialize this). On contention the link is
                // routed to unmatched rather than dropped — matches this
                // file's "still offered as a custom link, never silently
                // dropped" contract.
                $result = $this->withBookingXorLock(
                    $userId,
                    fn () => $this->resolveBookingLink($userId, $platform, $url, $write, $classified),
                    ['findings' => [], 'unmatched' => [['url' => $url, 'label' => $classified['label']]], 'consumed' => true],
                );

                foreach ($result['findings'] as $finding) {
                    $findings[] = $finding;
                }
                foreach ($result['unmatched'] as $miss) {
                    $unmatched[] = $miss;
                }
                if ($result['consumed']) {
                    $seenPlatforms[$platform] = true;
                }

                return;
            }

            // Social (facebook/tiktok/x/linkedin): no lock — each is its own
            // platform, already serialized by the per-platform DB unique
            // index (idx_platform_connections_unique_active), so a lock here
            // would add contention for no correctness gain (see
            // withBookingXorLock's docblock for the contrast with booking).
            $existing = IntegrationConnection::query()
                ->where('user_id', $userId)->where('platform', $platform)
                ->first();

            if ($existing === null) {
                // A soft-deleted row means the user explicitly disconnected
                // this platform before (ManagesIntegrationConnection::
                // forgetConnection() soft-deletes on disconnect) — a
                // tombstone, not "never connected". The default Eloquent
                // scope excludes it, so treating "no live row" as blank
                // slate would silently resurrect a connection the user
                // chose to remove. Respect it: route the link to unmatched
                // instead (still addable manually). NOTE: GoogleBusiness-
                // AutoSync has the same gap (no trashed-check either) —
                // parity fix deferred to the owner.
                $wasDisconnected = IntegrationConnection::onlyTrashed()
                    ->where('user_id', $userId)->where('platform', $platform)
                    ->exists();

                if ($wasDisconnected) {
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];
                    $seenPlatforms[$platform] = true;

                    return;
                }

                $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
                $findings[] = $this->seededFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url);
                // Consume the platform slot only AFTER the write succeeded —
                // a caught throw (query/write above) must leave it open so a
                // later same-platform link in this run still gets its attempt.
                $seenPlatforms[$platform] = true;

                return;
            }

            // An existing row was found: both outcomes below (same-url skip,
            // conflict finding) are handled, throw-free array work — consume
            // the slot now.
            $seenPlatforms[$platform] = true;

            $existingUrl = CardPayload::fromArray($existing->payload)->url();
            if ($existingUrl !== null && $this->sameUrl($existingUrl, $url)) {
                return; // already synced with the same link — nothing to surface
            }

            $findings[] = $this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                'remove' => [$write['platform']],
                'write' => $write,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // resolveWrite(), resolveBookingLink(), sameUrl() moved to
    // BuildsAutoSyncFindings (Phase 2.5, 2026-07-25). socialUsername()
    // stays as an override adding Facebook normalizer support.

    /** Override — adds Facebook normalizer to the trait's base implementation. */
    protected function socialUsername(string $platform, string $url): string
    {
        if ($platform === 'facebook') {
            $parsed = ($this->facebookNormalizer)($url);

            return $parsed['username'] ?? '';
        }

        return \App\Services\Platforms\Concerns\BuildsAutoSyncFindings::socialUsername($platform, $url);
    }

    /** Domain-derived fallback label for a genuinely unclassified link ("linktr.ee", not the full URL). */
    private function hostLabel(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('~^www\.~i', '', $host) ?? $host;

        return $host !== '' ? $host : $url;
    }
}
