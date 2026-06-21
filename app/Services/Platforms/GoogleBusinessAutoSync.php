<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\InstagramApifyBudget;
use Throwable;

// Seeds Reservations / Online-ordering / Social connections from a Google
// Business Apify enrichment — only into slots the user hasn't filled, each
// tagged source:'google-business' so the connect modal's "Automatically Synced
// Integrations" step can list them with an undo. Best-effort: every seed is
// isolated in its own try/catch so one failure never blocks the rest.
//
// Known providers store under their own platform keys (opentable / resdiary /
// nowbookit + the link socials) so these seeds drive the same public rendering a
// manual connect would; online-ordering rows are dashboard-only. Booking seeds a
// custom card from Google's appointment-booking link (only-if-empty), and the
// workplace card's category / description / old-website are filled from Place
// Details. Instagram goes through its normal budgeted scrape job.
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
     */
    public function seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null): void
    {
        // Booking is the one platform synced for EVERY account type (only-if-empty):
        // a professional with no booking link yet still gets the one Google has on
        // file, regardless of whether they're a Business Partna.
        $this->seedBooking($userId, $enrichment, $businessName);

        // Reservations / online-ordering / workplace / socials are a Business-Partna
        // convenience. A standard (partna) account only gets the booking link above —
        // it's never handed reservation/ordering/menu cards it doesn't surface. Gate
        // on the capability so the account_type read stays inside AccountCapabilities.
        $user = User::find($userId);
        if ($user === null || ! AccountCapabilities::for($user)->google_business_full_sync) {
            return;
        }

        $this->seedReservation($userId, $enrichment, $businessName);
        $this->seedOrdering($userId, $enrichment);
        $this->seedWorkplace($userId, $gbPayload ?? []);
        $this->seedSocials($userId, $enrichment);
    }

    // ── reservation ──────────────────────────────────────────────

    private function seedReservation(string $userId, array $enrichment, ?string $businessName): void
    {
        try {
            $reservation = data_get($enrichment, 'reservation');
            if (! is_array($reservation)) {
                return;
            }
            // Only-if-empty across the whole reservations family.
            if ($this->hasAnyReservation($userId)) {
                return;
            }

            // Candidate provider links: the primary url + every named link. One
            // may be a keyless provider (OpenTable / ResDiary / NowBookit).
            $candidates = array_values(array_filter([
                data_get($reservation, 'url'),
                ...array_map(fn ($l) => data_get($l, 'url'), (array) data_get($reservation, 'links', [])),
            ], fn ($u) => is_string($u) && $u !== ''));

            foreach ($candidates as $url) {
                if ($this->openTable->isOpenTableUrl($url) && ($rid = $this->openTable->parseRid($url)) !== null) {
                    $this->write($userId, 'opentable', 'opentable', [
                        'url' => $url,
                        'rid' => $rid,
                        'name' => $businessName,
                        'embedUrl' => $this->openTable->embedUrl($rid, $this->openTable->hostOf($url)),
                        'source' => 'google-business',
                    ]);

                    return;
                }
                if ($this->resDiary->isResDiaryUrl($url) && ($embed = $this->resDiary->embedUrl($url)) !== null) {
                    $this->write($userId, 'resdiary', 'resdiary', [
                        'url' => $url,
                        'microsite' => $this->resDiary->parseMicrosite($url),
                        'name' => $this->resDiary->nameFromUrl($url) ?? $businessName,
                        'embedUrl' => $embed,
                        'source' => 'google-business',
                    ]);

                    return;
                }
                if ($this->nowBookit->isNowBookitUrl($url) && ($ids = $this->nowBookit->parseIds($url)) !== null) {
                    $this->write($userId, 'nowbookit', 'nowbookit', [
                        'url' => $url,
                        'accountId' => $ids['accountId'],
                        'venueId' => $ids['venueId'],
                        'name' => $this->nowBookit->nameFromUrl($url) ?? $businessName,
                        'embedUrl' => $this->nowBookit->embedUrl($ids['accountId'], $ids['venueId']),
                        'source' => 'google-business',
                    ]);

                    return;
                }
            }

            // No keyless provider matched → a branded custom card.
            $url = $this->safeUrl(data_get($reservation, 'url'));
            if ($url === null) {
                return;
            }
            $this->write($userId, 'reservations', 'reservations', [
                'provider' => 'custom',
                'url' => $url,
                'name' => $this->clean(data_get($reservation, 'provider')) ?? $businessName,
                'favicon' => null,
                'logo' => null,
                'source' => 'google-business',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
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

    // Auto-connect the booking link Google has on file (only-if-empty across the
    // whole booking family). A Fresha link becomes a PENDING Fresha connection
    // (url only, no team-member/services selection) so the dashboard shows a
    // "Finish setup" prompt; a Square link is a complete "Book now" connection
    // (no picker step); anything else is a branded custom booking card.
    private function seedBooking(string $userId, array $enrichment, ?string $businessName): void
    {
        try {
            $links = data_get($enrichment, 'booking');
            $url = is_array($links) ? $this->safeUrl($links[0] ?? null) : null;
            if ($url === null) {
                return;
            }
            if ($this->has($userId, 'fresha') || $this->has($userId, 'square') || $this->has($userId, 'booking')) {
                return;
            }

            $provider = $this->detector->detectFor('booking', $url);
            if ($provider === 'fresha') {
                // Pending: url only, selection null (payload stays non-null — prod
                // constraint). The dashboard's "Finish setup" runs the normal
                // /fresha/connect + /selection picker flow from this url.
                $this->write($userId, 'fresha', 'fresha', [
                    'url' => $url,
                    'selection' => null,
                    'source' => 'google-business',
                ]);

                return;
            }
            if ($provider === 'square') {
                // Square is just a "Book now" link — complete, no picker step.
                $this->write($userId, 'square', 'square', [
                    'url' => $url,
                    'source' => 'google-business',
                ]);

                return;
            }

            // Unknown provider → a branded custom booking card.
            $this->write($userId, 'booking', 'booking', [
                'provider' => 'custom',
                'url' => $url,
                'name' => $businessName,
                'favicon' => null,
                'logo' => null,
                'source' => 'google-business',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── workplace ─────────────────────────────────────────────────

    // Fill the workplace card's previous-website / category / description from
    // the Google Business Place Details — per field, only when the user hasn't
    // set it. Never seeds the identity fields (name/address), so it can't
    // auto-publish a workplace section the user didn't create.
    //
    // @param array<string,mixed> $gbPayload
    private function seedWorkplace(string $userId, array $gbPayload): void
    {
        try {
            // Truncate to the workplace request's field caps (category 120,
            // description 1000) so a long Google summary can't later 422 the
            // user's own workplace save.
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
            $settings = is_array($site->settings) ? $site->settings : [];
            $workplace = is_array($settings['workplace'] ?? null) ? $settings['workplace'] : [];

            $changed = false;
            foreach ($fields as $key => $value) {
                if ($this->blank($workplace[$key] ?? null)) {
                    $workplace[$key] = $value;
                    $changed = true;
                }
            }
            if (! $changed) {
                return;
            }

            $settings['workplace'] = $workplace;
            $site->settings = $settings;
            $site->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── ordering ─────────────────────────────────────────────────

    private function seedOrdering(string $userId, array $enrichment): void
    {
        try {
            $providers = data_get($enrichment, 'order.providers');
            if (! is_array($providers)) {
                return;
            }
            foreach ($providers as $p) {
                if ($this->count($userId, 'online-ordering') >= self::MAX_ORDERING) {
                    break;
                }
                $url = $this->safeUrl(data_get($p, 'url'));
                if ($url === null) {
                    continue;
                }
                $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
                if ($this->has($userId, 'online-ordering', $rid)) {
                    continue;   // only-if-empty per URL — never clobber a manual add
                }
                $name = $this->clean(data_get($p, 'name'));
                $this->write($userId, 'online-ordering', $rid, [
                    'id' => $rid,
                    'provider' => 'custom',
                    'url' => $url,
                    'name' => $name ?? 'Order online',
                    'favicon' => null,
                    'logo' => null,
                    'source' => 'google-business',
                    'data' => array_filter([
                        'type' => $this->clean(data_get($p, 'type')),
                        'fees' => $this->clean(data_get($p, 'fees')),
                        'time' => $this->clean(data_get($p, 'time')),
                        'sourcePlatform' => $name,
                    ], fn ($v) => $v !== null),
                ]);
            }

            // Ordering links changed → (re)derive the shared menu from them.
            MenuFetchJob::dispatch($userId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── socials ──────────────────────────────────────────────────

    private function seedSocials(string $userId, array $enrichment): void
    {
        $socials = data_get($enrichment, 'socials');
        if (! is_array($socials)) {
            return;
        }

        // Link-only socials store {username, url} directly. youtube + pinterest are
        // scrape platforms (richer payloads) — skipped here; the user connects those
        // manually. instagram is handled separately (paid scrape, budgeted).
        $linkOnly = ['facebook' => 'facebook', 'tiktok' => 'tiktok', 'twitter' => 'x', 'linkedin' => 'linkedin'];
        foreach ($linkOnly as $socialKey => $platform) {
            try {
                $url = $this->safeUrl(data_get($socials, $socialKey));
                if ($url === null || $this->has($userId, $platform)) {
                    continue;
                }
                $this->write($userId, $platform, $platform, [
                    'username' => $this->socialUsername($platform, $url),
                    'url' => $url,
                    'source' => 'google-business',
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $this->seedInstagram($userId, $this->safeUrl(data_get($socials, 'instagram')));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function seedInstagram(string $userId, ?string $url): void
    {
        if ($url === null || $this->has($userId, 'instagram')) {
            return;
        }
        if (! preg_match('~instagram\.com/([A-Za-z0-9._]+)~i', $url, $m)) {
            return;
        }
        $username = $m[1];
        if (! preg_match('/^[A-Za-z0-9._]{1,80}$/', $username)) {
            return;
        }
        // Apify token required + the SAME global daily budget the manual connect
        // claims (shared cache service) — a Google connect can never blow the cap.
        if (! config('services.apify.token') || ! $this->instagramBudget->tryClaim()) {
            return;
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
