<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Detect\HostMatch;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;

/**
 * PD-retirement P4 (2026-08-27): Humanitix's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. Every string is
 * a frozen contract — do not edit without the tests that pin it.
 *
 * See EventbriteBinding for the CA-W5 no-deferredConnect note and the
 * no-resource/routes(Bespoke) rationale — identical here.
 */
final class HumanitixBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Events)
            ->payload(EventsAccountPayload::class)
            ->refreshable()
            // Event fetch strategy (Plan 6) — consumed by the registry-driven refresher.
            ->fetch(fn () => new HumanitixFetch(app(HumanitixScraper::class)))
            ->connectFetchError('Could not load that Humanitix page.')
            ->connectInput('url', ['required', 'string', 'max:500'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Auto sync latest from each organiser', 'description' => 'Automatically refresh each connected organiser\'s upcoming events.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.humanitix', 6 * 3600))
            // Smart-detect matcher (Plan 6): single-domain.
            ->detect(new HostMatch('~(^|\.)humanitix\.com$~'))
            ->routes(PlatformRouteShape::Bespoke);
    }
}
