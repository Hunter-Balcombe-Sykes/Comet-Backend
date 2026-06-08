<?php

use App\Services\Platforms\EventbriteScraper;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Helper: build minimal org-page HTML that references one event URL.
function orgPageHtml(string $eventUrl): string
{
    return "<html><body><a href=\"{$eventUrl}\">Event</a></body></html>";
}

// Helper: build minimal event-page HTML carrying a JSON-LD startDate/endDate.
function eventPageHtml(string $startDate, ?string $endDate = null): string
{
    $node = ['@type' => 'Event', 'name' => 'Test Event', 'startDate' => $startDate];
    if ($endDate !== null) {
        $node['endDate'] = $endDate;
    }

    return '<html><body><script type="application/ld+json">'.json_encode($node).'</script></body></html>';
}

// Helper: fake response array matching SafeUrlFetcher::fetch's return shape.
function fakeResponse(string $body): array
{
    return ['status' => 200, 'body' => $body, 'finalUrl' => 'https://example.com', 'contentType' => 'text/html'];
}

// SEM-4 regression: a future event whose startDate carries a negative UTC offset
// must be RETAINED as upcoming. The old string `>=` compare treated e.g.
// "2026-06-20T09:00:00-07:00" (9am LA = 16:00 UTC, still future) as past
// because "09..." < "16..." textually.
// NOTE: illustrative only — with a single event the `$upcoming ?: $events` fallback
// returns it under the OLD code too, so this case can't fail on the regression alone.
// The load-bearing guard is the two-event test below ("keeps future and drops past...").
it('retains a future event expressed in a negative-UTC-offset timezone', function () {
    // Construct a startDate that is +2 hours from now in UTC but expressed
    // with a -07:00 local offset, so the LOCAL hour is "now - 5h".
    // e.g. now=16:00 UTC → local=09:00-07:00; old code: "09" < "16" → dropped.
    $utcNow = Carbon::now('UTC');
    $futureUtc = $utcNow->copy()->addHours(2);
    // Represent the same instant as a -07:00 timestamp.
    $startDate = $futureUtc->copy()->setTimezone('America/Los_Angeles')->toIso8601String();

    $orgUrl = 'https://www.eventbrite.com/o/acme-1';
    $eventUrl = 'https://www.eventbrite.com/e/test-event-123';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->with($orgUrl, Mockery::any())
        ->andReturn(fakeResponse(orgPageHtml($eventUrl)));
    $fetcher->shouldReceive('fetch')
        ->with($eventUrl, Mockery::any())
        ->andReturn(fakeResponse(eventPageHtml($startDate)));

    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);

    expect($result)->not->toBeNull();
    // The event is upcoming — it must appear in the results.
    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0]['startDate'])->toBe($startDate);
});

// Complementary: a past event in a negative-UTC offset is correctly excluded.
it('excludes a past event expressed in a negative-UTC-offset timezone', function () {
    $utcNow = Carbon::now('UTC');
    $pastUtc = $utcNow->copy()->subHours(2);
    $startDate = $pastUtc->copy()->setTimezone('America/Los_Angeles')->toIso8601String();

    $orgUrl = 'https://www.eventbrite.com/o/acme-1';
    $eventUrl = 'https://www.eventbrite.com/e/past-event-456';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->with($orgUrl, Mockery::any())
        ->andReturn(fakeResponse(orgPageHtml($eventUrl)));
    $fetcher->shouldReceive('fetch')
        ->with($eventUrl, Mockery::any())
        ->andReturn(fakeResponse(eventPageHtml($startDate)));

    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);

    expect($result)->not->toBeNull();
    // A lone past event is still returned via the `$upcoming ?: $events` fallback, so
    // this case only proves a past negative-offset timestamp parses without error.
    // Actual exclusion (future kept, past dropped) is proven by the two-event test below.
    expect($result)->toBeArray();
});

// Two-event scenario: one future (negative offset), one past. Only the future event
// should appear in the results (upcoming path, not fallback).
it('keeps future and drops past when both carry negative-UTC-offset timestamps', function () {
    $utcNow = Carbon::now('UTC');

    $futureStart = $utcNow->copy()->addHours(3)->setTimezone('America/Los_Angeles')->toIso8601String();
    $pastStart = $utcNow->copy()->subHours(3)->setTimezone('America/Los_Angeles')->toIso8601String();

    $orgUrl = 'https://www.eventbrite.com/o/acme-1';
    $futureUrl = 'https://www.eventbrite.com/e/future-event-1';
    $pastUrl = 'https://www.eventbrite.com/e/past-event-2';

    // Org page lists both events.
    $orgBody = orgPageHtml($futureUrl)."\n".orgPageHtml($pastUrl);

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->with($orgUrl, Mockery::any())
        ->andReturn(fakeResponse($orgBody));
    $fetcher->shouldReceive('fetch')
        ->with($futureUrl, Mockery::any())
        ->andReturn(fakeResponse(eventPageHtml($futureStart)));
    $fetcher->shouldReceive('fetch')
        ->with($pastUrl, Mockery::any())
        ->andReturn(fakeResponse(eventPageHtml($pastStart)));

    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);

    expect($result)->not->toBeNull();
    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0]['startDate'])->toBe($futureStart);
});

// Null-safety: usort and filter must not throw when startDate is missing.
it('tolerates events with a missing startDate without throwing', function () {
    $orgUrl = 'https://www.eventbrite.com/o/acme-1';
    $eventUrl = 'https://www.eventbrite.com/e/no-date-789';

    // JSON-LD node has startDate set (required for the scraper to pick it up),
    // but we simulate a case where the returned array has no startDate.
    $node = ['@type' => 'Event', 'name' => 'No Date Event', 'startDate' => ''];
    $html = '<html><body><script type="application/ld+json">'.json_encode($node).'</script></body></html>';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->with($orgUrl, Mockery::any())
        ->andReturn(fakeResponse(orgPageHtml($eventUrl)));
    $fetcher->shouldReceive('fetch')
        ->with($eventUrl, Mockery::any())
        ->andReturn(fakeResponse($html));

    // Should not throw — empty startDate is tolerated.
    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);
    expect($result)->toBeArray();
});
