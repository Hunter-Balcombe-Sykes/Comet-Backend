<?php

namespace App\Jobs\PreAccount;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\FreshaWorkplaceLinker;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\ScrapeCreators\FindSocialProfilesClient;
use App\Services\PreAccount\BuildProgress;
use App\Services\Shop\DiscountCodeAdopter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * T14 (2026-08-27, D10): act on the classified bio @mentions the build's
 * bio-intelligence pass stored on the instagram connection payload.
 *
 *  - workplace mention + user has NO workplace: chained scrape of the
 *    mentioned account → name/address/postcode/phone from ITS bio →
 *    FreshaWorkplaceLinker::attempt() (the same Places search + triple
 *    agreement + connect + workplace write the Fresha path uses; it also
 *    carries the capability gate and the GBP-already-connected skip).
 *  - brand mention: chained scrape → the brand's website → LinkRouter (the
 *    commerce probe lane), so a real store connects with the pre-account
 *    publishing default (D10: publishing ON) and anything weaker lands in
 *    the routing-suggestions machinery via the router's own gating.
 *
 * Dispatched AT ready since Item 9a (2026-09-01) — Fresha precedence is
 * state-gated (see FRESHA_RECHECK_SECONDS), not clock-gated; the owner rule
 * "bio workplace fills only when still empty" holds via hasWorkplace() and
 * the linker's own google_already_connected guard. Chained scrapes are
 * capped and globally CACHED per handle — @andisco_aunz appears in hundreds
 * of barber bios; it is scraped once, not per signup. Item 4-EXT tries a
 * Places-first one-hop before every scrape, so the common case spends no
 * chained scrape at all.
 */
class BioMentionChainsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Chained (paid) scrapes per build, at most. */
    public const MAX_CHAINED_SCRAPES = 3;

    public const CACHE_TTL_DAYS = 14;

    public int $tries = 1;

    /** @var list<int> moot at one attempt; declared for the job-hygiene policy. */
    public array $backoff = [60];

    public int $timeout = 240;

    public int $uniqueFor = 3600;

    /**
     * Mentions ride the JOB, not the connection payload: the async Instagram
     * connect's fetch writes the payload wholesale minutes after the
     * generator merged bioMentions in, clobbering them before this job's
     * +10-min run could read them (verified on the 2026-08-27 acceptance
     * round — the job drained with zero chain logs). The payload copy stays
     * as best-effort telemetry only.
     *
     * @param  list<array{handle: string, label: string, type: string}>  $mentions
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $mentions = [],
        // Item 9a: how many 30s Fresha-pending deferrals this dispatch has
        // already taken. Rides the job for the same reason mentions do.
        public readonly int $deferrals = 0,
        /** lowercased brand handle => discount code found beside it in the bio (batch 3 E.3). @var array<string, string> */
        public readonly array $codes = [],
    ) {
        $this->onQueue(config('partna.queues.signup', 'signup'));
    }

    /**
     * Item 9a (2026-09-01): the job now dispatches AT ready (the flat 600s
     * delay is gone) and yields to a still-in-flight auto Fresha connect by
     * STATE, not clock: while the user's Fresha row still wears
     * payload.connectMode=auto (stamped by AutoBookingConnectDispatcher,
     * stripped when the fetch settles, row deleted on abandonment), the
     * chain re-queues itself in 30s steps up to MAX_FRESHA_DEFERRALS. Past
     * the cap it runs anyway — the old timer only ever outlasted Fresha's
     * FIRST retry tier (5m/15m/45m/2h), so "wait forever" was never the
     * contract; hasWorkplace() and the linker's google_already_connected
     * guard keep precedence for every later race exactly as before.
     */
    public const FRESHA_RECHECK_SECONDS = 30;

    public const MAX_FRESHA_DEFERRALS = 10;

    public function uniqueId(): string
    {
        // Per-deferral-generation lock: a deferral re-dispatch from inside
        // handle() must not be swallowed by the lock the RUNNING generation
        // still holds. Duplicate build dispatches still collapse — both
        // arrive at generation 0.
        return $this->userId.':'.$this->deferrals;
    }

    public function handle(
        InstagramScraper $scraper,
        FreshaWorkplaceLinker $linker,
        LinkRouter $router,
        FindSocialProfilesClient $discovery,
    ): void {
        try {
            $this->run($scraper, $linker, $router, $discovery);
        } finally {
            // Setup progress (2026-09-02): a chain that started (its own note or
            // the dispatch's) always answers — landed when a workplace exists
            // (the one-shot dedupe makes this a no-op after the linker's own
            // row), skipped when none was found. A chain that ran after "done"
            // (liam, +87s) left the row open for good otherwise.
            $owner = User::query()->find($this->userId);
            if ($owner !== null) {
                $has = $this->hasWorkplace($owner);
                BuildProgress::noteForUser(
                    (string) $owner->id,
                    PreAccountBuildEvent::STAGE_WORKPLACE,
                    $has ? PreAccountBuildEvent::STATUS_LANDED : PreAccountBuildEvent::STATUS_SKIPPED,
                    $has ? 'Found where you work' : 'No workplace found from your bio yet',
                );
            }
        }
    }

    private function run(
        InstagramScraper $scraper,
        FreshaWorkplaceLinker $linker,
        LinkRouter $router,
        FindSocialProfilesClient $discovery,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }
        // The workplace chain is a partna concept — for a business the
        // workplace IS the account (and came from its own listing).
        if (AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            return;
        }

        // Item 9a: state-gated Fresha precedence (see the constants' doc).
        if ($this->deferrals < self::MAX_FRESHA_DEFERRALS && $this->freshaAutoConnectPending($user)) {
            Log::info('bio_mention.deferred_for_fresha', [
                'user_id' => $this->userId,
                'deferrals' => $this->deferrals + 1,
            ]);
            self::dispatch($this->userId, $this->mentions, $this->deferrals + 1, $this->codes)
                ->delay(self::FRESHA_RECHECK_SECONDS);

            return;
        }

        $mentions = $this->mentions;
        if ($mentions === []) {
            $connection = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', Platform::Instagram->value)
                ->first();
            $mentions = (array) data_get($connection?->payload, 'bioMentions', []);
        }
        if ($mentions === []) {
            // Setup progress (2026-09-02): said plainly, so the feed's
            // workplace row is never a spinner waiting for nothing. Skipped
            // only when nothing else found one either.
            if (! $this->hasWorkplace($user)) {
                BuildProgress::noteForUser(
                    (string) $user->id,
                    PreAccountBuildEvent::STAGE_WORKPLACE,
                    PreAccountBuildEvent::STATUS_SKIPPED,
                    'No workplace mentioned in your bio — you can add one later',
                );
            }

            return;
        }

        // Setup progress: the feed's "checking" row while the chain runs.
        BuildProgress::noteForUser(
            (string) $user->id,
            PreAccountBuildEvent::STAGE_WORKPLACE,
            PreAccountBuildEvent::STATUS_STARTED,
            'Checking '.BuildProgress::count(count($mentions), 'place mentioned', 'places mentioned').' in your bio',
            ['mentions' => array_values(array_map(
                static fn (array $m): array => ['handle' => (string) ($m['handle'] ?? ''), 'platform' => 'instagram', 'type' => (string) ($m['type'] ?? 'other')],
                array_filter($mentions, static fn ($m) => is_array($m) && ($m['handle'] ?? '') !== ''),
            ))],
        );

        // Item 4 (2026-09-01): multiple venue-shaped mentions are legal now —
        // the classifier nominates, the corroboration gate disambiguates. So
        // candidates iterate in EVIDENCE order, not bio order: explicit
        // works-there wording in the label first, then a venue token in the
        // handle, then everything else (stable within a tier). Brand mentions
        // keep their original relative order after the workplace candidates.
        $mentions = $this->orderedForProcessing($mentions);

        $scrapes = 0;
        $workplaceDone = false;
        foreach ($mentions as $mention) {
            if ($scrapes >= self::MAX_CHAINED_SCRAPES) {
                break;
            }
            $handle = (string) ($mention['handle'] ?? '');
            $type = (string) ($mention['type'] ?? 'other');
            if ($handle === '' || $type === 'other') {
                continue;
            }

            if ($type === 'workplace') {
                if ($workplaceDone || $this->hasWorkplace($user)) {
                    continue; // Fresha-linker (or an earlier mention) got there first — precedence holds.
                }

                // Item 4-EXT: Places-first one hop, BEFORE spending the paid
                // chained scrape. A bare {name} venue reaches pick()'s
                // no-corroborator branch, where a locality token in the name
                // itself must agree with the candidate's address AND the
                // single-candidate ambiguity guard must hold —
                // "Star Barber Darwin" connects in one free-ish call, while
                // "Akro Studio" (no locality token) correctly returns
                // no_match and falls through to the scrape for its postcode
                // evidence. The gate does the deciding either way.
                $handleName = ucwords(str_replace(['_', '.'], ' ', $handle));
                $oneHop = $linker->attempt($user, [
                    'name' => $handleName,
                    'street' => null, 'city' => null, 'postcode' => null,
                    'region' => null, 'country' => 'AU',
                    'lat' => null, 'lng' => null, 'phone' => null,
                ]);
                Log::info('bio_mention.workplace_chain', [
                    'user_id' => $this->userId,
                    'mention' => $handle,
                    'venue' => $handleName,
                    'outcome' => $oneHop['outcome'],
                    'reason' => $oneHop['reason'],
                    'via' => 'places_first',
                ]);
                if ($oneHop['outcome'] === 'connected') {
                    $workplaceDone = true;

                    continue;
                }
                if ($oneHop['outcome'] !== 'no_match') {
                    // skipped (google_already_connected / capability) — the
                    // scrape would meet the same wall; move on.
                    continue;
                }

                $profile = $this->mentionProfile($scraper, $handle, $scrapes);
                if ($profile === null) {
                    continue;
                }
                // Two name candidates, tried in order: the account's own
                // fullName, then the handle prettified. Round-2 acceptance
                // (2026-08-27): @star_barber_darwin's fullName is
                // "Em|Holley|Finley" (the barbers, not the venue) — the
                // handle-derived "Star Barber Darwin" is what Places knows.
                $venue = $this->venueFrom($profile, $handle);
                foreach (array_unique([$venue['name'], $handleName]) as $candidateName) {
                    $attemptVenue = ['name' => $candidateName] + $venue;
                    $outcome = $linker->attempt($user, $attemptVenue);
                    $workplaceDone = $outcome['outcome'] === 'connected';
                    Log::info('bio_mention.workplace_chain', [
                        'user_id' => $this->userId,
                        'mention' => $handle,
                        'venue' => $candidateName,
                        'outcome' => $outcome['outcome'],
                        'reason' => $outcome['reason'],
                    ]);
                    if ($outcome['outcome'] !== 'no_match') {
                        break;
                    }
                }

                continue;
            }

            // brand
            $profile = $this->mentionProfile($scraper, $handle, $scrapes);
            $website = is_string($profile['externalUrl'] ?? null) ? trim($profile['externalUrl']) : '';
            if ($website === '' || preg_match('~^https?://~i', $website) !== 1) {
                continue;
            }
            // Batch 3 E.2 (owner, 2026-09-02): a brand whose bio link is a
            // Linktree (adidasau) is not a store — read that page and take
            // its ONE store link instead. Nothing else on the page is routed.
            $importer = app(LinkInBioImporter::class);
            if ($importer->isVendorPage($website)) {
                $store = $importer->storeLinkOn($website, $user);
                Log::info('bio_mention.brand_link_in_bio', [
                    'user_id' => $this->userId,
                    'mention' => $handle,
                    'page' => $website,
                    'store' => $store,
                ]);
                if ($store === null) {
                    continue;
                }
                $website = $store;
            }
            try {
                $result = $router->route($user, $website, new RouteContext);
                Log::info('bio_mention.brand_chain', [
                    'user_id' => $this->userId,
                    'mention' => $handle,
                    'website' => $website,
                    'outcome' => $result->outcome,
                ]);
                // E.3: "@brand code LALOR" beside the mention → the store's
                // discount code (matched on the store's host; no-op when the
                // router placed nothing on that host).
                $code = $this->codes[strtolower(ltrim($handle, '@'))] ?? null;
                if ($code !== null) {
                    app(DiscountCodeAdopter::class)->adopt($user, $website, $code);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Item 11g (2026-09-01): one cross-platform discovery per build, off
        // the build's OWN Instagram identity — the loop above chained through
        // OTHER accounts; this asks the vendor what other platforms THIS
        // account verifiably has, after the mentions have had their turn at
        // the router's per-platform slots.
        $this->discoverOwnPlatforms($user, $router, $discovery);
    }

    /**
     * Feed the vendor's corroborated {platform => url} map through the SAME
     * router seam the brand chain uses, one RouteContext for the whole run
     * (RouteContext's own one-per-run rule). Additive and fail-open by the
     * lane's contract: no key, no budget slot, vendor miss and nothing-new
     * all read null, and the build simply has no discoveries. The client
     * owns the budget claim; nothing here notifies anyone.
     */
    private function discoverOwnPlatforms(User $user, LinkRouter $router, FindSocialProfilesClient $discovery): void
    {
        $igHandle = (string) data_get(IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Instagram->value)
            ->value('payload'), 'username', '');
        if ($igHandle === '') {
            return;
        }

        $map = $discovery->discover('instagram', $igHandle, $this->userId);
        if ($map === null) {
            return;
        }

        $ctx = new RouteContext;
        foreach ($map as $platform => $url) {
            try {
                $result = $router->route($user, $url, $ctx);
                Log::info('bio_mention.discovery_chain', [
                    'user_id' => $this->userId,
                    'platform' => $platform,
                    'url' => $url,
                    'outcome' => $result->outcome,
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The mentioned account's identity essentials, cached GLOBALLY per handle
     * (the same brand/venue appears across many users' bios — one paid
     * scrape, shared). $scrapes only counts cache misses.
     *
     * @return array{fullName: ?string, biography: ?string, externalUrl: ?string}|null
     */
    private function mentionProfile(InstagramScraper $scraper, string $handle, int &$scrapes): ?array
    {
        $key = 'bio_mention_profile:'.$handle;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $scrapes++;
        $result = $scraper->fetchProfileResult($handle, $this->userId);
        $profile = $result->profile;
        if ($profile === null) {
            return null;
        }

        $essentials = [
            'fullName' => data_get($profile, 'fullName') ?? data_get($profile, 'full_name'),
            'biography' => data_get($profile, 'biography') ?? data_get($profile, 'bio'),
            'externalUrl' => data_get($profile, 'externalUrl') ?? data_get($profile, 'external_url'),
        ];
        Cache::put($key, $essentials, now()->addDays(self::CACHE_TTL_DAYS));

        return $essentials;
    }

    private function hasWorkplace(User $user): bool
    {
        $siteId = Site::query()->where('user_id', $user->id)->value('id');

        return $siteId !== null && Workplace::query()->whereKey($siteId)->exists();
    }

    /**
     * Item 9a: is a system-initiated Fresha connect still in flight? The
     * marker's lifecycle makes this a clean state read: connectMode=auto is
     * stamped at dispatch (AutoBookingConnectDispatcher), stripped from the
     * payload when the fetch settles either branch, and the whole row is
     * deleted when the retry ladder abandons — so every terminal state reads
     * false and only genuine in-flight reads true.
     */
    private function freshaAutoConnectPending(User $user): bool
    {
        $payload = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Fresha->value)
            ->value('payload');

        return is_array($payload) && ($payload['connectMode'] ?? null) === 'auto';
    }

    /**
     * Item 4: workplace candidates in evidence order — explicit works-there
     * wording beats a venue-shaped handle beats bio order — with brand/other
     * mentions after them in their original relative order. Sorting is
     * stable (index tiebreak), so equal-evidence candidates keep bio order.
     *
     * @param  list<array{handle?: string, label?: string, type?: string}>  $mentions
     * @return list<array{handle?: string, label?: string, type?: string}>
     */
    private function orderedForProcessing(array $mentions): array
    {
        $workplace = [];
        $rest = [];
        foreach ($mentions as $i => $mention) {
            if ((string) ($mention['type'] ?? 'other') === 'workplace') {
                $workplace[] = [$this->evidenceTier($mention), $i, $mention];
            } else {
                $rest[] = $mention;
            }
        }

        usort($workplace, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [...array_column($workplace, 2), ...$rest];
    }

    private function evidenceTier(array $mention): int
    {
        $label = mb_strtolower((string) ($mention['label'] ?? ''));
        if ($label !== '' && preg_match('/owner|work|cut|based|resident|found|my (shop|salon|studio|store)/u', $label) === 1) {
            return 0;
        }
        $handle = mb_strtolower((string) ($mention['handle'] ?? ''));
        if (preg_match('/studio|salon|barber|shop|store|clinic|spa/u', $handle) === 1) {
            return 1;
        }

        return 2;
    }

    /**
     * A linker venue from the mentioned account's own bio: its name, plus the
     * corroborators pick() needs — an address-ish line, an AU postcode, a
     * phone-shaped digit run.
     *
     * @param  array{fullName: ?string, biography: ?string, externalUrl: ?string}  $profile
     * @return array{name: string, street: ?string, city: ?string, postcode: ?string, region: ?string, country: ?string, lat: null, lng: null, phone: ?string}
     */
    private function venueFrom(array $profile, string $handle): array
    {
        $bio = (string) ($profile['biography'] ?? '');
        $name = trim((string) ($profile['fullName'] ?? '')) ?: str_replace(['_', '.'], ' ', $handle);

        $street = null;
        foreach (preg_split('/\r?\n/', $bio) ?: [] as $line) {
            $line = trim($line);
            // An address-ish line: digits plus street/venue wording.
            if ($line !== '' && preg_match('/\d/', $line) === 1 && mb_strlen($line) >= 12
                && preg_match('/street|st\b|road|rd\b|avenue|ave\b|mall|arcade|lane|ln\b|shop|level|suite/i', $line) === 1) {
                $street = mb_substr($line, 0, 160);
                break;
            }
        }

        preg_match('/\b(0[289]\d{2}|[1-9]\d{3})\b(?!.*\b\d{4}\b)/s', $bio, $pc);
        preg_match('/(?:\+?61|0)[\d\s()-]{8,}/', $bio, $ph);

        return [
            'name' => $name,
            'street' => $street,
            'city' => null,
            'postcode' => $pc[1] ?? null,
            'region' => null,
            'country' => 'AU',
            'lat' => null,
            'lng' => null,
            'phone' => isset($ph[0]) ? trim($ph[0]) : null,
        ];
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::warning('bio_mention.chains_failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
