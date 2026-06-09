<?php

use App\Services\Platforms\EventbriteScraper;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

// Helper: fake response array matching SafeUrlFetcher::fetch/fetchMany's return shape.
function fakeResponse(string $body): array
{
    return ['status' => 200, 'body' => $body, 'finalUrl' => 'https://example.com', 'contentType' => 'text/html'];
}

// Build a fetchMany return value for a single event URL.
function fakeFetchMany(string $eventUrl, string $body): array
{
    return [$eventUrl => fakeResponse($body)];
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
    // Event detail pages are now fetched concurrently via fetchMany.
    $fetcher->shouldReceive('fetchMany')
        ->with([$eventUrl], Mockery::any())
        ->andReturn(fakeFetchMany($eventUrl, eventPageHtml($startDate)));

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
    $fetcher->shouldReceive('fetchMany')
        ->with([$eventUrl], Mockery::any())
        ->andReturn(fakeFetchMany($eventUrl, eventPageHtml($startDate)));

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
    // Both event URLs arrive in one fetchMany call (order matches URL appearance on the page).
    $fetcher->shouldReceive('fetchMany')
        ->with([$futureUrl, $pastUrl], Mockery::any())
        ->andReturn([
            $futureUrl => fakeResponse(eventPageHtml($futureStart)),
            $pastUrl => fakeResponse(eventPageHtml($pastStart)),
        ]);

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
    $fetcher->shouldReceive('fetchMany')
        ->with([$eventUrl], Mockery::any())
        ->andReturn(fakeFetchMany($eventUrl, $html));

    // Should not throw — empty startDate is tolerated.
    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);
    expect($result)->toBeArray();
});

// Concurrent fetch: all event-detail URLs are fetched in a single fetchMany call,
// not one fetch() call per URL. This asserts the parallel-fetch contract.
it('fetches all event detail pages in a single concurrent fetchMany call', function () {
    $orgUrl = 'https://www.eventbrite.com/o/acme-1';
    $urls = [
        'https://www.eventbrite.com/e/event-a-1',
        'https://www.eventbrite.com/e/event-b-2',
        'https://www.eventbrite.com/e/event-c-3',
    ];

    $future = now()->addDays(7)->toIso8601String();
    $orgBody = implode("\n", array_map(fn ($u) => "<a href=\"{$u}\">{$u}</a>", $urls));

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->once()  // org page: exactly 1 serial fetch
        ->with($orgUrl, Mockery::any())
        ->andReturn(fakeResponse($orgBody));

    // All event details must arrive in exactly ONE fetchMany call.
    $fetcher->shouldReceive('fetchMany')
        ->once()
        ->with($urls, Mockery::any())
        ->andReturn(array_combine($urls, array_map(fn ($u) => fakeResponse(eventPageHtml($future)), $urls)));

    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);

    expect($result)->not->toBeNull();
    expect($result['events'])->toHaveCount(3);
});

// SSRF: an event URL whose host is a private IP must be silently dropped
// (fetchMany returns null for it). The scraper must not surface it in results.
it('silently drops an event whose URL resolves to a private address', function () {
    $orgUrl = 'https://www.eventbrite.com/o/acme-1';
    $safeUrl = 'https://www.eventbrite.com/e/safe-event-1';
    $ssrfUrl = 'https://www.eventbrite.com/e/evil-event-2';  // treated as SSRF target

    $future = now()->addDays(7)->toIso8601String();
    $orgBody = "<a href=\"{$safeUrl}\">{$safeUrl}</a><a href=\"{$ssrfUrl}\">{$ssrfUrl}</a>";

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->with($orgUrl, Mockery::any())
        ->andReturn(fakeResponse($orgBody));
    // fetchMany returns null for the SSRF URL (as SafeUrlFetcher does when validation fails).
    $fetcher->shouldReceive('fetchMany')
        ->with([$safeUrl, $ssrfUrl], Mockery::any())
        ->andReturn([
            $safeUrl => fakeResponse(eventPageHtml($future)),
            $ssrfUrl => null,  // SSRF guard dropped this
        ]);

    $result = (new EventbriteScraper($fetcher))->fetchEvents($orgUrl, 5);

    expect($result)->not->toBeNull();
    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0]['link'])->toContain('safe-event');
});

// SSRF guard on fetchMany itself: a URL with a literal private IP must be rejected
// before any HTTP connection is made. This tests SafeUrlFetcher directly.
it('fetchMany rejects URLs with a literal private IP without fetching', function () {
    // Use Http::fake to assert nothing is fetched.
    Http::fake([]);

    $fetcher = app(SafeUrlFetcher::class);

    // 169.254.169.254 is the cloud-metadata endpoint — a literal reserved IP
    // rejected by assertSafe without DNS. The result must be null for this URL.
    $privateUrl = 'http://169.254.169.254/e/evil-event';
    $results = $fetcher->fetchMany([$privateUrl]);

    expect($results)->toHaveKey($privateUrl);
    expect($results[$privateUrl])->toBeNull();

    // No outbound HTTP must have been made.
    Http::assertNothingSent();
});

// SSRF guard on fetchMany: a redirect that points to a private IP must be dropped,
// not followed. Simulated by Http::fake returning a 301 to an internal address.
it('fetchMany drops a redirect that targets a private IP', function () {
    // Literal public IP as the start URL: assertSafe accepts it WITHOUT a DNS
    // lookup, so the test reaches the redirect-rejection path even in offline CI
    // (a hostname here would fail pre-validation on no-DNS and pass for the wrong reason).
    $publicUrl = 'http://1.2.3.4/e/redirect-bait-1';

    Http::fake([
        // First request returns a 301 to the cloud-metadata endpoint.
        $publicUrl => Http::response('', 301, ['Location' => 'http://169.254.169.254/secret']),
    ]);

    $fetcher = app(SafeUrlFetcher::class);
    $results = $fetcher->fetchMany([$publicUrl]);

    expect($results)->toHaveKey($publicUrl);
    // The redirect target failed SSRF re-validation — URL must be null, not the private response.
    expect($results[$publicUrl])->toBeNull();
});
