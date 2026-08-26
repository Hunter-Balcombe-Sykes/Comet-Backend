<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Detect\HostMatch;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;

/**
 * PD-retirement P4 (2026-08-27): Eventbrite's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. Every string is
 * a frozen contract — do not edit without the tests that pin it.
 *
 * CA-W5 — see AppleMusicBinding's identical note: connectFetchError is the
 * message ConnectFetchJob stores when the deferred scrape fails, verbatim
 * from addAccount()'s own synchronous 422; deliberately NOT
 * ->deferredConnect() (connect is bespoke via DefersBespokeConnect, no
 * ConnectStrategy for the flag to describe).
 *
 * No ->resource(): the hand-written descriptor carried none (its bespoke
 * controller shapes its own responses); the bare binding base keeps it null.
 *
 * The auto_sync_latest toggle is per-user "auto sync latest from each
 * organiser" — not a payload-suppression toggle (no DisplaySettingsFilter
 * entry); the events FETCH strategy reads it and 304s an account row when
 * it's off. The dashboard's single Tickets card PATCHes eventbrite and
 * humanitix together.
 */
final class EventbriteBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Events)
            ->payload(EventsAccountPayload::class)
            ->refreshable()
            // Event fetch strategy (Plan 6) — consumed by the registry-driven refresher.
            ->fetch(fn () => new EventbriteFetch(app(EventbriteScraper::class)))
            ->connectFetchError('Could not load that Eventbrite page.')
            ->connectInput('url', ['required', 'string', 'max:500'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Auto sync latest from each organiser', 'description' => 'Automatically refresh each connected organiser\'s upcoming events.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.eventbrite', 6 * 3600))
            // Smart-detect matcher (Plan 6): Eventbrite has regional TLDs.
            ->detect(new HostMatch('~(^|\.)eventbrite\.(com|com\.au|co\.uk|co\.nz|ca|de|fr|es|it|nl|pt|ie|at|ch|dk|fi|se|be|sg|hk|com\.br|com\.mx|com\.ar|com\.pe|cl)$~'))
            ->routes(PlatformRouteShape::Bespoke);
    }
}
