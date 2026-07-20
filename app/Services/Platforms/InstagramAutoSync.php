<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
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

        // Social auto-sync is gated on the SAME capability GB's socials tier
        // uses (see class docblock); booking on can_use_booking (sector
        // gating). A missing user reads as no capability — fail closed, gated
        // links fall through to unmatched.
        $user = User::find($userId);
        $canSyncSocial = $user !== null && AccountCapabilities::for($user)->google_business_full_sync;
        $canSyncBooking = $user !== null && AccountCapabilities::for($user)->can_use_booking;

        $findings = [];
        $unmatched = [];
        $seenPlatforms = [];

        foreach ($bioLinks as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);

            try {
                $classified = $this->harvester->classify($url);
                if ($classified === null) {
                    $unmatched[] = ['url' => $url, 'label' => $this->hostLabel($url)];

                    continue;
                }

                $platform = $classified['platform'];
                if (! isset(self::ACTIONABLE[$platform])) {
                    // Recognised (e.g. YouTube, OpenTable, Instagram) but not
                    // something this service auto-syncs — see class docblock.
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                    continue;
                }
                if (self::ACTIONABLE[$platform] === 'social' && ! $canSyncSocial) {
                    // Capability-gated (RULING 1): a standard account keeps the
                    // link as a custom-link suggestion instead of an auto-synced
                    // connection — surfaced, never silently dropped.
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                    continue;
                }
                if (self::ACTIONABLE[$platform] === 'booking' && ! $canSyncBooking) {
                    // Sector-gated (2026-07-15): a food-sector business doesn't
                    // use booking — same unmatched routing as gated socials, so
                    // the link still surfaces as a custom-link suggestion.
                    $unmatched[] = ['url' => $url, 'label' => $classified['label']];

                    continue;
                }
                if (isset($seenPlatforms[$platform])) {
                    continue; // first HANDLED bio link per platform wins this run
                }

                $write = $this->resolveWrite($platform, $url);

                if (self::ACTIONABLE[$platform] === 'booking') {
                    // XOR invariant (FreshaController/SquareController::
                    // hasConflictingConnection() both 409 the other way): only
                    // one booking provider may be live at a time. Mirrors
                    // GoogleBusinessAutoSync::seedBooking's group-level check —
                    // unlike the same-platform branch below, GB's own group
                    // check never compares urls (there's no meaningful "same
                    // link" across two different providers), so neither do we
                    // here: any OTHER live booking connection always conflicts,
                    // never a silent write of a second live provider.
                    $conflictingBooking = IntegrationConnection::query()
                        ->where('user_id', $userId)
                        ->whereIn('platform', self::BOOKING_PLATFORMS)
                        ->where('platform', '!=', $platform)
                        ->first();

                    if ($conflictingBooking !== null) {
                        $findings[] = $this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                            'remove' => self::BOOKING_PLATFORMS,
                            'write' => $write,
                        ]);
                        $seenPlatforms[$platform] = true;

                        continue;
                    }
                }

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

                        continue;
                    }

                    $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
                    $findings[] = $this->seededFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url);
                    // Consume the platform slot only AFTER the write succeeded —
                    // a caught throw (query/write above) must leave it open so a
                    // later same-platform link in this run still gets its attempt.
                    $seenPlatforms[$platform] = true;

                    continue;
                }

                // An existing row was found: both outcomes below (same-url skip,
                // conflict finding) are handled, throw-free array work — consume
                // the slot now.
                $seenPlatforms[$platform] = true;

                $existingUrl = CardPayload::fromArray($existing->payload)->url();
                if ($existingUrl !== null && $this->sameUrl($existingUrl, $url)) {
                    continue; // already synced with the same link — nothing to surface
                }

                $findings[] = $this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                    'remove' => [$write['platform']],
                    'write' => $write,
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['findings' => $findings, 'unmatched' => $unmatched];
    }

    /**
     * "Change to" — install the bio-found connection over the user's existing
     * one. Removes whatever currently occupies the slot, then writes the found
     * link. Idempotent + best-effort (mirrors GoogleBusinessAutoSync::applyFinding).
     *
     * @param  array<string,mixed>  $finding  a conflict finding (carries `apply`)
     */
    public function applyFinding(string $userId, array $finding): void
    {
        $apply = $finding['apply'] ?? null;
        if (! is_array($apply)) {
            return;
        }

        foreach ((array) ($apply['remove'] ?? []) as $platform) {
            if (! is_string($platform)) {
                continue;
            }
            IntegrationConnection::query()
                ->where('user_id', $userId)->where('platform', $platform)
                ->get()->each->delete();
        }

        if (is_array($apply['write'] ?? null)) {
            $w = $apply['write'];
            $this->write($userId, (string) $w['platform'], (string) $w['resourceId'], (array) $w['payload']);
        }
    }

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

    /** @return array<string,mixed> */
    private function seededFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl): array
    {
        return [
            'platform' => $platform,
            'resourceId' => $resourceId,
            'category' => $category,
            'label' => $label,
            'foundUrl' => $foundUrl,
            'outcome' => 'seeded',
            'apply' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $apply
     * @return array<string,mixed>
     */
    private function conflictFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl, array $apply): array
    {
        return [
            'platform' => $platform,
            'resourceId' => $resourceId,
            'category' => $category,
            'label' => $label,
            'foundUrl' => $foundUrl,
            'outcome' => 'conflict',
            'apply' => $apply,
        ];
    }

    /** @param  array<string,mixed>  $payload */
    private function write(string $userId, string $platform, string $resourceId, array $payload): void
    {
        // Model write (not quiet): the observer purges the sitepage edge cache
        // for the newly-public row (mirrors GoogleBusinessAutoSync::write).
        IntegrationConnection::updateOrCreate(
            ['user_id' => $userId, 'platform' => $platform, 'resource_id' => $resourceId],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
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
