<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\InstagramApifyBudget;
use Throwable;

// Seeds Reservations / Online-ordering / Social connections from a Google
// Business Apify enrichment — only into slots the user hasn't filled, each
// tagged source:'google-business' so the connect modal's "Automatically Synced
// Integrations" step can list them with an undo. Best-effort: every seed is
// isolated in its own try/catch so one failure never blocks the rest.
//
// Known providers store under their own platform keys (opentable + the link
// socials) so these seeds drive the same public rendering a manual connect
// would; online-ordering rows are dashboard-only. Booking is deliberately NOT
// auto-synced. Instagram goes through its normal budgeted scrape job.
class GoogleBusinessAutoSync
{
    private const MAX_ORDERING = 10;

    public function __construct(
        private readonly OpenTableService $openTable,
        private readonly InstagramApifyBudget $instagramBudget,
    ) {}

    /**
     * @param  array<string,mixed>  $enrichment  the scraper map() output (menu / reservation / order / booking / socials)
     */
    public function seed(string $userId, array $enrichment, ?string $businessName): void
    {
        $this->seedReservation($userId, $enrichment, $businessName);
        $this->seedOrdering($userId, $enrichment);
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
            if ($this->has($userId, 'opentable') || $this->has($userId, 'reservations')) {
                return;
            }

            // Prefer an OpenTable link with a rid → the live keyless widget.
            $ot = $this->openTable->suggestionFromGoogleBusiness(['reservation' => $reservation, 'name' => $businessName]);
            if ($ot !== null && ($rid = $this->openTable->parseRid($ot['url'])) !== null) {
                $this->write($userId, 'opentable', 'opentable', [
                    'url' => $ot['url'],
                    'rid' => $rid,
                    'name' => $ot['name'],
                    'embedUrl' => $this->openTable->embedUrl($rid, $this->openTable->hostOf($ot['url'])),
                    'source' => 'google-business',
                ]);

                return;
            }

            // Non-OpenTable (or rid-less) provider → a branded custom card.
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
}
