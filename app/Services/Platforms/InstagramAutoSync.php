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

// Seeds social + booking connections from the links found in an Instagram bio —
// mirrors GoogleBusinessAutoSync::seed()'s shape (only-if-empty seeding,
// persisted findings for the connect modal, conflict apply = remove existing +
// write found link) but classifies each link independently by host via
// WebsiteLinkHarvester::classify() rather than a structured Google enrichment.
//
// Scope (deliberate): only platforms whose connection payload is safely
// buildable from a bare URL are auto-synced — social handles (facebook /
// tiktok / x / linkedin) and booking (fresha / square), matching
// GoogleBusinessAutoSync's own resolveBookingWrite payload shapes exactly.
// classify() also recognises reservation (opentable / resdiary / nowbookit),
// online-ordering, youtube, pinterest and instagram hosts — those need either
// provider-specific parsing (a reservation embed needs a rid, GB's own
// resolveReservationWrite calls into OpenTableService/etc. for that) or a real
// scrape (youtube/pinterest, same reason GoogleBusinessAutoSync::seedSocials
// never syncs them either) to render a working card, so a bio link that
// classifies to one of those surfaces in `unmatched` instead — a safe "add as
// custom link" suggestion rather than a half-built card.
//
// Capability split (mirrors GoogleBusinessAutoSync::seed exactly): the SOCIAL
// bucket is a Business-Partna convenience, gated on the same capability GB's
// socials tier uses — AccountCapabilities::google_business_full_sync. A
// standard (partna) account's classified social links fall through to
// `unmatched` (still offered as custom links, never silently dropped). Booking
// is gated on can_use_booking (2026-07-15 sector gating: food-sector
// businesses don't use booking) — gated links ALSO fall to `unmatched`,
// mirroring GB's now-gated seedBooking.
//
// Best-effort: each link is isolated in its own try/catch so one bad link
// never blocks the rest (mirrors GoogleBusinessAutoSync's per-seed try/catch).
class InstagramAutoSync
{
    use BuildsAutoSyncFindings;

    /** Platforms this service knows how to seed from a bare URL. Category per platform mirrors GoogleBusinessAutoSync's finding categories. */
    private const ACTIONABLE = [
        'facebook' => 'social', 'tiktok' => 'social', 'x' => 'social', 'linkedin' => 'social',
        'fresha' => 'booking', 'square' => 'booking',
    ];

    /** Mutually-exclusive booking providers — mirrors FreshaController/SquareController::hasConflictingConnection()'s XOR and GoogleBusinessAutoSync::BOOKING_PLATFORMS. */
    private const BOOKING_PLATFORMS = [Platform::Fresha->value, Platform::Square->value];

    public function __construct(
        private readonly WebsiteLinkHarvester $harvester,
        private readonly FacebookNormalizer $facebookNormalizer,
        private readonly LinkInBioDetector $linkInBioDetector,
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
        // Dominant case today: the Apify actor returns no bio fields at all, so
        // the connect job calls this with []. Skip the user lookup entirely.
        if ($bioLinks === []) {
            return ['findings' => [], 'unmatched' => []];
        }

        // A missing user reads as no capability — fail closed, below, exactly
        // like handleClassifiedLink()'s own capability derivation would if it
        // could resolve a $user at all.
        $user = User::find($userId);

        $findings = [];
        $unmatched = [];
        $seenPlatforms = [];

        foreach ($bioLinks as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);

            try {
                if ($this->linkInBioDetector->matches($url)) {
                    // A curated link-in-bio page (Linktree/Milkshake/Beacons/Stan
                    // Store) isn't itself classifiable — it's a page to unroll, not
                    // a platform to connect. Scanned async (its own fetch can be
                    // slow/JS-heavy) rather than inline here, which would risk
                    // blowing InstagramConnectJob's timeout. Nothing about the
                    // bio-link URL itself is persisted; see LinkInBioScanJob.
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

                $this->handleClassifiedLink($user, $classified, $url, $seenPlatforms, $findings, $unmatched);
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
            // DISC-7: don't auto-create platform connections from a scraped bio for a
            // not-yet-consenting provisional (unclaimed) subject — surfaced as an
            // unmatched custom-link suggestion instead (same routing as a capability-gated link).
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

    // applyFinding() / seededFinding() / conflictFinding() / write() moved to
    // BuildsAutoSyncFindings (SLOP-101) — was byte-identical with GoogleBusinessAutoSync.

    /** @return array{platform:string, resourceId:string, payload:array<string,mixed>} */
    private function resolveWrite(string $platform, string $url): array
    {
        if ($platform === Platform::Fresha->value) {
            return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
                'url' => $url, 'selection' => null, 'source' => 'instagram',
            ]];
        }
        if ($platform === Platform::Square->value) {
            return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
                'url' => $url, 'source' => 'instagram',
            ]];
        }

