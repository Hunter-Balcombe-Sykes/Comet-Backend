<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\AddPlatformEventRequest;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Services\Platforms\EventbriteScraper;
use Illuminate\Http\JsonResponse;

// Eventbrite "Tickets" integration — organiser accounts (scraped, no auth) +
// individually-added standalone events. All flows live in
// EventsPlatformController; this subclass binds the Eventbrite scraper.
// Spec: ~/Developer/platform link capabilites/eventbrite.md
class EventbriteController extends EventsPlatformController
{
    public function __construct(private readonly EventbriteScraper $scraper) {}

    protected function platform(): string
    {
        return 'eventbrite';
    }

    protected function platformLabel(): string
    {
        return 'Eventbrite';
    }

    protected function normalizeAccountUrl(string $input): ?string
    {
        return $this->scraper->normalizeOrgUrl($input);
    }

    protected function fetchAccountEvents(string $url): ?array
    {
        return $this->scraper->fetchEvents($url);
    }

    protected function normalizeEventUrl(string $input): ?string
    {
        return $this->scraper->normalizeEventUrl($input);
    }

    protected function fetchSingleEvent(string $url): ?array
    {
        return $this->scraper->fetchSingleEvent($url);
    }

    protected function accountUrlError(): string
    {
        return 'Enter your Eventbrite organiser URL (eventbrite.com/o/...).';
    }

    protected function eventUrlError(): string
    {
        return 'Enter an Eventbrite event URL (eventbrite.com/e/...).';
    }

    // POST /api/platforms/eventbrite/connect — add an organiser account.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        return $this->addAccount($this->currentUser($request), $request->validated()['url']);
    }

    // POST /api/platforms/eventbrite/events — add one event by URL.
    public function addEvent(AddPlatformEventRequest $request): JsonResponse
    {
        return $this->addStandaloneEvent($this->currentUser($request), $request->validated()['url']);
    }
}
