<?php

namespace App\Jobs\PreAccount;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\FreshaWorkplaceLinker;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\RouteContext;
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
 * Dispatched ~10 min after the build so the FRESHA → workplace path keeps
 * precedence (owner: bio workplace fills only when still empty). Chained
 * scrapes are capped and globally CACHED per handle — @andisco_aunz appears
 * in hundreds of barber bios; it is scraped once, not per signup.
 */
class BioMentionChainsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Chained (paid) scrapes per build, at most. */
    public const MAX_CHAINED_SCRAPES = 3;

    public const CACHE_TTL_DAYS = 14;

    public const DISPATCH_DELAY_SECONDS = 600;

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
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(
        InstagramScraper $scraper,
        FreshaWorkplaceLinker $linker,
        LinkRouter $router,
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

        $mentions = $this->mentions;
        if ($mentions === []) {
            $connection = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', Platform::Instagram->value)
                ->first();
            $mentions = (array) data_get($connection?->payload, 'bioMentions', []);
        }
        if ($mentions === []) {
            return;
        }

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
                $handleName = ucwords(str_replace(['_', '.'], ' ', $handle));
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
            try {
                $result = $router->route($user, $website, new RouteContext);
                Log::info('bio_mention.brand_chain', [
                    'user_id' => $this->userId,
                    'mention' => $handle,
                    'website' => $website,
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
