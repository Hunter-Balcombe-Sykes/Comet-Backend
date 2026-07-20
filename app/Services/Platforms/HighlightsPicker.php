<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;

/**
 * Serves the highlights picker's item list from the connection's private
 * `recent` payload snapshot instead of live-scraping the vendor on every
 * modal open (LIFE-21..24 / audit unit 11, Phase 1b).
 *
 * `recent` is a PRIVATE key, never on any wire: absent from
 * PublicIntegrationConnectionResource::ALLOWLIST for youtube/vimeo/
 * youtube-music/bandcamp, absent from the four dashboard resources
 * (YoutubeConnectionResource via TileConnectionResource, VimeoConnectionResource,
 * YoutubeMusicConnectionResource, BandcampConnectionResource), and absent from
 * FeedPayload — so GenericPlatformController::shape()'s DTO round-trip drops it
 * even if a stray write ever put it in a place that flows through shape().
 *
 * Kept separate from the platform's PUBLIC item keys (`items`/`latest`) rather
 * than reusing them: reusing `items` would couple picker width to render width
 * (Vimeo's picker wants more items than the public grid stores).
 *
 * Known consequence of keying freshness off `last_refreshed_at`, accepted
 * deliberately: a Bandcamp owner with `auto_sync_latest` off gets
 * FetchNotModifiedException on every refresh → recordNotModified() still bumps
 * last_refreshed_at → their `recent` looks fresh forever while never updating.
 * Consistent with the toggle's own intent (they asked for the tile to stop
 * moving); reconnecting refreshes it.
 */
class HighlightsPicker
{
    /** Private payload key holding the picker snapshot. */
    public const SNAPSHOT_KEY = 'recent';

    public function __construct(private readonly FetchBudget $budget) {}

    /**
     * Resolve picker items for one connection. Four-branch decision, in this
     * exact order — the order is what preserves today's frozen 404 (handled
     * by the caller before this ever runs)/422 error contract while making a
     * fresh snapshot the fast, no-vendor-call path:
     *
     *  1. Fresh, non-empty stored snapshot → serve it. Zero vendor calls.
     *  2. Otherwise live-fetch, wrapped in the SAME wall-clock budget
     *     ConnectResolver opens around connect() — this endpoint had no
     *     budget at all before this change (W1 review finding: ConnectResolver
     *     only wrapped connect(), so /recent could still hang a request
     *     thread on a slow vendor). Live success → serve it.
     *  3. Live fetch failed/empty AND a stale-but-non-empty snapshot exists →
     *     serve the stale snapshot. Real-but-old data beats an error page —
     *     the items a visitor would pick from almost certainly still exist
     *     upstream, so the wall-clock budget tripping shouldn't dead-end them.
     *  4. No usable snapshot at all and live failed → null, exactly like
     *     today. This is the ONLY branch whose outward behaviour is
     *     unchanged by this class existing.
     */
    public function items(HighlightsStrategy $strategy, IntegrationConnection $row, string $identity): ?array
    {
        // Array access, never data_get() — NoUntypedPayloadAccessTest greps
        // app/Services/Platforms/ for the untyped pattern and this file is
        // NOT covered by the Strategies/Fetch/ exemption (that exemption is
        // for the refresh WRITE path; this is a read boundary).
        $stored = $row->payload[self::SNAPSHOT_KEY] ?? null;
        $storedUsable = is_array($stored) && $stored !== [];

        if ($storedUsable && $this->fresh($row)) {
            return array_values($stored);
        }

        $live = $this->budget->open(
            (float) config('partna.http_fetch.connect_budget_seconds', 20),
            fn () => $strategy->recentItems($identity),
        );

        if ($live !== null) {
            return $live;
        }

        return $storedUsable ? array_values($stored) : null;
    }

    /**
     * Whether the row's stored snapshot is recent enough to serve without a
     * live re-fetch. Gated on the COLUMN `last_refreshed_at`, deliberately
     * NOT a timestamp written into the payload: IntegrationConnectionObserver::
     * saved() purges the sitepage edge cache on wasChanged('payload') (line ~48),
     * so a monotonic timestamp inside the payload would fire an edge purge on
     * EVERY 12h scheduled refresh of EVERY picker connection — including the
     * overwhelming majority where nothing else changed. The column already
     * advances on every refresh (ok status AND the 304/not-modified path
     * alike) without touching payload, so it's a freshness signal that costs
     * nothing extra to read.
     */
    public function fresh(IntegrationConnection $row): bool
    {
        return $row->last_refreshed_at?->gt(
            now()->subSeconds((int) config('partna.refresh.highlights_snapshot_ttl', 24 * 3600))
        ) === true;
    }

    /**
     * Return $payload with the `recent` snapshot filled in, WITHOUT writing
     * to the DB. Nothing calls this yet — it exists for a later phase's async
     * connect job, which fetches content and needs to write it + `recent` in
     * ONE locked write. If this method wrote the row itself, that job would
     * have to take the per-user connection lock twice (once here, once for
     * its own write), opening a window for a highlights save to land between
     * the two and lose an update.
     *
     * Every migrated FetchStrategy (Youtube/Vimeo/YoutubeMusic/Bandcamp) now
     * computes `recent` itself directly inside fetch() — see Strategies/Fetch/
     * — so the common case is $payload already carries it and this is a
     * no-op passthrough. The fallback exists so a payload that DIDN'T come
     * through one of those (or a future FetchStrategy that forgets `recent`)
     * doesn't silently blank an already-warm snapshot.
     */
    public function warmInto(array $payload, IntegrationConnection $row): array
    {
        $incoming = $payload[self::SNAPSHOT_KEY] ?? null;
        if (is_array($incoming) && $incoming !== []) {
            return $payload;
        }

        $stored = $row->payload[self::SNAPSHOT_KEY] ?? null;
        if (is_array($stored) && $stored !== []) {
            $payload[self::SNAPSHOT_KEY] = array_values($stored);
        }

        return $payload;
    }
}
