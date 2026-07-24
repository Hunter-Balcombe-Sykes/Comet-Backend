<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\AddPlatformEventRequest;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\HumanitixScraper;
use Illuminate\Http\JsonResponse;

// Humanitix "Tickets" integration — host accounts (scraped, no auth) +
// individually-added standalone events. All flows live in
// EventsPlatformController; this subclass binds the Humanitix scraper.
class HumanitixController extends EventsPlatformController
{
    public function __construct(private readonly HumanitixScraper $scraper, FetchBudget $budget)
    {
        parent::__construct($budget);
    }

    protected function platform(): string
    {
        return 'humanitix';
    }

    protected function platformLabel(): string
    {
        return 'Humanitix';
    }

    protected function normalizeAccountUrl(string $input): ?string
    {
        return $this->scraper->resolveHostUrl($input);
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
        return 'Enter your Humanitix host URL (events.humanitix.com/host/...).';
    }

    protected function eventUrlError(): string
    {
        return 'Enter a Humanitix event URL (events.humanitix.com/...).';
    }

    // POST /api/platforms/humanitix/connect — add a host account.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        return $this->addAccount($this->currentUser($request), $request->validated()['url']);
    }

    // POST /api/platforms/humanitix/events — add one event by URL.
    public function addEvent(AddPlatformEventRequest $request): JsonResponse
    {
        return $this->addStandaloneEvent($this->currentUser($request), $request->validated()['url']);
    }
}
