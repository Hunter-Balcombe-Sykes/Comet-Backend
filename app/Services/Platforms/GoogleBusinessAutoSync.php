<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\InstagramApifyBudget;
use App\Services\Platforms\Payloads\CardPayload;
use Throwable;

// Seeds Reservations / Online-ordering / Social connections from a Google
// Business Apify enrichment — only into slots the user hasn't filled, each
// tagged source:'google-business' so the connect modal's "Automatically Synced
// Integrations" step can list them with an undo.
//
// seed() RETURNS the per-connect findings (one per platform Google had a link
// for) so the modal can show only what THIS scrape produced, with a live status:
//   - outcome 'seeded'   → we wrote the row (the /synced endpoint re-derives
//                          synced vs syncing from its last_refresh_status);
//   - outcome 'conflict' → the slot was already filled by a different connection,
//                          so we didn't touch it — the finding carries an `apply`
//                          recipe so a "Change to" swap can remove the existing
//                          and install Google's (applyFinding()).
// Best-effort: every seed is isolated in its own try/catch so one failure never
// blocks the rest.
class GoogleBusinessAutoSync
{
    private const MAX_ORDERING = 10;

    public function __construct(
        private readonly OpenTableService $openTable,
        private readonly ResDiaryService $resDiary,
        private readonly NowBookitService $nowBookit,
        private readonly ProviderDetector $detector,
        private readonly InstagramApifyBudget $instagramBudget,
    ) {}

