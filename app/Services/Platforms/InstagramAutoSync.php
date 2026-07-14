<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
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
// syncs for EVERY account type, exactly like GB's seedBooking.
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

    public function __construct(private readonly WebsiteLinkHarvester $harvester) {}

    /**
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
        // uses (see class docblock). A missing user reads as no capability —
        // fail closed, socials fall through to unmatched. Booking is universal.
        $user = User::find($userId);
        $canSyncSocial = $user !== null && AccountCapabilities::for($user)->google_business_full_sync;

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
                if (isset($seenPlatforms[$platform])) {
                    continue; // first HANDLED bio link per platform wins this run
                }

                $write = $this->resolveWrite($platform, $url);
                $existing = IntegrationConnection::query()
                    ->where('user_id', $userId)->where('platform', $platform)
                    ->first();

                if ($existing === null) {
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
        $patterns = [
            'facebook' => '~facebook\.com/([A-Za-z0-9.]+)~i',
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
