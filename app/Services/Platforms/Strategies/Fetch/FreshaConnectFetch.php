<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Http\SafeUrlException;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Site\AdvisoryLockTimeoutException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

// CA-W6/CA-W7: completes a pending Fresha connect (either mode) by scraping
// the saved store URL's menu — the same synchronous scrape FreshaController::
// connect() used to run inline before the flag. Deliberately NOT FreshaFetch:
// that strategy is refresh-only (throws FetchNotModifiedException on a row
// with no selection — see its own docblock). Selected via
// PlatformDescriptor::connectFetchStrategy(), which defaults to
// fetchStrategy() for every other platform — this is the one override.
//
// MODE BRANCH: payload.connectMode records which flow the 202 promised the
// client. 'team' (CA-W6) snapshots the raw menu into payload.teamMenu for the
// poll to shape; 'storewide' (CA-W7) additionally runs the write-side work
// connect() used to do inline — FreshaServiceProjector::sync() plus the
// composed selection — because storewide's public payload genuinely depends
// on the fetch (unlike team, whose write never did). Any other value is a
// canary: identify()/connectDeferred() only ever stamp these two.
//
// Never persists a NEW key the pending write didn't already carry: the
// returned payload is built from the connection's OWN stored payload, not a
// fresh array, so any key neither branch below touches (raw, selection on the
// team side, etc.) rides through unchanged. The one deliberate exception is
// removal: both branches strip connectMode from what they persist on success
// (R3, 2026-07-25) — it is a pending-window marker only, and
// FreshaController::connectStatus()'s discriminator already falls back to
// teamMenu's array-ness once a row is 'ok' (see that method's own comment),
// so keeping connectMode forever was dead weight one allowlist edit away
// from a public leak. teamMenu itself stays for TEAM mode: it is the row's
// only stored copy of the scraped menu until saveSelection() replaces the
// whole payload, and it is where a future team() fix (dropped from W8,
// still open — team() currently live-rescrapes on every call) should read
// from instead. fetchStorewide() below also drops teamMenu, but only
// because that branch never fills it — it is always the null the pending
// write stamped, so dropping it loses no content.
final readonly class FreshaConnectFetch implements FetchStrategy
{
    public function __construct(
        private FreshaScraper $scraper,
        private FreshaServiceProjector $projector,
    ) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = SelectionPayload::fromArray($payload)->url;
        if (! $url) {
            // Unreachable in production — FreshaController::connectDeferred()
            // always stamps `url` before dispatching. A canary, not a real path.
            throw new FetchShapeException('fresha_no_url');
        }

        $mode = $payload['connectMode'] ?? 'team';
        if (! in_array($mode, ['team', 'storewide'], true)) {
            // Unreachable in production — connectDeferred() only ever stamps
            // one of these two values. A canary, not a silent fall-through.
            throw new FetchShapeException('fresha_mode');
        }

        // ConnectFetchJob opens ONE FetchBudget around this whole call (E-1);
        // nesting a second open() here would fail open (the inner finally
        // clears the outer deadline), so this strategy deliberately does not
        // open its own — it relies on the caller's, for either branch below.
        return $mode === 'team'
            ? $this->fetchTeam($url, $payload, (string) $connection->user_id)
            : $this->fetchStorewide($connection, $url, $payload);
    }

    /**
     * CA-W6: snapshots the raw menu into payload.teamMenu. If the user's name
     * matches a team member, auto-selects them (2026-07-25, Phase 11).
     */
    private function fetchTeam(string $url, array $payload, string $userId): array
    {
        try {
            $menu = $this->scraper->fetchMenu($url);
        } catch (SafeUrlException|ConnectionException) {
            throw new FetchUnavailableException('fresha_unreachable');
        } catch (HttpException) {
            throw new FetchUnavailableException('fresha_bad_page');
        }

        if ($menu['team'] === [] && $menu['services'] === []) {
            throw new FetchUnavailableException('fresha_empty_menu');
        }

        $next = $payload;
        unset($next['connectMode']);

        // Auto-select staff member by name match (Phase 11).
        $autoEmployee = $this->matchEmployee($userId, $menu['team']);
        if ($autoEmployee !== null) {
            $next['autoSelectedEmployeeId'] = $autoEmployee;
        }

        return [...$next, 'teamMenu' => $menu, 'connectPendingAt' => null];
    }

    /**
     * CA-W7: storewide's write DOES depend on the fetch — projector->sync()
     * and the composed `selection` both need the scraped menu — so this
     * branch does the work FreshaController::connect()'s synchronous
     * storewide body used to do inline (`:97-127` there, verbatim below).
     *
     * The scrape stays outside every lock (same discipline as team mode and
     * as ConnectFetchJob itself). The re-assert + projection run inside
     * CacheKeyGenerator::bookingXorLock — NOT the per-platform key
     * (ConnectFetchJob takes that one for its own write, sequentially after
     * this returns) — because forget() / BookingController::clearBooking()
     * take the SAME booking-XOR lock for their delete, and
     * FreshaServiceProjector::sync() explicitly resurrects any service row
     * tombstoned with deleted_origin!=='user'. Without this lock, a
     * disconnect landing between the scrape and the projection would have
     * its service teardown undone by this job.
     *
     * TTL 30s (not withConnectionLock's 10s): the closure holds this lock
     * across sync()'s DB transaction, which itself now bounds its own
     * pg_advisory_xact_lock (services:{user_id}) via AdvisoryLock (U2) rather
     * than waiting forever — sized to stay under ConnectFetchJob's 45s timeout
     * backstop. block(5) matches every other booking-XOR caller. A timeout on
     * either lock (this one or the inner services one) resolves the same way:
     * caught below and folded into the job's terminal path.
     */
    private function fetchStorewide(IntegrationConnection $connection, string $url, array $payload): array
    {
        $user = User::find($connection->user_id);
        if ($user === null) {
            // Unreachable in steady state (the FK guarantees a user row), but
            // the row could vanish between dispatch and this job running.
            throw new FetchShapeException('fresha_no_user');
        }

        // Every non-vendor failure below carries GENERIC_USER_MESSAGE: we never
        // reached Fresha, so ConnectFetchJob's fallback copy ("we couldn't read
        // that Fresha page") would describe a request that was never made. Only
        // the three genuine vendor misses (unreachable / bad page / empty menu)
        // leave $userMessage null and take the platform's own wording.
        if (! FeatureAvailability::for($user)->allows('integration.fresha')) {
            throw new FetchUnavailableException('fresha_disabled', FetchUnavailableException::GENERIC_USER_MESSAGE);
        }

        try {
            $menu = $this->scraper->fetchMenu($url);
        } catch (SafeUrlException|ConnectionException) {
            throw new FetchUnavailableException('fresha_unreachable');
        } catch (HttpException) {
            throw new FetchUnavailableException('fresha_bad_page');
        }

        if ($menu['services'] === []) {
            throw new FetchUnavailableException('fresha_empty_menu');
        }

        try {
            $projected = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 30)
                ->block(5, function () use ($connection, $user, $menu) {
                    // A concurrent forget()/clearBooking() may have soft-deleted
                    // this row while the scrape above was in flight — that
                    // teardown must win, not get resurrected by sync() below.
                    if (IntegrationConnection::find($connection->id) === null) {
                        throw new FetchUnavailableException('fresha_disconnected', FetchUnavailableException::GENERIC_USER_MESSAGE);
                    }

                    // Re-assert the XOR under the SAME lock forget()/clearBooking()
                    // hold — closes the window a Square connect landing mid-flight
                    // would otherwise leave (see this class's docblock). Reported
                    // as a canary: the pending row already 409s a concurrent Square
                    // connect, so reaching this branch means that pre-existing,
                    // out-of-scope interleaving race actually fired.
                    if ($user->integrationConnections()->where('platform', Platform::Square->value)->exists()) {
                        $violation = new FetchUnavailableException('fresha_xor_violated', FetchUnavailableException::GENERIC_USER_MESSAGE);
                        report($violation);

                        throw $violation;
                    }

                    // Write-time re-check, mirrored from ConnectFetchJob's own
                    // generic recheck: a staff disable landing exactly inside
                    // this lock window must still stop the projection.
                    if (! FeatureAvailability::for($user)->allows('integration.fresha')) {
                        throw new FetchUnavailableException('fresha_disabled', FetchUnavailableException::GENERIC_USER_MESSAGE);
                    }

                    return $this->projector->sync($user, $menu['services']);
                });
        } catch (LockTimeoutException) {
            throw new FetchUnavailableException('fresha_xor_lock', FetchUnavailableException::GENERIC_USER_MESSAGE);
        } catch (AdvisoryLockTimeoutException) {
            // U2: sync()'s own bounded pg_advisory_xact_lock (services:{user_id})
            // timed out inside the closure above — same non-vendor treatment as
            // the booking-XOR lock timeout caught just above, so ConnectFetchJob's
            // existing FetchUnavailableException catch marks the row terminal
            // instead of leaving it 'pending' forever.
            throw new FetchUnavailableException('fresha_services_lock', FetchUnavailableException::GENERIC_USER_MESSAGE);
        }

        $selection = [
            'url' => $url,
            'storeName' => $menu['storeName'],
            'mode' => 'storewide',
            'employee' => null,
            'services' => $projected['services'],
            'hiddenServiceIds' => $projected['hiddenServiceIds'],
        ];

        // connectMode and teamMenu drop here too (R3) — see the class
        // docblock. teamMenu is always the null the pending write stamped on
        // this branch (only fetchTeam() ever fills it), so dropping it loses
        // no content.
        $next = $payload;
        unset($next['connectPendingAt'], $next['connectMode'], $next['teamMenu']);

        return [...$next, 'url' => $url, 'selection' => $selection, 'raw' => ['services' => $projected['raw']]];
    }

    /**
     * Fuzzy-match the user's name against the Fresha team. Returns an
     * employeeId when exactly one match is found at medium confidence or
     * above, or null when the name is blank or the match is ambiguous.
     *
     * Confidence tiers:
     *   - Exact full-name match → auto-select
     *   - Both first AND last name tokens present → auto-select
     *   - Last-name token match → auto-select (only if exactly one)
     *   - First-name-only match → too ambiguous, skip
     */
    private function matchEmployee(string $userId, array $team): ?string
    {
        if ($team === []) {
            return null;
        }

        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        $fullName = trim(implode(' ', array_filter([
            $user->first_name,
            $user->last_name,
        ])));

        if ($fullName === '') {
            return null;
        }

        $nameLower = strtolower($fullName);
        $tokens = array_filter(explode(' ', $nameLower), fn ($t) => $t !== '');
        $firstName = $tokens[0] ?? '';
        $lastName = count($tokens) > 1 ? end($tokens) : '';

        $candidates = [];

        foreach ($team as $employee) {
            $empName = strtolower((string) ($employee['displayName'] ?? ''));
            if ($empName === '') {
                continue;
            }

            $empId = (string) ($employee['employeeId'] ?? '');
            if ($empId === '') {
                continue;
            }

            // Exact match — highest confidence.
            if ($empName === $nameLower) {
                $candidates[] = ['id' => $empId, 'score' => 3];

                continue;
            }

            // Both first and last name tokens present in the employee name.
            if ($firstName !== '' && $lastName !== '' && $lastName !== $firstName
                && str_contains($empName, $firstName)
                && str_contains($empName, $lastName)) {
                $candidates[] = ['id' => $empId, 'score' => 2];

                continue;
            }

            // Last name match (medium confidence — only if unique).
            if ($lastName !== '' && str_contains($empName, $lastName)) {
                $candidates[] = ['id' => $empId, 'score' => 1];

                continue;
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Take the highest-scoring candidates.
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $bestScore = $candidates[0]['score'];
        $best = array_filter($candidates, fn ($c) => $c['score'] === $bestScore);

        // Score 1 (last-name only): require exactly one match.
        if ($bestScore === 1 && count($best) !== 1) {
            return null;
        }

        // Multiple exact or high-confidence matches — ambiguous, skip.
        if (count($best) > 1) {
            return null;
        }

        return $best[0]['id'];
    }
}
