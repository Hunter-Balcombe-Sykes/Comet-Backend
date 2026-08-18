<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\FreshaEmployeeServicesRequest;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveFreshaSelectionRequest;
use App\Http\Requests\Platforms\SetFreshaServiceVisibilityRequest;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\UserCacheService;
use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlException;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\FreshaStaffMatcher;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Endpoints for the Fresha integration (fully authenticated — 'user.api' middleware
// on the route group, routes/api/platforms.php). Saves a per-user Fresha store URL
// and returns the staff list extracted from the page's __NEXT_DATA__ blob.
//
// Approach proven and documented in:
//   ~/Developer/platform link capabilites/fresha.md
class FreshaController extends ApiController
{
    use DefersBespokeConnect;
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(
        private readonly FreshaScraper $scraper,
        private readonly FreshaServiceProjector $projector,
        private readonly FetchBudget $budget,
        private readonly FreshaStaffMatcher $staffMatcher,
    ) {}

    protected function platform(): string
    {
        return Platform::Fresha->value;
    }

    // The per-user Fresha connection payload is { url, selection } — the connected
    // store URL plus the saved { storeName, employee, services } blob (or null).
    private function freshaUrl(User $user): ?string
    {
        return SelectionPayload::fromArray($this->readConnection($user) ?? [])->url;
    }

    // POST /api/platforms/fresha/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): a food business books via
        // Reservations, not Booking — Fresha is a bespoke connect flow (never
        // routes through GenericPlatformController), so it needs its own check.
        if (! AccountCapabilities::for($user)->can_use_booking) {
            return $this->error('Booking is not available for your account.', 403);
        }

        // Fresha + Square are mutually exclusive booking providers (XOR).
        if ($this->hasConflictingConnection($user, Platform::Square->value)) {
            return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
        }

        $validated = $request->validated();

        $url = $this->scraper->stripLocale($validated['url']);

        // CA-W6/CA-W7: config('partna.connect.deferred') names 'fresha' —
        // skip the synchronous ~96s menu scrape entirely and let
        // ConnectFetchJob fill the row via FreshaConnectFetch, for EITHER
        // mode. Team mode's write never depended on the fetch (CA-W6);
        // storewide's does (projector->sync() + a real selection), so
        // FreshaConnectFetch runs that work itself (CA-W7) instead of this
        // method falling through to the synchronous body below. Checked
        // after the 403/409 guards and the pure stripLocale() parse, never
        // before them or inside any vendor call. $mode is read from the
        // capability, not the vendor, so it is known at 202 time.
        if ($this->shouldDeferConnect($this->platform())) {
            $mode = AccountCapabilities::for($user)->can_book_storewide ? 'storewide' : 'team';

            return $this->connectDeferred($user, $url, $mode);
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        try {
            $menu = $this->budget->open($seconds, fn () => $this->scraper->fetchMenu($url));
        } catch (SafeUrlException|ConnectionException) {
            abort(502, 'Could not reach Fresha — please try again.');
        }

        // U1: outer bookingXorLock, re-asserting the Square conflict check —
        // a lock on Fresha's per-platform key alone cannot exclude a
        // concurrent Square connect (different key, different row). The
        // inner withConnectionLock is kept IN ADDITION (never replaced) —
        // dropping it would reopen PWL-5 for saveSelection /
        // setServiceVisibility / ConnectFetchJob / ScheduledRefresh, which
        // all still take only the 'fresha' platform key. Order is fixed:
        // bookingXor outer, platform inner — never the reverse (see
        // ManagesIntegrationConnection::withCrossPlatformLock's docblock).
        //
        // U1 raised this to TTL 30 because the storewide branch below ran
        // FreshaServiceProjector::sync() (Postgres advisory lock + a row per
        // scraped service) INSIDE this closure. Slice 7 D3a removed those
        // writes — sync() is now a content.* read and takes no advisory lock —
        // so this is back on the 10s default every other caller uses. The lock
        // itself is unchanged and still mandatory: it is what re-asserts the
        // Square XOR against a concurrent Square connect.
        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user, $url, $menu): JsonResponse {
            if ($this->hasConflictingConnection($user, Platform::Square->value)) {
                return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
            }

            // Back on the 10s default for the same reason the outer lock is
            // (D3a): the projection this closure used to run is now a read.
            // The lock STAYS — dropping it would reopen PWL-5 for
            // saveSelection / setServiceVisibility / ConnectFetchJob /
            // ScheduledRefresh, all of which key on this 'fresha' platform
            // lock alone. See ManagesIntegrationConnection::withConnectionLock.
            return $this->withConnectionLock($user, function () use ($user, $url, $menu): JsonResponse {
                // Business Partna accounts book storewide — no team-member picker. Finalise
                // the selection here so connect() completes setup in one step; the dashboard
                // sees mode='storewide' and skips the picker. Capability-gated so the
                // account_type read stays inside AccountCapabilities.
                if (AccountCapabilities::for($user)->can_book_storewide) {
                    // Compose the EFFECTIVE list from content.* (deduped by
                    // serviceId; suppressions and departures honoured) into the
                    // public selection; the raw scrape lands at payload.raw
                    // (private — the public allowlist ships only url+selection),
                    // where its remaining job is the vendor's menu ORDER.
                    $projected = $this->projector->sync($user, $menu['services']);
                    $selection = [
                        'url' => $url,
                        'storeName' => $menu['storeName'],
                        'mode' => 'storewide',
                        'employee' => null,
                        'services' => $projected['services'],
                        'hiddenServiceIds' => $projected['hiddenServiceIds'],
                    ];
                    $this->writeConnection($user, [
                        'url' => $url,
                        'selection' => $selection,
                        'raw' => ['services' => $projected['raw']],
                    ]);

                    return $this->success([
                        'url' => $url,
                        'mode' => 'storewide',
                        'selection' => (new FreshaSelectionResource($selection, (string) $user->id))->resolve(),
                    ]);
                }

                // Individual: preserve any existing selection (re-connecting the same store
                // keeps the saved team member); the dashboard re-picks via saveSelection.
                // FreshaSelection::toArray() returns the stored inner blob verbatim, so a
                // canonical stored selection round-trips byte-identically; a pending row
                // (selection null) carries forward as null, exactly as before. The stored
                // raw scrape (revert source for detached projections) rides along too.
                $existingPayload = $this->readConnection($user) ?? [];
                $existing = SelectionPayload::fromArray($existingPayload);
                $carriedRaw = is_array($existingPayload['raw'] ?? null) ? $existingPayload['raw'] : null;
                $this->writeConnection($user, [
                    'url' => $url,
                    'selection' => $existing->selection?->toArray(),
                    ...($carriedRaw !== null ? ['raw' => $carriedRaw] : []),
                ]);

                return $this->success(['url' => $url, 'mode' => 'team', ...$menu]);
            });
        });
    }

    /**
     * CA-W6/CA-W7 deferred-connect write path, shared by both modes. Writes a
     * pending row carrying {url, selection (carried forward exactly like the
     * synchronous branch above — see §4.4 in the design doc for why a
     * storewide reconnect must NOT blank it), connectMode:$mode,
     * teamMenu:null, connectPendingAt}, then dispatches ConnectFetchJob.
     * Mirrors SkoolController::connectDeferred()'s lock discipline: $row is
     * captured by reference because withConnectionLock()'s (and, since U1,
     * withCrossPlatformLock()'s) return type is JsonResponse only;
     * deferredConnectResponse() (DefersBespokeConnect) dispatches
     * ConnectFetchJob AFTER both locks have released. This is now a
     * self-deadlock hazard from TWO directions, not one: the job blocks on
     * the same per-user platform lock this method takes, AND — the more
     * dangerous one — FreshaConnectFetch.php:152 itself acquires
     * bookingXorLock to run its storewide projection, so a dispatch from
     * inside the outer lock below would have that job block on a lock this
     * same PHP process already holds. Either way, under a sync queue
     * connection dispatch() runs handle() inline, so either self-deadlock is
     * real, not theoretical. NEVER move this dispatch inside either lock.
     *
     * U1: wrapped in an outer bookingXorLock (added below) that re-asserts
     * the Square conflict check — a per-platform lock alone cannot exclude a
     * concurrent Square connect. Order: bookingXor outer, platform inner,
     * same as the synchronous connect() path above.
     *
     * U1 review fix: stays on withCrossPlatformLock's 10s default (unlike
     * connect()'s synchronous storewide branch, which passes 30) — this
     * closure only reads/writes the pending row; it never calls
     * FreshaServiceProjector::sync(). That work happens later, for a
     * dispatch that runs strictly AFTER this lock releases (see above), inside
     * ConnectFetchJob → FreshaConnectFetch's own separate lock acquisition,
     * already TTL'd to 30 (FreshaConnectFetch.php:155, CA-W7).
     *
     * `teamMenu => null` is written EXPLICITLY on BOTH modes so a mode flip
     * (or a reconnect to a different salon) can't leave a stale team snapshot
     * merged forward and served as `ready`. `connectPendingAt` is stamped
     * fresh on every pending write — see FreshaConnectFetch's docblock for
     * what clears it and connectStatus() for why it's checked instead of an
     * absence-only ("ok without teamMenu") guard, which would wrongly fail
     * every storewide `ready` row (storewide never writes teamMenu at all).
     */
    private function connectDeferred(User $user, string $url, string $mode): JsonResponse
    {
        $row = null;

        $lockResponse = $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user, $url, $mode, &$row): JsonResponse {
            if ($this->hasConflictingConnection($user, Platform::Square->value)) {
                return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
            }

            return $this->withConnectionLock($user, function () use ($user, $url, $mode, &$row): JsonResponse {
                $existingPayload = $this->readConnection($user) ?? [];
                $existing = SelectionPayload::fromArray($existingPayload);
                $carriedRaw = is_array($existingPayload['raw'] ?? null) ? $existingPayload['raw'] : null;

                $row = $this->writeConnection($user, [
                    'url' => $url,
                    'selection' => $existing->selection?->toArray(),
                    'connectMode' => $mode,
                    'teamMenu' => null,
                    'connectPendingAt' => now()->toIso8601String(),
                    ...($carriedRaw !== null ? ['raw' => $carriedRaw] : []),
                ], pending: true);

                return $this->success([]);
            });
        });

        if ($row === null) {
            // $row stays null on every non-dispatch outcome: a 423 from
            // either lock (bookingXor or platform), or the re-asserted 409 —
            // all three must skip the dispatch below.
            return $lockResponse;
        }

        return $this->deferredConnectResponse(
            $row,
            ['url' => $url, 'mode' => $mode],
            '/api/platforms/fresha/connect/status',
        );
    }

    // GET /api/platforms/fresha/connect/status — poll target for the 202
    // above (CA-W6). Single-selection (no ?account=) — bespokeConnectStatus
    // reads the platform's one row via connectionFor(), same as /selection below.
    public function connectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $row = $this->connectStatusRow($user, null, perAccount: false);

        // The dashboard's manual refresh button (RefreshController::refresh())
        // can flip a stranded pending row to 'ok' without ever running THIS
        // connect's fetch: FreshaFetch (the refresh strategy) 304s a
        // selection-less/carried-forward row via
        // PlatformRefresher::recordNotModified(), which touches last_refresh_*
        // but never payload — so it can never clear connectPendingAt. (Not the
        // hourly cron — scopeDueForRefresh() already excludes every 'pending'
        // row from its own selection.) An absence-only guard ("ok without
        // teamMenu") would still miss a reconnect whose STALE teamMenu/
        // selection got merged forward from a prior connect (a real cron
        // fetch could then legitimately overwrite the row with genuinely-
        // present-but-wrong content); this timestamp marker catches both, AND
        // — because it is stamped identically for both connectMode values —
        // covers storewide's equivalent "ok with a carried-forward selection"
        // case for free, with no second guard. Cleared only by
        // FreshaConnectFetch's own success write (team: set to null;
        // storewide: key removed).
        if ($row !== null && $row->last_refresh_status === 'ok' && ($row->payload['connectPendingAt'] ?? null) !== null) {
            return $this->success(['status' => 'failed', 'error' => FetchUnavailableException::STALE_CONNECT_ERROR]);
        }

        // $shape branches on the STORED payload, not the caller's current
        // capability — the account type could flip between the 202 and the
        // poll, and the row records what was promised. connectMode is only
        // authoritative while pending: connectDeferred() stamps it on the
        // pending write, but this closure runs solely on 'ok' rows
        // (bespokeConnectStatus() only calls $shape there), and both
        // FreshaConnectFetch success branches now DROP connectMode (R3,
        // 2026-07-25) — past completion it served no purpose and was one
        // allowlist edit away from a public leak (see that class's docblock).
        // teamMenu's array-ness is therefore the REAL discriminator, not a
        // fallback arm: team mode's fetchTeam() guarantees a non-empty array
        // on every successful write (an empty menu throws before returning),
        // and storewide's fetchStorewide() never fills teamMenu and no longer
        // carries forward its always-null placeholder either. The
        // `$payload['connectMode'] ?? null` read below only still matches a
        // hand-seeded test row.
        //
        // Neither key is eternal for a second reason too: the hourly cron's
        // FreshaFetch returns a fresh three-key payload dropping both — moot
        // in practice, since FreshaFetch 304s any row with no `selection`
        // (team mode never sets one) and a completed connect is never polled
        // again anyway.
        return $this->bespokeConnectStatus($user, null, function (array $payload) use ($user): array {
            $isTeam = ($payload['connectMode'] ?? null) === 'team' || is_array($payload['teamMenu'] ?? null);

            return $isTeam
                ? ['url' => $payload['url'] ?? null, 'mode' => 'team', ...($payload['teamMenu'] ?? [])]
                : [
                    'url' => $payload['url'] ?? null,
                    'mode' => 'storewide',
                    'selection' => (new FreshaSelectionResource($payload['selection'] ?? [], (string) $user->id))->resolve(),
                ];
        });
    }

    // GET /api/platforms/fresha/team — team + services for the saved URL.
    // Read-through cached into payload.teamMenuCache: the dashboard's
    // finish-setup prompt can open once per session, and a live scrape per open
    // would hammer fresha.com for a roster that changes only when staff join or
    // leave. ?refresh=1 forces a re-scrape.
    //
    // The cache key is deliberately NOT 'teamMenu' — connectStatus() uses
    // is_array(payload.teamMenu) to discriminate team from storewide mode, so
    // writing that key here would make a completed storewide connection report
    // mode:'team' on its next poll.
    public function team(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $row = $this->connectionFor($user);
        $payload = $row->payload ?? [];
        $url = SelectionPayload::fromArray($payload)->url;
        if (! $url) {
            return $this->error('No Fresha URL connected yet. POST one to /connect first.', 404);
        }

        $cached = is_array($payload['teamMenuCache'] ?? null) ? $payload['teamMenuCache'] : null;
        if ($cached !== null && ! $request->boolean('refresh') && $this->teamCacheIsFresh($payload)) {
            return $this->success(['url' => $url, ...$cached]);
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        try {
            $menu = $this->budget->open($seconds, fn () => $this->scraper->fetchMenu($url));
        } catch (SafeUrlException|ConnectionException|HttpException) {
            // A stale roster beats a dead picker — the owner can still identify
            // themselves, and saveSelection() re-scrapes server-side anyway, so
            // a departed employee 404s correctly at submit time.
            if ($cached !== null) {
                return $this->success(['url' => $url, ...$cached]);
            }
            abort(502, 'Could not reach Fresha — please try again.');
        }

        // Phase 11 (Decision 1): which team member is probably "you", so the
        // picker opens pre-highlighted instead of blank. Null when the name is
        // blank or the match is ambiguous — the user then picks manually, which
        // is the documented fallback. Advisory only: saveSelection() re-resolves
        // the employeeId server-side against a fresh scrape, so a wrong
        // suggestion can never become a wrong saved selection on its own.
        $menu['suggestedEmployeeId'] = $this->staffMatcher->match($user, $menu['team']);

        // Quiet, merged write. saveQuietly() because IntegrationConnectionObserver
        // ::saved() purges the Cloudflare sitepage cache on ANY payload change —
        // and this roster is private dashboard data that appears nowhere in the
        // public payload, so a purge here would be pure waste. Merged so
        // url/selection/raw ride through untouched. Never writeConnection(pending:)
        // — this must not move last_refresh_status.
        //
        // connectionFor() only authorizes 'view' (no denyIfPendingDeletion —
        // see IntegrationConnectionPolicy), unlike every other connection-write
        // path in this controller which goes through upsertConnection()'s
        // 'update'/'create' abilities. A GET is supposed to stay side-effect-free
        // for a pending-deletion account (EnforcePendingDeletionReadOnly lets GETs
        // through on that assumption) — so skip the persist and just serve the
        // freshly-scraped result, matching denyIfPendingDeletion's convention
        // without routing this read through a write-gated ability.
        if ($row !== null && ! $user->isPendingDeletion()) {
            $row->payload = [...($row->payload ?? []), 'teamMenuCache' => $menu, 'teamMenuCachedAt' => now()->toIso8601String()];
            $row->saveQuietly();
        }

        return $this->success(['url' => $url, ...$menu]);
    }

    /** Whether payload.teamMenuCachedAt is inside the configured TTL. */
    private function teamCacheIsFresh(array $payload): bool
    {
        $at = $payload['teamMenuCachedAt'] ?? null;
        if (! is_string($at)) {
            return false;
        }

        $ttl = (int) config('partna.platforms.fresha.team_cache_seconds', 86400);

        return Carbon::parse($at)->addSeconds($ttl)->isFuture();
    }

    // GET /api/platforms/fresha/url — peek at what's saved without re-scraping.
    public function show(Request $request): JsonResponse
    {
        return $this->success(['url' => $this->freshaUrl($this->currentUser($request))]);
    }

    // POST /api/platforms/fresha/selection — save which team member is "you"
    // plus the current service menu. Re-scrapes the saved URL so the stored
    // blob is server-authoritative (not whatever the client happened to hold).
    public function saveSelection(SaveFreshaSelectionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $url = $this->freshaUrl($user);
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        // Per-employee services via the booking GraphQL; fall back to the whole
        // location menu if that call fails (hash/version rotated). One budget
        // spans both fetches; the "team member not found" 404 is preserved by
        // signalling it out of the closure rather than returning directly.
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        try {
            $resolved = $this->budget->open($seconds, function () use ($url, $validated) {
                $location = $this->scraper->fetchLocation($url);
                $employee = collect($this->scraper->extractTeam($location))->firstWhere('employeeId', $validated['employeeId']);
                if (! $employee) {
                    return ['employee' => null];
                }
                $slug = $this->scraper->slugFromUrl($url);
                $services = ($slug ? $this->scraper->fetchEmployeeServices($slug, $validated['employeeId']) : null)
                    ?? $this->scraper->extractServices($location);

                return ['location' => $location, 'employee' => $employee, 'services' => $services];
            });
        } catch (SafeUrlException|ConnectionException) {
            abort(502, 'Could not reach Fresha — please try again.');
        }

        if ($resolved['employee'] === null) {
            return $this->error('That team member was not found on the saved Fresha page.', 404);
        }
        $location = $resolved['location'];
        $employee = $resolved['employee'];
        $services = $resolved['services'];

        // Whole-branch review pt.3 fix: take bookingXorLock OUTER, the
        // per-platform lock INNER — the exact structure connect()/
        // connectDeferred() above use (and FreshaConnectFetch.php:155-184 for
        // the async path). The earlier pt.2 guard re-checked existence under
        // the 'fresha' platform key ALONE, but forget()/BookingController::
        // clearBooking() delete under bookingXorLock — a DIFFERENT key — so the
        // two never excluded each other: a forget()+Square-connect landing
        // between the re-check and the write still resurrected Fresha alongside
        // an active Square, the very at-most-one-booking-provider violation U1
        // exists to prevent. Holding bookingXorLock across the whole check →
        // sync() → write span closes that residual TOCTOU — forget() now blocks
        // on the same key and cannot interleave.
        //
        // Lock order is fixed (bookingXor outer, platform inner) — never the
        // reverse; see ManagesIntegrationConnection::withCrossPlatformLock's
        // lock-ordering docblock (reversing it is the one cycle the branch
        // confirmed does not exist). The ~20s scrape above stays OUTSIDE both
        // locks, same discipline as connect(). Both locks were on TTL 30s for
        // the projection this closure used to run inside them; slice 7 D3a made
        // that a content.* read, so both are back on the 10s default. Neither
        // lock is optional — the span they cover (existence re-check → compose
        // → write) is unchanged.
        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user, $url, $location, $employee, $services): JsonResponse {
            // Re-assert the Square XOR under the lock forget()/clearBooking()/
            // SquareController::connect() all serialise on — a Square that
            // connected during the unlocked scrape above must block this write,
            // mirroring FreshaConnectFetch.php:170 for the async path.
            if ($this->hasConflictingConnection($user, Platform::Square->value)) {
                return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
            }

            return $this->withConnectionLock($user, function () use ($user, $url, $location, $employee, $services): JsonResponse {
                // Existence re-check (kept from pt.2, still necessary but no
                // longer sufficient alone): a forget() landing during the
                // unlocked scrape soft-deletes this row before either lock is
                // taken. writeConnection() below is an updateOrCreate, so
                // without this it would resurrect the connection row. (It used
                // to resurrect the tombstoned deleted_origin='sync' service rows
                // too; sync() no longer writes those, but the connection-row
                // half is reason enough on its own.) Mirrored from
                // FreshaConnectFetch.php's identical re-check.
                if ($this->connectionFor($user) === null) {
                    return $this->error('No Fresha URL saved yet. Save one first.', 404);
                }

                // Preserve previously hidden services, dropping ids that no longer exist
                // in the refreshed menu so the hidden list never drifts stale. D3a: the
                // kept list IS the record now — it rides on the blob, and compose() only
                // prunes it further to ids still live in content.*.
                $serviceIds = array_map(static fn (array $s): string => (string) $s['serviceId'], $services);
                $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
                $hidden = array_values(array_filter(
                    $existing->selection?->hiddenServiceIds() ?? [],
                    static fn ($id): bool => in_array($id, $serviceIds, true),
                ));

                $projected = $this->projector->sync($user, $services, $hidden);
                $selection = [
                    'url' => $url,
                    'storeName' => $this->scraper->extractStoreName($location),
                    'mode' => 'employee',
                    'employee' => $employee,
                    'services' => $projected['services'],
                    'hiddenServiceIds' => $projected['hiddenServiceIds'],
                ];
                $this->writeConnection($user, [
                    'url' => $url,
                    'selection' => $selection,
                    'raw' => ['services' => $projected['raw']],
                ]);

                return $this->success((new FreshaSelectionResource($selection, (string) $user->id))->resolve());
            });
        });
    }

    // GET /api/platforms/fresha/employee-services?employeeId=X — the per-employee
    // menu for the dashboard preview (before saving). Same fallback as above.
    public function employeeServices(FreshaEmployeeServicesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $url = $this->freshaUrl($this->currentUser($request));
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        $slug = $this->scraper->slugFromUrl($url);
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        try {
            $services = $this->budget->open($seconds, fn () => ($slug ? $this->scraper->fetchEmployeeServices($slug, $validated['employeeId']) : null)
                ?? $this->scraper->extractServices($this->scraper->fetchLocation($url)));
        } catch (SafeUrlException|ConnectionException) {
            abort(502, 'Could not reach Fresha — please try again.');
        }

        return $this->success(['services' => $services]);
    }

    // GET /api/platforms/fresha/selection — read the saved selection (partna-pages
    // reads this; the dashboard reads it to restore its "saved" state on load).
    public function selection(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $payload = SelectionPayload::fromArray($this->readConnection($user) ?? []);

        return $this->success([
            'selection' => $payload->selection !== null
                ? (new FreshaSelectionResource($payload->selection->toArray(), (string) $user->id))->resolve()
                : null,
            // Pending (Google-seeded) connections have a url but no selection — the
            // dashboard uses it to show "Finish setup" and open the picker.
            'url' => $payload->url,
        ]);
    }

    // POST /api/platforms/fresha/selection/storewide — go back to booking the whole
    // venue after narrowing to one team member (ruling R11, overnight 2026-08-18:
    // business accounts default storewide but MAY narrow via saveSelection, and
    // must be able to widen again). Capability-gated: an individual account can
    // never publish a whole salon's menu. The public services list is composed
    // from content.* — SourceProvisioner sees selection_ref flip to 'storewide'
    // and the observer runs the source eagerly, so the storewide menu lands
    // seconds later without a scrape here.
    public function saveStorewide(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if (! AccountCapabilities::for($user)->can_book_storewide) {
            return $this->error('This account books one team member at a time.', 403);
        }
        $url = $this->freshaUrl($user);
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        return $this->withConnectionLock($user, function () use ($user, $url): JsonResponse {
            $stored = $this->readConnection($user) ?? [];
            $existing = SelectionPayload::fromArray($stored);
            $storeName = $existing->selection?->toArray()['storeName'] ?? null;
            // payload.raw.services is the vendor's menu ORDER (compose() reads
            // values from content.*); carry the stored list forward rather
            // than re-scraping — sync([]) would blank it for good (review).
            $rawServices = is_array($stored['raw']['services'] ?? null) ? $stored['raw']['services'] : [];
            $projected = $this->projector->sync($user, $rawServices, $existing->selection?->hiddenServiceIds() ?? []);
            $selection = [
                'url' => $url,
                'storeName' => is_string($storeName) ? $storeName : null,
                'mode' => 'storewide',
                'employee' => null,
                'services' => $projected['services'],
                'hiddenServiceIds' => $projected['hiddenServiceIds'],
            ];
            $this->writeConnection($user, [
                'url' => $url,
                'selection' => $selection,
                'raw' => ['services' => $projected['raw']],
            ]);

            // The storewide menu itself lands via the eager ingest run this
            // write triggers (selection_ref → 'storewide'); until it does the
            // composed list is the narrowed one, so the response says so.
            return $this->success([...(new FreshaSelectionResource($selection, (string) $user->id))->resolve(), 'pending' => true]);
        });
    }

    // POST /api/platforms/fresha/service-visibility — show/hide one service on the
    // public page. Toggles the service id in the saved selection's hiddenServiceIds
    // list; only ids present in the saved menu are accepted. Returns the updated
    // selection so the dashboard swaps state in place. (partna-pages filters the
    // services list by hiddenServiceIds at render time — the public payload is
    // shipped verbatim, so the hidden list is curation, not a privacy boundary.)
    public function setServiceVisibility(SetFreshaServiceVisibilityRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $payload = SelectionPayload::fromArray($this->readConnection($user) ?? []);
            $selection = $payload->selection;
            if ($selection === null) {
                return $this->error('No Fresha selection saved yet.', 404);
            }

            // Only toggle services that exist in the saved menu.
            $serviceIds = array_map(
                static fn ($s) => is_array($s) ? ($s['serviceId'] ?? null) : null,
                $selection->services(),
            );
            if (! in_array($validated['serviceId'], $serviceIds, true)) {
                return $this->error('That service is not part of the saved Fresha menu.', 404);
            }

            $hidden = array_values(array_filter(
                $selection->hiddenServiceIds(),
                static fn ($id): bool => is_string($id),
            ));

            if ($validated['hidden']) {
                $hidden = array_values(array_unique([...$hidden, $validated['serviceId']]));
            } else {
                $hidden = array_values(array_filter($hidden, static fn ($id): bool => $id !== $validated['serviceId']));
            }

            // Slice 7 D3a: the BLOB owns the hidden state. It used to live on
            // site.services.is_active with compose() re-deriving the list from
            // it; content.* has no is_active, so the list computed above IS the
            // record and compose() only prunes it to ids that are still live.
            // Legacy connections (no payload.raw yet — the projection marker)
            // fall through to the verbatim hidden-list write below, exactly as
            // before.
            $storedPayload = $this->readConnection($user) ?? [];
            $rawServices = $storedPayload['raw']['services'] ?? null;
            if (is_array($rawServices)) {
                $composed = $this->projector->compose($user, $rawServices, $hidden);
                $inner = [
                    ...$selection->toArray(),
                    'services' => $composed['services'],
                    'hiddenServiceIds' => $composed['hiddenServiceIds'],
                ];
                $this->writeConnection($user, [
                    'url' => $payload->url,
                    'selection' => $inner,
                    'raw' => ['services' => $rawServices],
                ]);

                return $this->success((new FreshaSelectionResource($inner, (string) $user->id))->resolve());
            }

            // Write back the inner blob VERBATIM with only hiddenServiceIds replaced —
            // FreshaSelection::toArray() returns the stored blob unchanged, so the
            // public (verbatim) selection payload never gains a canonical-null key.
            $inner = [...$selection->toArray(), 'hiddenServiceIds' => $hidden];
            $this->writeConnection($user, ['url' => $payload->url, 'selection' => $inner]);

            return $this->success((new FreshaSelectionResource($inner, (string) $user->id))->resolve());
        });
    }

    // DELETE /api/platforms/fresha — clear the saved URL and selection. The
    // synced projections soft-delete with deleted_origin='sync' so a later
    // reconnect restores them (curation intact); owner-suppressed rows
    // (deleted_origin='user') and detached (is_manual) rows keep their state —
    // a detached row is owner content and survives the disconnect live.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // The booking-XOR lock (not the per-platform withConnectionLock) —
        // this delete has exactly one owner: BookingController::clearBooking()
        // also deletes the fresha connection row as part of the single-slot
        // booking-family clear, under this same cross-platform key. Serializing
        // both on the per-platform 'fresha' key would leave clearBooking()
        // free to race this delete.
        //
        // Fix B's 30s TTL is BACK to the 10s default (services cutover): it was
        // sized for the per-row ServiceObserver storm the Service::delete()
        // loop below caused — user+site lookup, ~29-key Redis bust, two
        // visibility re-evaluations and a Site touch, PER ROW. One raw UPDATE
        // replaces the loop, so the cost that justified the wider bound is gone.
        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user) {
            $this->forgetConnection($user);

            // Content twin of the legacy 'sync' soft-delete (spec §3.3):
            // source_items.removed_at hides the synced items from every
            // management read, and ProjectionWriter CLEARS it when a
            // reconnect's next run re-lands them — restore-on-return, native.
            // An item carrying a manual override is the owner's detached
            // content (the old is_manual rule) and survives live; an
            // owner-DELETED item already carries items.removed_at, which
            // nothing here touches.
            DB::connection('pgsql')->table('content.source_items')
                ->whereIn('source_id', fn ($q) => $q->select('id')->from('content.sources')
                    ->where('user_id', $user->id)->where('kind', 'connection'))
                ->whereIn('item_id', fn ($q) => $q->select('id')->from('content.items')
                    ->where('user_id', $user->id)->where('kind', 'service')
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')
                        ->from('content.manual_overrides')
                        ->whereColumn('content.manual_overrides.item_id', 'content.items.id')))
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            app(UserCacheService::class)->invalidateServices((string) $user->id);

            return $this->success(['url' => null, 'selection' => null]);
        });
    }
}
