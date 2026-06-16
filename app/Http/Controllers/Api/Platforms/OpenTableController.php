<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectOpenTableRequest;
use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Services\Platforms\OpenTableService;
use Illuminate\Http\JsonResponse;

// OpenTable — connect by restaurant link; the rid is read from the URL and the
// keyless reservation widget embeds live availability + booking (no scraping,
// no auth). OpenTable WAF-blocks our servers, so slug-only links (/r/<slug>)
// that don't carry the rid are rejected with a nudge to the profile link.
class OpenTableController extends SingleSelectionPlatformController
{
    public function __construct(private readonly OpenTableService $service) {}

    protected function platform(): string
    {
        return 'opentable';
    }

    protected function resourceClass(): string
    {
        return OpenTableConnectionResource::class;
    }

    // POST /api/platforms/opentable/connect
    public function connect(ConnectOpenTableRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $url = $request->validated()['url'];

        if (! $this->service->isOpenTableUrl($url)) {
            return $this->error('Enter an OpenTable restaurant link (opentable.com.au/...).', 422);
        }

        $rid = $this->service->parseRid($url);
        if ($rid === null) {
            return $this->error("That link doesn't include the restaurant id. Use the profile link with the number — opentable.com.au/restaurant/profile/123456.", 422);
        }

        return $this->connected($user, [
            'url' => $url,
            'rid' => $rid,
            'name' => $this->service->nameFromUrl($url),
            'embedUrl' => $this->service->embedUrl($rid, $this->service->hostOf($url)),
        ]);
    }
}
