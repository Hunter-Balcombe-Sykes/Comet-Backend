<?php

namespace App\Http\Controllers\Api\Platforms\Concerns;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\StrandedPendingWindow;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use Illuminate\Http\JsonResponse;

/**
 * Shared seam for the bespoke platform-connect controllers adopting the SAME
 * deferred-connect mechanism the eight registry platforms already use — the
 * flag, the pending write, ConnectFetchJob, and its failure-message
 * convention — without moving them onto GenericPlatformController's registry
 * router (design doc §2, route (c)). Currently used by Apple, Skool, Fresha,
 * and the Eventbrite/Humanitix pair (via EventsPlatformController). Instagram
 * uses bespokeConnectStatus() ALONE — its connect is driven by
 * InstagramConnectJob, not ConnectFetchJob, and it is not flag-gated (R7).
 * EventsController's own events/add facade deliberately does NOT use this
 * trait — see its own docblock for why.
 *
 * The using class MUST also `use ManagesIntegrationConnection` and extend
 * ApiController: every method below calls platform(), connectionFor(),
 * requestedAccountRow(), success() and error(), none of which this trait
 * declares. Mirrors ThrottlesPreAccountScraping's own using-class contract
 * (app/Jobs/Concerns/ThrottlesPreAccountScraping.php).
 *
 * Wires no platform by itself — each using controller gates it behind its
 * own shouldDeferConnect() check against config('partna.connect.deferred').
 * Landed inert at CA-W2; Apple, Skool, Fresha, Eventbrite and Humanitix have
 * since wired into it.
 *
 * @mixin ApiController
 * @mixin ManagesIntegrationConnection
 */
trait DefersBespokeConnect
{
    // The failure SENTENCE is also present as its own separate copy in
    // GenericPlatformController::connectStatus() — kept duplicated there
    // rather than hoisted out of the generic controller's file, since
    // deduplicating would mean editing the eight already-armed registry
    // platforms' code path for a cosmetic win (design doc §7 Risk R3). The
    // sentence lives on FetchUnavailableException, not here, so
    // ConnectFetchJob's own lock-timeout catch can reference the SAME
    // constant without a Job depending on a Controller trait for a string —
    // PHP has no way to reference a trait constant externally otherwise. The
    // stale-pending WINDOW itself is shared via StrandedPendingWindow, not
    // duplicated — see the staleness check below.

    private const UNKNOWN_CONNECT_ERROR = 'We could not load that account. Please try again.';

    /**
     * This platform's own async kill switch — config('partna.connect.deferred')
     * named it, or not. Strict `in_array` on purpose: a loose/prefix match
     * would let 'apple-music' being deferred silently activate 'apple-podcast'.
     * Takes the slug explicitly rather than reading platform() because Apple
     * serves two slugs from one controller instance via a mutable property —
     * the caller's own slug is unambiguous, the property might not be set yet.
     */
    protected function shouldDeferConnect(string $slug): bool
    {
        return in_array($slug, (array) config('partna.connect.deferred', []), true);
    }

