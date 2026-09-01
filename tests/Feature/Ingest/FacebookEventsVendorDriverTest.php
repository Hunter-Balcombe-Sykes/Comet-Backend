<?php

use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\FacebookEventsVendorDriver;
use App\Services\Cache\ScrapeCreatorsBudget;
use Illuminate\Support\Facades\Http;

// Item 11a (2026-09-01): the ('vendor', 'facebook_events') billed driver —
// spend-shape guarantees on the RECORDED payloads (The Tote / Corner Hotel):
// refusals before the first claim, release on transport-null, slot spent on
// billed husks, the details walk capped per run, and `complete` erring
// closed so partial walks can never tombstone live events downstream.

function fbEventsCtx(array $input = ['url' => 'https://www.facebook.com/thetotehotel']): BilledEffectContext
{
    return new BilledEffectContext('vendor', 'facebook_events', $input, 'run-1', 'source-1', 'user-1');
}

function fbEventsRecorded(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/fixtures/recorded/scrapecreators-{$name}.json")),
        true
    );
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.facebook_events', 100);
    config()->set('partna.limits.scrapecreators.facebook_events.details_per_run', 8);
});

it('supports exactly the facebook_events vendor pair', function () {
    $driver = app(FacebookEventsVendorDriver::class);

    expect($driver->supports('vendor', 'facebook_events'))->toBeTrue()
        ->and($driver->supports('vendor', 'facebook'))->toBeFalse()
        ->and($driver->supports('actor', 'facebook_events'))->toBeFalse();
});

it('refuses before claiming when the key is missing', function () {
    config()->set('services.scrapecreators.key', '');
    Http::fake();

    expect(fn () => app(FacebookEventsVendorDriver::class)->run(fbEventsCtx()))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('refuses before any call when the daily cap is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.facebook_events', 0);
    Http::fake();

    expect(fn () => app(FacebookEventsVendorDriver::class)->run(fbEventsCtx()))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
});

it('reads an input with no page url as noAnswer without spending anything', function () {
    Http::fake();

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx(['url' => 'thetotehotel']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertNothingSent();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('facebook_events'))->toBeTrue();
});

it('walks the recorded list into detail docs keyed by the stable event id', function () {
    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response(fbEventsRecorded('facebook-profile-events')),
        'api.scrapecreators.com/v1/facebook/event/details*' => Http::response(fbEventsRecorded('facebook-event-details')),
    ]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data['events'])->toHaveCount(8)
        // The recorded list page claims another page — never complete.
        ->and($result->data['complete'])->toBeFalse();

    $keys = array_column($result->data['events'], 'key');
    expect($keys)->toContain('1759413615194443')
        ->and($result->data['events'][0]['doc']['name'])->toBe('SHONEN KNIFE AT THE TOTE w/ MOLER')
        ->and($result->data['events'][0]['doc']['start_date'])->toBe('2026-10-14T19:30:00+11:00');

    // One list call + one details call per stub.
    Http::assertSentCount(9);
});

it('caps the details walk per run and reports the walk incomplete', function () {
    config()->set('partna.limits.scrapecreators.facebook_events.details_per_run', 2);
    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response(fbEventsRecorded('facebook-profile-events')),
        'api.scrapecreators.com/v1/facebook/event/details*' => Http::response(fbEventsRecorded('facebook-event-details')),
    ]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    expect($result->data['events'])->toHaveCount(2)
        ->and($result->data['complete'])->toBeFalse();
    Http::assertSentCount(3);
});

it('releases the slot on a list transport miss, keeps it spent on a billed husk', function () {
    // Transport miss: the list call never answers — both claims hand back.
    config()->set('partna.limits.scrapecreators.sources.facebook_events', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response(null, 500)]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and(app(ScrapeCreatorsBudget::class)->tryClaim('facebook_events'))->toBeTrue();
});

it('reads the recorded empty-events husk as noAnswer with the slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.facebook_events', 1);
    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response(fbEventsRecorded('facebook-profile-events-empty')),
    ]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    // A husk bills upstream (success:true) — never an empty calendar, and
    // never a refund.
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and(app(ScrapeCreatorsBudget::class)->tryClaim('facebook_events'))->toBeFalse();
});

it('keeps the events already landed when a mid-walk details call dies', function () {
    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response(fbEventsRecorded('facebook-profile-events')),
        'api.scrapecreators.com/v1/facebook/event/details*' => Http::sequence()
            ->push(fbEventsRecorded('facebook-event-details'))
            ->push(null, 500),
    ]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    // Paid doc kept, walk incomplete — a partial walk must never claim the
    // whole calendar.
    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data['events'])->toHaveCount(1)
        ->and($result->data['complete'])->toBeFalse();
    Http::assertSentCount(3);
});

it('skips a details husk with the slot spent and degrades completeness', function () {
    $list = fbEventsRecorded('facebook-profile-events');
    $list['events'] = array_slice($list['events'], 0, 2);
    $list['has_next_page'] = false;

    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response($list),
        'api.scrapecreators.com/v1/facebook/event/details*' => Http::sequence()
            ->push(['success' => true, 'credits_charged' => 1])
            ->push(fbEventsRecorded('facebook-event-details')),
    ]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    // The husk-missed stub might be a live event — completeness errs closed
    // so the stream can never tombstone it downstream.
    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data['events'])->toHaveCount(1)
        ->and($result->data['complete'])->toBeFalse();
    Http::assertSentCount(3);
});

it('answers complete only when the last page landed every stub', function () {
    $list = fbEventsRecorded('facebook-profile-events');
    $list['events'] = array_slice($list['events'], 0, 2);
    $list['has_next_page'] = false;

    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response($list),
        'api.scrapecreators.com/v1/facebook/event/details*' => Http::response(fbEventsRecorded('facebook-event-details')),
    ]);

    $result = app(FacebookEventsVendorDriver::class)->run(fbEventsCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data['events'])->toHaveCount(2)
        ->and($result->data['complete'])->toBeTrue();
});
