<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectBooksyRequest;
use App\Http\Resources\Platforms\BooksyConnectionResource;
use App\Services\Platforms\BooksyScraper;
use Illuminate\Http\JsonResponse;

// Booksy — connect by listing URL. The listing's JSON-LD provides the
// business name, photo, live rating + review count, and address; the book
// action deep-links to the listing.
class BooksyController extends SingleSelectionPlatformController
{
    public function __construct(private readonly BooksyScraper $scraper) {}

    protected function platform(): string
    {
        return 'booksy';
    }

    protected function resourceClass(): string
    {
        return BooksyConnectionResource::class;
    }

    // POST /api/platforms/booksy/connect
    public function connect(ConnectBooksyRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter your Booksy listing URL (the page clients book you on).', 422);
        }

        $business = $this->scraper->fetchBusiness($url);
        if ($business === null) {
            return $this->error('Could not read that Booksy listing.', 404);
        }

        return $this->connected($user, ['url' => $url, ...$business]);
    }
}