        // Social (facebook / tiktok / x / linkedin) — same {username, url, source} shape as GoogleBusinessAutoSync::seedSocials.
        return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
            'username' => $this->socialUsername($platform, $url), 'url' => $url, 'source' => 'instagram',
        ]];
    }

    /**
     * LIFE-106: the booking-category span run under BuildsAutoSyncFindings::
     * withBookingXorLock() — conflicting-provider check, existing-connection
     * lookup (incl. the soft-delete tombstone guard), and the write, all
     * behind the one lock. See the call site in handleClassifiedLink() for why
     * this can't be split (only the write locked) — the has-then-write shape is
     * exactly the race the lock exists to close.
     *
     * @param  array{platform:string,resourceId:string,payload:array<string,mixed>}  $write
     * @param  array{platform:string,category:string,label:string}  $classified
     * @return array{findings:list<array<string,mixed>>,unmatched:list<array<string,mixed>>,consumed:bool}
     */
    private function resolveBookingLink(string $userId, string $platform, string $url, array $write, array $classified): array
    {
        // XOR invariant (FreshaController/SquareController::
        // hasConflictingConnection() both 409 the other way): only one booking
        // provider may be live at a time. Mirrors GoogleBusinessAutoSync::
        // seedBooking's group-level check — unlike the same-platform branch
        // below, GB's own group check never compares urls (there's no
        // meaningful "same link" across two different providers), so neither
        // do we here: any OTHER live booking connection always conflicts,
        // never a silent write of a second live provider.
        $conflictingBooking = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->whereIn('platform', self::BOOKING_PLATFORMS)
            ->where('platform', '!=', $platform)
            ->first();

        if ($conflictingBooking !== null) {
            return [
                'findings' => [$this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                    'remove' => self::BOOKING_PLATFORMS,
                    'write' => $write,
                ])],
                'unmatched' => [],
                'consumed' => true,
            ];
        }

        $existing = IntegrationConnection::query()
            ->where('user_id', $userId)->where('platform', $platform)
            ->first();

        if ($existing === null) {
            // A soft-deleted row means the user explicitly disconnected this
            // platform before (ManagesIntegrationConnection::forgetConnection()
            // soft-deletes on disconnect) — a tombstone, not "never connected".
            // Respect it: route the link to unmatched instead of resurrecting it.
            $wasDisconnected = IntegrationConnection::onlyTrashed()
                ->where('user_id', $userId)->where('platform', $platform)
                ->exists();

            if ($wasDisconnected) {
                return ['findings' => [], 'unmatched' => [['url' => $url, 'label' => $classified['label']]], 'consumed' => true];
            }

            $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

            return [
                'findings' => [$this->seededFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url)],
                'unmatched' => [],
                'consumed' => true,
            ];
        }

        $existingUrl = CardPayload::fromArray($existing->payload)->url();
        if ($existingUrl !== null && $this->sameUrl($existingUrl, $url)) {
            return ['findings' => [], 'unmatched' => [], 'consumed' => true]; // already synced with the same link — nothing to surface
        }

        return [
            'findings' => [$this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                'remove' => [$write['platform']],
                'write' => $write,
            ])],
            'unmatched' => [],
            'consumed' => true,
        ];
    }

    /** Best-effort handle from a canonical social profile URL ('' when none) — mirrors GoogleBusinessAutoSync::socialUsername. */
    private function socialUsername(string $platform, string $url): string
    {
        if ($platform === 'facebook') {
            // Delegate to the same parser the manual connect form uses (G4-4) —
            // see GoogleBusinessAutoSync::socialUsername()'s identical fix; this
            // was a byte-for-byte copy of that same standalone regex, sharing
            // its blind spot for reserved path segments (pages/people/etc.).
            $parsed = ($this->facebookNormalizer)($url);

            return $parsed['username'] ?? '';
        }

        $patterns = [
            'tiktok' => '~tiktok\.com/@?([A-Za-z0-9._]+)~i',
            'x' => '~(?:twitter|x)\.com/([A-Za-z0-9_]+)~i',
            'linkedin' => '~linkedin\.com/(?:in|company)/([A-Za-z0-9-]+)~i',
        ];
        if (isset($patterns[$platform]) && preg_match($patterns[$platform], $url, $m)) {
            return strtolower($m[1]) === 'profile.php' ? '' : $m[1];
        }

        return '';
    }

    private function sameUrl(string $a, string $b): bool
    {
        return strtolower(rtrim(trim($a), '/')) === strtolower(rtrim(trim($b), '/'));
    }

    /** Domain-derived fallback label for a genuinely unclassified link ("linktr.ee", not the full URL). */
    private function hostLabel(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('~^www\.~i', '', $host) ?? $host;

        return $host !== '' ? $host : $url;
    }
}
