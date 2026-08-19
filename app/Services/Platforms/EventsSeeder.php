<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Content\ManualEventWriter;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
// request input. There is no ownership check inside; capability gating on
// can_autosync_scraped_connections was the caller's responsibility — removed
// 2026-07-25 (unclaimed users now auto-sync identically to claimed).
class EventsSeeder
{
    /** Mirrors ManagesIntegrationConnection::maxAccounts() — keep in lockstep. */
    private const MAX_ACCOUNTS = 5;

    /**
     * The scan lane's OWN cap, per platform, on CONNECTION rows — deliberately
     * still this and not ManualEventWriter::MAX_STANDALONE_EVENTS.
     *
     * The two govern different things and always did: this bounds what an
     * automatic scan may seed per platform, that bounds what an owner may add
     * by hand. Slice 7 Phase 4 left the pool half of seedStandalone() UNCAPPED
     * for exactly that reason — every row this seeder wrote used to publish, so
     * capping the item while still writing the connection would resurrect the
     * invisible-event bug this phase exists to fix.
     */
    private const MAX_STANDALONE_EVENTS = 10;

    private const PLATFORMS = ['eventbrite', 'humanitix'];

    /**
     * Events-parity (2026-08-19): brands whose single-event pages seed the
     * SAME pool-item lane through the generic schema.org reader — no bespoke
     * scraper, no organiser/account arm (only the two bespoke platforms have
     * an organiser scrape). 'events-custom' passes through for hosts classify
     * recognises without a brand key (Meetup); the reader self-filters a page
     * with no Event JSON-LD to null and the caller cards the link as before.
     */
    private const GENERIC_EVENT_PLATFORMS = [
        'luma', 'partiful', 'ticketmaster', 'ticketek', 'oztix', 'trybooking',
        'resident-advisor', 'events-custom',
    ];

    public function __construct(
        private readonly EventbriteScraper $eventbrite,
        private readonly HumanitixScraper $humanitix,
        private readonly EventPageReader $reader,
    ) {}

