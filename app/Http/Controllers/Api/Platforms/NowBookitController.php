<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Services\Platforms\NowBookitService;
use Illuminate\Http\JsonResponse;

// NowBookit — connect by booking link; the keyless NowBookit booking widget
// embeds live availability + booking (no scraping, no auth). The venue's
// accountid + venueid are read straight from the booking URL's query string.
class NowBookitController extends SingleSelectionPlatformController
{
    public function __construct(private readonly NowBookitService $service) {}

    protected function platform(): string
    {
        return 'nowbookit';
    }

    protected function resourceClass(): string
    {
        return NowBookitConnectionResource::class;
    }

    // POST /api/platforms/nowbookit/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $url = $request->validated()['url'];

        if (! $this->service->isNowBookitUrl($url)) {
            return $this->error('Enter a NowBookit booking link (nowbookit.com/...).', 422);
        }

        $ids = $this->service->parseIds($url);
        if ($ids === null) {
            return $this->error('That link is missing the venue details. Use your NowBookit booking link that includes accountid and venueid.', 422);
        }

        return $this->connected($user, [
            'url' => $url,
            'accountId' => $ids['accountId'],
            'venueId' => $ids['venueId'],
            'name' => $this->service->nameFromUrl($url),
            'embedUrl' => $this->service->embedUrl($ids['accountId'], $ids['venueId']),
            'source' => 'manual',
        ]);
    }
}