    /**
     * @param  array<string,mixed>  $enrichment  the scraper map() output (menu / reservation / order / booking / socials)
     * @param  array<string,mixed>|null  $gbPayload  the Google Business connection payload (Place Details: category / website / editorialSummary) for the workplace seed
     * @return list<array<string,mixed>> the per-connect findings (see class doc)
     */
    public function seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null): array
    {
        $findings = [];

        // Booking is the one platform synced for EVERY account type (only-if-empty):
        // a professional with no booking link yet still gets the one Google has on
        // file, regardless of whether they're a Business Partna.
        $findings = [...$findings, ...$this->seedBooking($userId, $enrichment, $businessName)];

        // Reservations / online-ordering / workplace / socials are a Business-Partna
        // convenience. A standard (partna) account only gets the booking link above —
        // it's never handed reservation/ordering/menu cards it doesn't surface. Gate
        // on the capability so the account_type read stays inside AccountCapabilities.
        $user = User::find($userId);
        if ($user === null || ! AccountCapabilities::for($user)->google_business_full_sync) {
            return $findings;
        }

        $findings = [...$findings, ...$this->seedReservation($userId, $enrichment, $businessName)];
        $findings = [...$findings, ...$this->seedOrdering($userId, $enrichment)];
        $this->seedWorkplace($userId, $gbPayload ?? []);
        $findings = [...$findings, ...$this->seedSocials($userId, $enrichment)];

        return $findings;
    }

    /**
     * "Change to" — install Google's found connection over the user's existing
     * one. Removes whatever currently occupies the slot, then writes Google's
     * (or re-dispatches the Instagram scrape). Idempotent + best-effort.
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

        if (is_array($apply['instagram'] ?? null) && is_string($apply['instagram']['username'] ?? null)) {
            $this->dispatchInstagram($userId, $apply['instagram']['username']);

            return;
        }

        if (is_array($apply['write'] ?? null)) {
            $w = $apply['write'];
            $this->write($userId, (string) $w['platform'], (string) $w['resourceId'], (array) $w['payload']);
        }
    }

    // ── reservation ──────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function seedReservation(string $userId, array $enrichment, ?string $businessName): array
    {
        try {
            $write = $this->resolveReservationWrite($enrichment, $businessName);
            if ($write === null) {
                return [];
            }

            $label = $write['payload']['name'] ?? 'Reservations';
            if ($this->hasAnyReservation($userId)) {
                return [$this->conflictFinding($write['platform'], $write['resourceId'], 'reservations', is_string($label) ? $label : 'Reservations', $this->urlOf($write), [
                    'remove' => ['opentable', 'resdiary', 'nowbookit', 'reservations'],
                    'write' => $write,
                ])];
            }

            $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

            return [$this->seededFinding($write['platform'], $write['resourceId'], 'reservations', is_string($label) ? $label : 'Reservations', $this->urlOf($write))];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Resolve the single reservation row Google's data would write — a keyless
     * provider (OpenTable / ResDiary / NowBookit) or a branded custom card — or
     * null when there's nothing usable. Pure: no DB writes, so it can be reused to
     * build a conflict's "Change to" recipe.
     *
     * @return array{platform:string, resourceId:string, payload:array<string,mixed>}|null
     */
    private function resolveReservationWrite(array $enrichment, ?string $businessName): ?array
    {
        $reservation = data_get($enrichment, 'reservation');
        if (! is_array($reservation)) {
            return null;
        }

        $candidates = array_values(array_filter([
            data_get($reservation, 'url'),
            ...array_map(fn ($l) => data_get($l, 'url'), (array) data_get($reservation, 'links', [])),
        ], fn ($u) => is_string($u) && $u !== ''));

        foreach ($candidates as $url) {
            if ($this->openTable->isOpenTableUrl($url) && ($rid = $this->openTable->parseRid($url)) !== null) {
                return ['platform' => 'opentable', 'resourceId' => 'opentable', 'payload' => [
                    'url' => $url, 'rid' => $rid, 'name' => $businessName,
                    'embedUrl' => $this->openTable->embedUrl($rid, $this->openTable->hostOf($url)),
                    'source' => 'google-business',
                ]];
            }
            if ($this->resDiary->isResDiaryUrl($url) && ($embed = $this->resDiary->embedUrl($url)) !== null) {
                return ['platform' => 'resdiary', 'resourceId' => 'resdiary', 'payload' => [
                    'url' => $url, 'microsite' => $this->resDiary->parseMicrosite($url),
                    'name' => $this->resDiary->nameFromUrl($url) ?? $businessName,
                    'embedUrl' => $embed, 'source' => 'google-business',
                ]];
            }
            if ($this->nowBookit->isNowBookitUrl($url) && ($ids = $this->nowBookit->parseIds($url)) !== null) {
                return ['platform' => 'nowbookit', 'resourceId' => 'nowbookit', 'payload' => [
                    'url' => $url, 'accountId' => $ids['accountId'], 'venueId' => $ids['venueId'],
                    'name' => $this->nowBookit->nameFromUrl($url) ?? $businessName,
                    'embedUrl' => $this->nowBookit->embedUrl($ids['accountId'], $ids['venueId']),
                    'source' => 'google-business',
                ]];
            }
        }

        $url = $this->safeUrl(data_get($reservation, 'url'));
        if ($url === null) {
            return null;
        }

        return ['platform' => 'reservations', 'resourceId' => 'reservations', 'payload' => [
            'provider' => 'custom', 'url' => $url,
            'name' => $this->clean(data_get($reservation, 'provider')) ?? $businessName,
            'favicon' => null, 'logo' => null, 'source' => 'google-business',
        ]];
    }

    private function hasAnyReservation(string $userId): bool
    {
        foreach (['opentable', 'resdiary', 'nowbookit', 'reservations'] as $platform) {
            if ($this->has($userId, $platform)) {
                return true;
            }
        }

        return false;
    }

    // ── booking ──────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function seedBooking(string $userId, array $enrichment, ?string $businessName): array
    {
        try {
            $write = $this->resolveBookingWrite($enrichment, $businessName);
            if ($write === null) {
                return [];
            }

            $label = match ($write['platform']) {
                'fresha' => 'Fresha', 'square' => 'Square', default => $write['payload']['name'] ?? 'Booking',
            };
            if ($this->has($userId, 'fresha') || $this->has($userId, 'square') || $this->has($userId, 'booking')) {
                return [$this->conflictFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write), [
                    'remove' => ['fresha', 'square', 'booking'],
                    'write' => $write,
                ])];
            }

            $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

            return [$this->seededFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write))];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The single booking row Google's data would write: a PENDING Fresha (url
     * only, "Finish setup"), a complete Square "Book now", or a branded custom
     * card. Null when Google has no booking link. Pure (no DB writes).
     *
     * @return array{platform:string, resourceId:string, payload:array<string,mixed>}|null
     */
    private function resolveBookingWrite(array $enrichment, ?string $businessName): ?array
    {
        $links = data_get($enrichment, 'booking');
        $url = is_array($links) ? $this->safeUrl($links[0] ?? null) : null;
        if ($url === null) {
            return null;
        }

        $provider = $this->detector->detectFor('booking', $url);
        if ($provider === 'fresha') {
            return ['platform' => 'fresha', 'resourceId' => 'fresha', 'payload' => [
                'url' => $url, 'selection' => null, 'source' => 'google-business',
            ]];
        }
        if ($provider === 'square') {
            return ['platform' => 'square', 'resourceId' => 'square', 'payload' => [
                'url' => $url, 'source' => 'google-business',
            ]];
        }

        return ['platform' => 'booking', 'resourceId' => 'booking', 'payload' => [
            'provider' => 'custom', 'url' => $url, 'name' => $businessName,
            'favicon' => null, 'logo' => null, 'source' => 'google-business',
        ]];
    }

    // ── workplace ─────────────────────────────────────────────────

    // Fill the workplace card's previous-website / category / description from
    // the Google Business Place Details — per field, only when the user hasn't
    // set it. Never seeds the identity fields (name/address). Writes to
    // site.workplaces (FOUND-4 — promoted from settings JSONB).
    //
    // @param array<string,mixed> $gbPayload
    private function seedWorkplace(string $userId, array $gbPayload): void
    {
        try {
            $fields = array_filter([
                'previous_website' => $this->safeUrl(data_get($gbPayload, 'website')),
                'category' => $this->truncate($this->clean(data_get($gbPayload, 'category')), 120),
                'description' => $this->truncate(
                    $this->clean(data_get($gbPayload, 'editorialSummary'))
                        ?? $this->clean(data_get($gbPayload, 'reviewSummary')),
                    1000,
                ),
            ], fn ($v) => $v !== null);
            if ($fields === []) {
                return;
            }

            $site = Site::query()->where('user_id', $userId)->first();
            if ($site === null) {
                return;
            }

            // Load existing row (or a fresh unsaved model) to preserve fields the user already set.
            $workplace = Workplace::query()->firstOrNew(['site_id' => (string) $site->id]);

            $changed = false;
            foreach ($fields as $key => $value) {
                // Only fill if the column is currently blank — never overwrite user data.
                if ($this->blank($workplace->{$key} ?? null)) {
                    $workplace->{$key} = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                $workplace->save();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── ordering ─────────────────────────────────────────────────

    /**
     * Seed ordering links from the Google order panel, ONE consolidated row per
     * store. Google lists each provider×mode as a separate action (Uber Eats
     * pickup, Uber Eats delivery, DoorDash pickup, …); we group those by store
     * (host + path, query stripped) and write a single row carrying both the
     * pickup and delivery URLs under `data`, mirroring OnlineOrderingController's
     * merge-on-add — so the same store never appears twice. Only-if-empty PER
     * STORE: a store the user already has (manual add or a prior harvest) is left
     * untouched.
     *
     * @return list<array<string,mixed>>
     */
    private function seedOrdering(string $userId, array $enrichment): array
    {
        $findings = [];
        try {
            $providers = data_get($enrichment, 'order.providers');
            if (! is_array($providers)) {
                return [];
            }

            // Group providers by store, in first-seen order.
            $stores = [];
            foreach ($providers as $p) {
                $url = $this->safeUrl(data_get($p, 'url'));
                if ($url === null) {
                    continue;
                }
                $key = $this->storeKey($url) ?? $url;
                $stores[$key] ??= [];
                $stores[$key][] = $p;
            }

            foreach ($stores as $storeKey => $group) {
                if ($this->count($userId, 'online-ordering') >= self::MAX_ORDERING) {
                    break;
                }
                if ($this->hasStoreKey($userId, $storeKey)) {
                    continue;   // only-if-empty per store — never clobber an existing one
                }

                // Representative row identity: prefer a delivery-typed provider
                // (the common ordering intent), else the first in the group.
                $primary = $this->preferredProvider($group);
                $repUrl = $this->safeUrl(data_get($primary, 'url'));
                if ($repUrl === null) {
                    continue;
                }
                $name = $this->clean(data_get($primary, 'name'));
                $rid = 'order-'.substr(sha1(strtolower($repUrl)), 0, 16);

                // Gather the pickup + delivery URLs across the store's providers.
                $pickupUrl = $this->modeUrl($group, 'pickup');
                $deliveryUrl = $this->modeUrl($group, 'delivery');

                $this->write($userId, 'online-ordering', $rid, [
                    'id' => $rid,
                    'provider' => 'custom',
                    'url' => $repUrl,
                    'name' => $name ?? 'Order online',
                    'favicon' => null,
                    'logo' => null,
                    'source' => 'google-business',
                    'data' => array_filter([
                        'type' => $this->clean(data_get($primary, 'type')),
                        'fees' => $this->clean(data_get($primary, 'fees')),
                        'time' => $this->clean(data_get($primary, 'time')),
                        'sourcePlatform' => $name,
                        'pickupUrl' => $pickupUrl,
                        'deliveryUrl' => $deliveryUrl,
                    ], fn ($v) => $v !== null),
                ]);
                // Online-ordering is multi-entry — every new store is just added (no
                // conflict concept), so each is a 'seeded' finding.
                $findings[] = $this->seededFinding('online-ordering', $rid, 'online-ordering', $name ?? 'Order online', $repUrl);
            }

            // Ordering links changed → (re)derive the shared menu from them.
            MenuFetchJob::dispatch($userId);
        } catch (Throwable $e) {
            report($e);
        }

        return $findings;
    }

    /**
     * The provider that should own a store's consolidated row — the first
     * delivery-typed one, else the first provider.
     *
     * @param  list<array<string,mixed>>  $group
     * @return array<string,mixed>
     */
    private function preferredProvider(array $group): array
    {
        foreach ($group as $p) {
            if ($this->clean(data_get($p, 'type')) === 'delivery') {
                return $p;
            }
        }

        return $group[0];
    }

    /**
     * The first URL in a store's providers carrying the given mode (pickup /
     * delivery), or null.
     *
     * @param  list<array<string,mixed>>  $group
     */
    private function modeUrl(array $group, string $mode): ?string
    {
        foreach ($group as $p) {
            if ($this->clean(data_get($p, 'type')) === $mode) {
                return $this->safeUrl(data_get($p, 'url'));
            }
        }

        return null;
    }

    /** Whether the user already has any ordering row for this store key. */
    private function hasStoreKey(string $userId, string $storeKey): bool
    {
        return IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', 'online-ordering')
            ->get()
            ->contains(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);
    }

    /**
     * A store grouping key — "<host>|<path>", query + fragment + trailing slash
     * stripped (so Uber Eats ?diningMode / DoorDash ?pickup variants of one store
     * collapse). Null for a non-URL input. Mirrors OnlineOrderingController.
     */
    private function storeKey(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.'|'.$path;
    }

    // ── socials ──────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function seedSocials(string $userId, array $enrichment): array
    {
        $socials = data_get($enrichment, 'socials');
        if (! is_array($socials)) {
            return [];
        }

        $findings = [];
        $linkOnly = ['facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'X', 'linkedin' => 'LinkedIn'];
        $platformOf = ['facebook' => 'facebook', 'tiktok' => 'tiktok', 'twitter' => 'x', 'linkedin' => 'linkedin'];
        foreach ($linkOnly as $socialKey => $label) {
            try {
                $url = $this->safeUrl(data_get($socials, $socialKey));
                if ($url === null) {
                    continue;
                }
                $platform = $platformOf[$socialKey];
                $payload = ['username' => $this->socialUsername($platform, $url), 'url' => $url, 'source' => 'google-business'];
                $write = ['platform' => $platform, 'resourceId' => $platform, 'payload' => $payload];

                if ($this->has($userId, $platform)) {
                    $findings[] = $this->conflictFinding($platform, $platform, 'social', $label, $url, [
                        'remove' => [$platform], 'write' => $write,
                    ]);

                    continue;
                }
                $this->write($userId, $platform, $platform, $payload);
                $findings[] = $this->seededFinding($platform, $platform, 'social', $label, $url);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $igUrl = $this->safeUrl(data_get($socials, 'instagram'));
            $igFinding = $this->seedInstagram($userId, $igUrl);
            if ($igFinding !== null) {
                $findings[] = $igFinding;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $findings;
    }

    /** @return array<string,mixed>|null */
    private function seedInstagram(string $userId, ?string $url): ?array
    {
        if ($url === null || ! preg_match('~instagram\.com/([A-Za-z0-9._]+)~i', $url, $m)) {
            return null;
        }
        $username = $m[1];
        if (! preg_match('/^[A-Za-z0-9._]{1,80}$/', $username)) {
            return null;
        }

        if ($this->has($userId, 'instagram')) {
            return $this->conflictFinding('instagram', 'instagram', 'social', 'Instagram', $url, [
                'remove' => ['instagram'], 'instagram' => ['username' => $username],
            ]);
        }

        if (! $this->dispatchInstagram($userId, $username)) {
            return null;   // no Apify token / budget exhausted — nothing seeded, no card
        }

        return $this->seededFinding('instagram', 'instagram', 'social', 'Instagram', $url);
    }

    /**
     * Write the pending Instagram placeholder + dispatch the budgeted scrape.
     * Returns false when there's no token or the daily budget is spent.
     */
    private function dispatchInstagram(string $userId, string $username): bool
    {
        if (! config('services.apify.token') || ! $this->instagramBudget->tryClaim()) {
            return false;
        }

        // Pending placeholder tagged source so the synced step + undo can find it;
        // InstagramConnectJob preserves that tag when it writes the scrape result.
        $connection = IntegrationConnection::updateOrCreate(
            ['user_id' => $userId, 'platform' => 'instagram', 'resource_id' => 'instagram'],
            [
                'payload' => ['source' => 'google-business'],
                'is_active' => false,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        InstagramConnectJob::dispatch($userId, $username, $connection->id);

        return true;
    }

    // ── findings ──────────────────────────────────────────────────

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

    /** @param  array{platform:string,resourceId:string,payload:array<string,mixed>}  $write */
    private function urlOf(array $write): ?string
    {
        $url = $write['payload']['url'] ?? null;

        return is_string($url) ? $url : null;
    }

    // ── helpers ──────────────────────────────────────────────────

    private function has(string $userId, string $platform, ?string $resourceId = null): bool
    {
        $q = IntegrationConnection::query()->where('user_id', $userId)->where('platform', $platform);
        if ($resourceId !== null) {
            $q->where('resource_id', $resourceId);
        }

        return $q->exists();
    }

    private function count(string $userId, string $platform): int
    {
        return IntegrationConnection::query()->where('user_id', $userId)->where('platform', $platform)->count();
    }

    /** @param  array<string,mixed>  $payload */
    private function write(string $userId, string $platform, string $resourceId, array $payload): void
    {
        // Model write (not quiet): the observer purges the sitepage edge cache for
        // the newly-public opentable / social rows.
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

    /** Best-effort handle from a canonical social profile URL ('' when none). */
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

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim($value);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    private function truncate(?string $value, int $max): ?string
    {
        return $value !== null ? mb_substr($value, 0, $max) : null;
    }
}
