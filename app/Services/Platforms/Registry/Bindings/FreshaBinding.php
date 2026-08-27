<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Detect\HostMatch;
use App\Services\Platforms\Strategies\Fetch\FreshaConnectFetch;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;

/**
 * PD-retirement P5 (2026-08-27): Fresha's full behavioural contract, moved
 * VERBATIM from the retired hand-written registration. Connect stays bespoke
 * (FreshaController — routes(Bespoke), no ConnectStrategy, no capability
 * gate here: fresha gates itself inline, unlike the reservations trio).
 *
 * CA-W6/CA-W7: the CONNECT path needs a different fetch — FreshaFetch is
 * refresh-only (throws on a pending row with no selection), so
 * connectFetch() overrides connectFetchStrategy()'s default. The projector
 * dependency is CA-W7's: the storewide branch runs
 * FreshaServiceProjector::sync() itself. app() rather than `new` so a
 * constructor gaining a dependency can't leave this call site silently
 * short an argument.
 *
 * The completeness predicate: an auto-harvested fresha row (Instagram bio /
 * Google Business) is {url, selection: null} — connected, but with no
 * service menu to render. FreshaFetch 304s it forever, so it can never
 * self-heal; only the owner picking a team member completes it. is_array
 * (not !== null) mirrors FreshaFetch's own guard exactly so the two
 * predicates cannot drift.
 */
final class FreshaBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Booking)
            ->resource(FreshaSelectionResource::class)
            ->payload(SelectionPayload::class)
            ->refreshable()
            // Scheduled service-menu refresh (prices/durations/new services) —
            // re-scrapes the saved selection; 304s when unchanged or unselected.
            ->fetch(fn () => new FreshaFetch(
                app(FreshaScraper::class),
                app(FreshaServiceProjector::class),
                app(FreshaAutoSelector::class),
            ))
            // T23b: the reviews pool honours this via PoolResolver::
            // reviewsSuppressedByOwner — same gate google-business's toggle
            // feeds. Defaults ON.
            ->displayToggles([
                ['key' => 'reviews', 'label' => 'Reviews', 'description' => 'Your Fresha rating and recent reviews.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.fresha', 2 * 86400))
            ->connectFetch(fn () => app(FreshaConnectFetch::class))
            // The message ConnectFetchJob stores when the deferred team-mode
            // menu fetch fails — verbatim from connect()'s own synchronous 502
            // equivalent. Deliberately NOT ->deferredConnect(): fresha's
            // connect is bespoke (DefersBespokeConnect), so that flag would
            // falsely claim a ConnectStrategy exists.
            ->connectFetchError("We couldn't read that Fresha page just then — please try again.")
            ->connectInput('url', ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'], [], true)
            ->complete(fn (IntegrationConnection $c): bool => is_array($c->payload['selection'] ?? null))
            // Smart-detect matcher (Plan 6) — mirrors the fresha connect regex's host.
            ->detect(new HostMatch('~(^|\.)fresha\.com$~'))
            ->routes(PlatformRouteShape::Bespoke);
    }
}
