<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\SafeUrlException;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// CA-W6: completes a pending Fresha TEAM-mode connect by scraping the saved
// store URL's menu — the same synchronous scrape FreshaController::connect()
// used to run inline before the flag. Deliberately NOT FreshaFetch: that
// strategy is refresh-only (throws FetchNotModifiedException on a row with no
// selection — see its own docblock) and calls FreshaServiceProjector::sync(),
// which individual-mode connect must never trigger (today's synchronous
// individual branch never touches the projector either; only saveSelection()
// does, once a team member is picked). Selected via
// PlatformDescriptor::connectFetchStrategy(), which defaults to
// fetchStrategy() for every other platform — this is the one override.
//
// MODE BRANCH (CA-W7 seam): payload.connectMode records which flow the 202
// promised the client. Only 'team' is implemented here — a 'storewide' row
// reaching this strategy is a canary (W6 never writes that value onto a row
// this strategy runs against) rather than a silent fall-through; W7 widens
// the guard below to add its own branch.
//
// Never persists anything W6 didn't ask for: the returned payload carries
// forward every existing key (url, selection, raw, …) and adds `teamMenu` —
// the private snapshot FreshaController::connectStatus() reads to build the
// poll's `ready` body (nothing else stores the fetched menu) — and explicitly
// clears `connectPendingAt` to null. That marker is stamped fresh on every
// pending write (FreshaController::connectDeferred()) and is how the poll
// tells a genuine ConnectFetchJob completion apart from the manual refresh
// button (RefreshController::refresh()) flipping a stranded pending row to
// 'ok' behind our back: FreshaFetch 304s a selection-less/carried-forward
// row via PlatformRefresher::recordNotModified(), which touches
// last_refresh_* but never payload, so it can never clear this key. (Not the
// hourly cron — scopeDueForRefresh() already excludes every 'pending' row.)
final readonly class FreshaConnectFetch implements FetchStrategy
{
    public function __construct(private FreshaScraper $scraper) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = SelectionPayload::fromArray($payload)->url;
        if (! $url) {
            // Unreachable in production — FreshaController::connectDeferred()
            // always stamps `url` before dispatching. A canary, not a real path.
            throw new FetchShapeException('fresha_no_url');
        }

        if (($payload['connectMode'] ?? 'team') !== 'team') {
            // CA-W7 seam: storewide never reaches this strategy in W6 (its
            // connect stays synchronous), so this is a canary until W7 adds
            // its own branch here — never a silent fall-through.
            throw new FetchShapeException('fresha_mode');
        }

        // ConnectFetchJob opens ONE FetchBudget around this whole call (E-1);
        // nesting a second open() here would fail open (the inner finally
        // clears the outer deadline), so this strategy deliberately does not
        // open its own — it relies on the caller's.
        try {
            $menu = $this->scraper->fetchMenu($url);
        } catch (SafeUrlException|ConnectionException) {
            throw new FetchUnavailableException('fresha_unreachable');
        } catch (HttpException) {
            // The scraper's own abort(502, …) for a non-200 response, a
            // missing __NEXT_DATA__ blob, or undecodable JSON.
            throw new FetchUnavailableException('fresha_bad_page');
        }

        if ($menu['team'] === [] && $menu['services'] === []) {
            throw new FetchUnavailableException('fresha_empty_menu');
        }

        return [...$payload, 'teamMenu' => $menu, 'connectPendingAt' => null];
    }
}
