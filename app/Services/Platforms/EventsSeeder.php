<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Job-context seeding of Eventbrite/Humanitix rows from scanned links
// (signup-v2 C2). Mirrors EventsPlatformController::addAccount()/
// addStandaloneEvent()'s write mechanics — same caps (5 accounts via the
// controller trait's maxAccounts() mirror below, 10 standalone events), same
// row shapes ('acct-*' canonical-keyed account rows, 'event-*' rows with
// resource_kind 'event'), same per-platform lock — WITHOUT the controller's
// HTTP/policy wrapper, following the same parallel-implementation convention
// GoogleBusinessAutoSync/InstagramAutoSync use for their own scraped seeds.
//
// Contract (mirrors InstagramAutoSync::seed): $user MUST be server-derived —
// callers are queue jobs holding a userId they were dispatched with, never raw
// request input. There is no ownership check inside; capability gating
// (DISC-7 can_autosync_scraped_connections) is the CALLER's responsibility.
class EventsSeeder
{
    /** Mirrors ManagesIntegrationConnection::maxAccounts() — keep in lockstep. */
    private const MAX_ACCOUNTS = 5;

    /** Mirrors EventsPlatformController::MAX_STANDALONE_EVENTS — keep in lockstep. */
    private const MAX_STANDALONE_EVENTS = 10;

    private const PLATFORMS = ['eventbrite', 'humanitix'];

    public function __construct(
        private readonly EventbriteScraper $eventbrite,
        private readonly HumanitixScraper $humanitix,
    ) {}

    /**
     * Seed an organiser/host ACCOUNT row from a scanned link. Returns true when
     * a row was written (or already existed for the same canonical page).
     */
    public function seedAccount(User $user, string $platform, string $url): bool
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return false;
        }

        $canonical = $platform === 'eventbrite'
            ? $this->eventbrite->normalizeOrgUrl($url)
            : $this->humanitix->resolveHostUrl($url);
        if ($canonical === null) {
            return false;
        }

        // Tombstone: this exact organiser was explicitly disconnected before —
        // never resurrect from a scan (same rule InstagramAutoSync applies).
        $rid = 'acct-'.substr(sha1(strtolower(trim($canonical))), 0, 16);
        if ($this->wasDisconnected($user, $platform, $rid)) {
            return false;
        }

        $result = $platform === 'eventbrite'
            ? $this->eventbrite->fetchEvents($canonical)
            : $this->humanitix->fetchEvents($canonical);
        if ($result === null) {
            return false;
        }

        $payload = EventsPayload::accountPayload($canonical, $result['organiser'], $result['events']);

        return $this->locked($platform, $user, function () use ($user, $platform, $rid, $canonical, $payload): bool {
            $rows = $this->liveRows($user, $platform)
                ->filter(fn (IntegrationConnection $r) => $r->resource_kind !== 'event' && $r->resource_kind !== 'link');

            $existing = $rows->firstWhere('resource_id', $rid)
                ?? $rows->firstWhere('canonical_key', strtolower(trim($canonical)));
            if (! $existing && $rows->count() >= self::MAX_ACCOUNTS) {
                Log::info('events_seeder.account_cap', ['user_id' => (string) $user->id, 'platform' => $platform]);

                return false;
            }

            IntegrationConnection::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $existing?->resource_id ?? $rid],
                [
                    'payload' => $payload,
                    'canonical_key' => strtolower(trim($canonical)),
                    'is_active' => true,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                ],
            );

            return true;
        });
    }

    /**
     * Seed one STANDALONE event row from a scanned event-page link. Returns
     * true when the event row was written (or already existed).
     */
    public function seedStandalone(User $user, string $platform, string $url): bool
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return false;
        }

        $canonical = $platform === 'eventbrite'
            ? $this->eventbrite->normalizeEventUrl($url)
            : $this->humanitix->normalizeEventUrl($url);
        if ($canonical === null) {
            return false;
        }

        $event = $platform === 'eventbrite'
            ? $this->eventbrite->fetchSingleEvent($canonical)
            : $this->humanitix->fetchSingleEvent($canonical);
        if ($event === null) {
            return false;
        }

        $payload = EventsPayload::standalonePayload($event);
        $rid = 'event-'.$payload['id'];

        if ($this->wasDisconnected($user, $platform, $rid)) {
            return false;
        }

        return $this->locked($platform, $user, function () use ($user, $platform, $rid, $payload): bool {
            $events = $this->liveRows($user, $platform)
                ->filter(fn (IntegrationConnection $r) => $r->resource_kind === 'event');

            if ($events->firstWhere('resource_id', $rid) === null
                && $events->count() >= self::MAX_STANDALONE_EVENTS) {
                Log::info('events_seeder.event_cap', ['user_id' => (string) $user->id, 'platform' => $platform]);

                return false;
            }

            IntegrationConnection::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $rid],
                [
                    'payload' => $payload,
                    'resource_kind' => 'event',
                    'is_active' => true,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                ],
            );

            return true;
        });
    }

    /** @return \Illuminate\Support\Collection<int, IntegrationConnection> */
    private function liveRows(User $user, string $platform)
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->get();
    }

    private function wasDisconnected(User $user, string $platform, string $resourceId): bool
    {
        return IntegrationConnection::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('resource_id', $resourceId)
            ->exists();
    }

    /**
     * Same per-user/per-platform lock the controller trait's write path holds
     * (CacheKeyGenerator::platformConnectionLock) — a scan-seeded write must
     * exclude a concurrent dashboard add on the same platform. False on
     * contention: a scan seed is best-effort, never worth blocking on.
     */
    private function locked(string $platform, User $user, callable $callback): bool
    {
        $key = CacheKeyGenerator::platformConnectionLock($platform, (string) $user->id);

        try {
            return (bool) Cache::lock($key, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            Log::warning('events_seeder.lock_timeout', ['user_id' => (string) $user->id, 'platform' => $platform]);

            return false;
        }
    }
}