    /**
     * Seed an organiser/host ACCOUNT row from a scanned link. Returns the
     * resource_id written (or of the row that already held this canonical
     * page), null when nothing was written.
     *
     * The id — not a bare bool — because the caller has to name the row in the
     * synced-modal finding it emits, and an events platform holds MANY rows per
     * platform: a finding that says 'eventbrite' resolves to nothing and is
     * dropped (see LinkRouter::seedEvent).
     */
    public function seedAccount(User $user, string $platform, string $url): ?string
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return null;
        }

        $canonical = $platform === 'eventbrite'
            ? $this->eventbrite->normalizeOrgUrl($url)
            : $this->humanitix->resolveHostUrl($url);
        if ($canonical === null) {
            return null;
        }

        // Tombstone: this exact organiser was explicitly disconnected before —
        // never resurrect from a scan (same rule InstagramAutoSync applies).
        $rid = 'acct-'.substr(sha1(strtolower(trim($canonical))), 0, 16);
        if ($this->wasDisconnected($user, $platform, $rid)) {
            return null;
        }

        $result = $platform === 'eventbrite'
            ? $this->eventbrite->fetchEvents($canonical)
            : $this->humanitix->fetchEvents($canonical);
        if ($result === null) {
            return null;
        }

        $payload = EventsPayload::accountPayload($canonical, $result['organiser'], $result['events']);

        return $this->locked($platform, $user, function () use ($user, $platform, $rid, $canonical, $payload): ?string {
            $rows = $this->liveRows($user, $platform)
                ->filter(fn (IntegrationConnection $r) => $r->resource_kind !== 'event' && $r->resource_kind !== 'link');

            $existing = $rows->firstWhere('resource_id', $rid)
                ?? $rows->firstWhere('canonical_key', strtolower(trim($canonical)));
            if (! $existing && $rows->count() >= self::MAX_ACCOUNTS) {
                Log::info('events_seeder.account_cap', ['user_id' => (string) $user->id, 'platform' => $platform]);

                return null;
            }

            // A row matched by canonical_key keeps ITS id, so the id the caller
            // is told about must come from here, not from $rid.
            $written = $existing?->resource_id ?? $rid;

            IntegrationConnection::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $written],
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

            return $written;
        });
    }

    /**
     * Seed one STANDALONE event row from a scanned event-page link. Returns the
     * resource_id written (or of the row that already held this event), null
     * when nothing was written. See seedAccount() for why it is the id.
     *
     * Slice 7 Phase 4 made this a DUAL WRITE: the connection row below, plus a
     * `content.items` row in the `events` pool through the same
     * ManualEventWriter lane the two interactive add verbs now use. The item is
     * what PUBLISHES — the standalone payload on the public integrations wire
     * is empty as of this phase, so seeding only the connection would land an
     * event nobody can see.
     *
     * The connection is KEPT rather than repointed, unlike those two verbs,
     * because it is what the synced-modal finding lane resolves against:
     * shapeFinding() in BOTH InstagramController and GoogleBusinessController
     * looks a seeded finding up by `platform|resourceId` over connection rows
     * and derives its status from `last_refresh_status`, dropping anything it
     * cannot match. Teaching that lane to resolve a pool item is its own piece
     * of work in the scan surface, and doing it here would silently remove
     * every scanned event from the modal.
     *
     * Not a lane that can disagree: the connection publishes `[]` either way,
     * so it is dashboard-and-scan bookkeeping now — exactly what an events
     * ACCOUNT row became in slice 2. Both selection readers skip a connection
     * row whose canonical URL already has a pool card, so the pair is never
     * listed twice.
     *
     * $withConnectionRow = false is the NEW-LANE contract (LinkInBioImporter,
     * owner ruling 2026-08-18): that path records no payload finding, so the
     * connection row is not read by anyone — it only surfaced as a bogus
     * "Eventbrite" platform beside the real event item (gsnwilliams). Same
     * write as the interactive addEvent verb: the pool item and nothing else.
     * Legacy callers (LinkRouter::seedEvent, CommerceProbeJob) still record a
     * finding keyed to the row and keep the default. Returns the canonical
     * event URL in that mode — a non-null "written" signal for the caller's
     * tally, never a resource_id (there is none).
     */
    public function seedStandalone(User $user, string $platform, string $url, ?string $origin = null): ?string
    {
        if (in_array($platform, self::PLATFORMS, true)) {
            $canonical = $platform === 'eventbrite'
                ? $this->eventbrite->normalizeEventUrl($url)
                : $this->humanitix->normalizeEventUrl($url);
            if ($canonical === null) {
                return null;
            }

            $event = $platform === 'eventbrite'
                ? $this->eventbrite->fetchSingleEvent($canonical)
                : $this->humanitix->fetchSingleEvent($canonical);
            if ($event === null) {
                return null;
            }
        } elseif (in_array($platform, self::GENERIC_EVENT_PLATFORMS, true)) {
            // Events-parity: every other events brand reads through the one
            // generic schema.org lane — same stored shape, same pool write.
            $read = $this->reader->read($url);
            if ($read === null) {
                return null;
            }
            [$canonical, $event] = [$read['canonical'], $read['event']];
        } else {
            return null;
        }

        $payload = EventsPayload::standalonePayload($event);

        // F4 (2026-08-20): a re-SCAN never resurrects a removed item.
        // ManualEventWriter::write() un-deletes by design — a PERSON re-adding
        // the link means "bring it back" — but this is the scan lane, and the
        // next bio re-scan was quietly resurrecting events the owner removed.
        // Returned as HANDLED (the canonical), not null: null sends the
        // caller to its card write, and a link card for a removed event
        // resurrects it in another pool.
        if ($this->itemTombstoned($user, $canonical)) {
            Log::info('events_seeder.tombstoned', ['user_id' => (string) $user->id, 'platform' => $platform]);

            return $canonical;
        }

        // R7 (owner, 2026-08-19): a standalone event is an events-POOL item
        // and nothing else — the dual-write `resource_kind='event'`
        // connection row is retired for every lane (existing rows converted
        // one-off by events:convert-standalone). No wasDisconnected() here:
        // that tombstone is "the owner removed this CONNECTION row"; the pool
        // item has its own remove semantics (removed_at on the item), which
        // ManualEventWriter honours the same way for a hand re-add. Returns
        // the canonical event URL as the caller's "written" signal — there is
        // no resource_id.
        $writer = app(ManualEventWriter::class);
        if ($writer->wouldExceedCap($user, $canonical)) {
            Log::info('events_seeder.event_cap', ['user_id' => (string) $user->id, 'platform' => $platform, 'lane' => 'pool']);

            return null;
        }

        try {
            $item = $writer->addStandalone($user, $canonical, StandaloneEventPayload::fromArray($payload)->event(), $origin);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('events_seeder.pool_write_failed', [
                'user_id' => (string) $user->id, 'platform' => $platform, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $item === null ? null : $canonical;
    }

    /** @return Collection<int, IntegrationConnection> */
    private function liveRows(User $user, string $platform)
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->get();
    }

    /** The owner removed this exact ITEM before (items.removed_at on the
     * manual-source coord) — the pool-item twin of wasDisconnected(). */
    private function itemTombstoned(User $user, string $canonical): bool
    {
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as csi', 'csi.item_id', '=', 'i.id')
            ->join('content.sources as cs', function ($join) {
                $join->on('cs.id', '=', 'csi.source_id')->where('cs.kind', '=', 'manual');
            })
            ->where('i.user_id', (string) $user->id)
            ->where('csi.coord', ManualEventWriter::coordFor($canonical))
            ->whereNotNull('i.removed_at')
            ->exists();
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
     * exclude a concurrent dashboard add on the same platform. Null on
     * contention: a scan seed is best-effort, never worth blocking on.
     */
    private function locked(string $platform, User $user, callable $callback): ?string
    {
        $key = CacheKeyGenerator::platformConnectionLock($platform, (string) $user->id);

        try {
            $written = Cache::lock($key, 10)->block(5, $callback);

            return is_string($written) ? $written : null;
        } catch (LockTimeoutException) {
            Log::warning('events_seeder.lock_timeout', ['user_id' => (string) $user->id, 'platform' => $platform]);

            return null;
        }
    }
}