    /**
     * Builds the 202 for a pending row the caller already wrote (and whose
     * lock the caller already released). Dispatches ConnectFetchJob — call
     * this AFTER withConnectionLock()'s closure has returned, never from
     * inside it: under QUEUE_CONNECTION=sync, dispatch() runs handle()
     * inline, and the job blocks on the SAME per-user platform lock, so
     * dispatching under the lock self-deadlocks (constraint 2; mirrors
     * GenericPlatformController::connectDeferred()'s identical note).
     *
     * $statusPath is a root-relative path with NO query string — this method
     * appends '?account=' itself when $perAccount is true, so a caller can
     * never hand in a URL that already carries one. Apple's bespoke route
     * groups (/api/platforms/apple/music/…, /api/platforms/apple/podcast/…)
     * mean the path can't be derived from the slug the way the generic
     * controller derives "/api/platforms/{slug}/connect/status".
     *
     * $perAccount is the single switch for both wire facts the frontend
     * contract requires together: the 'id' key and the '?account=' segment,
     * so no future edit can produce one without the other.
     *
     * Known limitation: hardcodes ConnectFetchJob. A future platform needing
     * a different job writes its own dispatch and does not use this helper.
     */
    protected function deferredConnectResponse(
        IntegrationConnection $row,
        array $partial,
        string $statusPath,
        bool $perAccount = false,
    ): JsonResponse {
        ConnectFetchJob::dispatch($row->id, $row->platform)->afterCommit();

        $statusUrl = url($statusPath);
        $body = ['status' => 'pending'];
        if ($perAccount) {
            $body['id'] = $row->resource_id;
            $statusUrl .= '?account='.$row->resource_id;
        }
        $body['statusUrl'] = $statusUrl;

        // Envelope wins over a colliding $partial key — deliberately the
        // OPPOSITE spread order from GenericPlatformController::connectDeferred()'s
        // [...$body, ...$selection]. An Eventbrite/Humanitix partial can
        // legitimately carry its own 'id' (EventsPayload::withIds); that must
        // never overwrite the row's own resource_id in the 202.
        return $this->success([...$partial, ...$body], 202);
    }

    /**
     * The poll action (GET …/connect/status), shared by all six. $shape is
     * the platform's own payload→wire translation (a callable rather than a
     * Resource class name because Apple picks its Resource by slug at runtime
     * and Fresha's shape isn't a plain Resource wrap).
     *
     * $perAccount also selects the row reader: true reads via
     * requestedAccountRow() (?account=, or the first account row); false
     * always reads the platform's own default resource row via
     * connectionFor() and IGNORES $accountId entirely — a single-selection
     * platform must never let a stray query param select a different row.
     *
     * $notFoundMessage defaults to the async-connect contract's sentence, which
     * all six deferred platforms take unchanged — do not vary it for them, six
     * Feature tests and the contract doc pin it. It is a parameter only because
     * Instagram's poll predates that contract with its own published 404
     * sentence, and aligning that string is a separate frontend-visible
     * decision from sharing this method's staleness check.
     */
    protected function bespokeConnectStatus(
        User $user,
        ?string $accountId,
        callable $shape,
        bool $perAccount = false,
        string $notFoundMessage = 'Account not found.',
    ): JsonResponse {
        $row = $this->connectStatusRow($user, $accountId, $perAccount);

        // 404, never 403: both readers are already scoped to the caller's own
        // connections, so another user's row is never visible to look up in
        // the first place — no existence leak, no separate policy check.
        if ($row === null) {
            return $this->error($notFoundMessage, 404);
        }

        if ($row->last_refresh_status === 'pending') {
            // Ported from GenericPlatformController::connectStatus(): a
            // worker that dies between dispatch and a terminal write leaves
            // the row 'pending' forever with nothing to flip it. Window and
            // its justification: StrandedPendingWindow. Synthetic — this does
            // NOT write the row, so a merely-slow (not dead) worker can still
            // land its real 'ok' write afterwards and the next poll reports
            // 'ready'.
            if ($row->updated_at->lt(now()->subMinutes(StrandedPendingWindow::MINUTES))) {
                return $this->success(['status' => 'failed', 'error' => FetchUnavailableException::STALE_CONNECT_ERROR]);
            }

            return $this->success(['status' => 'pending']);
        }

        if ($row->last_refresh_status === 'ok') {
            $body = ['status' => 'ready'];
            if ($perAccount) {
                $body['id'] = $row->resource_id;
            }
            $body['connection'] = $row->payload ? $shape($row->payload) : null;

            return $this->success($body);
        }

        // 'unavailable' / 'error' / a legacy null status. The '?:' fallback
        // deliberately diverges from GenericPlatformController::connectStatus()
        // (which can emit {"error":null}): the contract says `error` is always
        // a complete, displayable sentence, so a missing stored message falls
        // back to the shared unhandled-failure string instead of null.
        return $this->success([
            'status' => 'failed',
            'error' => $row->last_refresh_error ?: self::UNKNOWN_CONNECT_ERROR,
        ]);
    }
}
